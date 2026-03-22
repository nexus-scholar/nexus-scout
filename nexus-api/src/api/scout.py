import logging
from typing import List, Optional
from fastapi import APIRouter, Depends
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from sqlmodel import Session

from nexus.core.database import get_session
from nexus.core.models import Query as SearchQuery, Document
from nexus.core.config import ProviderConfig, DeduplicationConfig, DeduplicationStrategy as StrategyEnum
from nexus.providers import get_provider
from nexus.dedup.deduplicator import Deduplicator
from nexus.batch import run_batch_research

logger = logging.getLogger("nexus-api")

router = APIRouter(prefix="/api/v1/scout", tags=["scout"])

class PICO(BaseModel):
    population: str = ""
    intervention: str = ""
    comparison: str = ""
    outcome: str = ""

class LiteratureSearchRequest(BaseModel):
    pico: PICO
    providers: List[str] = ["openalex", "semantic_scholar", "arxiv"]  # Default providers
    limit: int = 10

class SearchRequest(BaseModel):
    query: str
    provider: str = "openalex"
    limit: int = 10
    offset: int = 0

def build_pico_query(pico: PICO) -> str:
    """
    Builds a scientifically structured Boolean query from PICO elements.
    Example: (population) AND (intervention) AND (comparison) AND (outcome)
    """
    parts = []
    if pico.population.strip():
        parts.append(f"({pico.population.strip()})")
    if pico.intervention.strip():
        parts.append(f"({pico.intervention.strip()})")
    if pico.comparison.strip():
        parts.append(f"({pico.comparison.strip()})")
    if pico.outcome.strip():
        parts.append(f"({pico.outcome.strip()})")
    
    if not parts:
        return "medical research"
    
    return " AND ".join(parts)

@router.post("/literature-search")
async def scout_literature_search(
    request: LiteratureSearchRequest,
    session: Session = Depends(get_session)
):
    """
    Synchronous literature search for Scout LexicalScoutJob.
    Combines results from multiple providers, performs deduplication, 
    and returns a limited set of unique results.
    """
    logger.info(f"Scout Literature Search triggered with PICO: {request.pico}")
    
    query_text = build_pico_query(request.pico)
    logger.info(f"Generated Boolean Query: {query_text}")

    # Fetch more results than the limit to allow for deduplication loss
    fetch_limit = request.limit * 3 
    search_query_obj = SearchQuery(
        text=query_text,
        max_results=fetch_limit
    )

    # 1. Execute searches across providers
    all_raw_documents: List[Document] = []

    # In-memory config for now
    provider_configs = {
        "openalex": ProviderConfig(name="openalex", rate_limit=1.0),
        "arxiv": ProviderConfig(name="arxiv", rate_limit=0.5),
        "semantic_scholar": ProviderConfig(name="semantic_scholar", rate_limit=1.0),
        "s2": ProviderConfig(name="semantic_scholar", rate_limit=1.0),
        "pubmed": ProviderConfig(name="pubmed", rate_limit=1.0),
        "crossref": ProviderConfig(name="crossref", rate_limit=1.0),
    }

    for prov_name in request.providers:
        try:
            if prov_name not in provider_configs:
                logger.warning(f"Provider {prov_name} not configured, skipping.")
                continue

            provider = get_provider(prov_name, provider_configs[prov_name])
            results_iterator = provider.search(search_query_obj)
            
            count = 0
            for doc in results_iterator:
                all_raw_documents.append(doc)
                count += 1
                if count >= fetch_limit:
                    break
            
            logger.info(f"Found {count} results from {prov_name}")
                    
        except Exception as e:
            logger.error(f"Error searching {prov_name}: {e}")

    # 2. Deduplication
    if not all_raw_documents:
        return {"status": "success", "data": []}

    dedup_config = DeduplicationConfig(strategy=StrategyEnum.CONSERVATIVE)
    deduplicator = Deduplicator(dedup_config)
    
    unique_docs = deduplicator.get_unique_documents(all_raw_documents)
    logger.info(f"Deduplication complete: {len(all_raw_documents)} -> {len(unique_docs)}")

    # 3. Apply limit AFTER deduplication
    final_results = unique_docs[:request.limit]

    return {
        "status": "success",
        "data": [
            {
                "title": doc.title,
                "abstract": doc.abstract or "",
                "doi": doc.external_ids.doi if doc.external_ids else None,
                "url": doc.url,
                "publication_year": doc.year,
                "provider": doc.provider,
                "authors": [a.full_name for a in doc.authors] if doc.authors else []
            }
            for doc in final_results
        ]
    }

@router.post("/search")
async def boolean_search(
    request: SearchRequest,
    session: Session = Depends(get_session)
):
    """
    Search endpoint using a boolean query and a specific provider.
    Supports limit and paging (offset).
    """
    logger.info(f"Boolean Search triggered for provider {request.provider} with query: {request.query}")
    
    # In-memory config for now
    provider_configs = {
        "openalex": ProviderConfig(name="openalex", rate_limit=1.0),
        "arxiv": ProviderConfig(name="arxiv", rate_limit=0.5),
        "semantic_scholar": ProviderConfig(name="semantic_scholar", rate_limit=1.0),
        "s2": ProviderConfig(name="semantic_scholar", rate_limit=1.0),
        "pubmed": ProviderConfig(name="pubmed", rate_limit=1.0),
        "crossref": ProviderConfig(name="crossref", rate_limit=1.0),
    }

    if request.provider not in provider_configs:
        return {"status": "error", "message": f"Provider {request.provider} not configured."}

    # Create Query object
    search_query_obj = SearchQuery(
        text=request.query,
        max_results=request.offset + request.limit,
        offset=request.offset
    )

    try:
        provider = get_provider(request.provider, provider_configs[request.provider])
        results_iterator = provider.search(search_query_obj)
        
        # Handle paging manually if provider doesn't support offset in its internal search loop
        # The search() method in OpenAlexProvider yields up to max_results.
        
        all_results = []
        count = 0
        for doc in results_iterator:
            if count >= request.offset:
                all_results.append({
                    "title": doc.title,
                    "abstract": doc.abstract or "",
                    "doi": doc.external_ids.doi if doc.external_ids else None,
                    "url": doc.url,
                    "publication_year": doc.year,
                    "provider": doc.provider,
                    "authors": [a.full_name for a in doc.authors] if doc.authors else []
                })
            
            count += 1
            if len(all_results) >= request.limit:
                break
                
        return {
            "status": "success",
            "provider": request.provider,
            "translated_query": provider.get_last_query(),
            "data": all_results,
            "count": len(all_results),
            "offset": request.offset
        }
                    
    except Exception as e:
        logger.error(f"Error searching {request.provider}: {e}")
        return {"status": "error", "message": str(e)}

@router.get("/batch-research")
async def batch_research():
    """
    Runs batch research based on config.yml and queries.yml.
    Streams progress logs as a text response.
    """
    return StreamingResponse(
        run_batch_research(),
        media_type="text/plain"
    )

