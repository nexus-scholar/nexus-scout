import time
import datetime
import logging
import sys
import os
from contextlib import asynccontextmanager
from typing import List
from uuid import UUID

from fastapi import BackgroundTasks, FastAPI, Depends
from pydantic import BaseModel
from sqlmodel import Session

# Add src to path so we can import nexus and api
sys.path.append(os.path.join(os.getcwd(), "src"))

from nexus.core.database import create_db_and_tables, get_session, engine, SearchRun
from nexus.core.service import NexusService
from nexus.core.models import Query as SearchQuery
from nexus.core.config import ProviderConfig
from nexus.providers import get_provider

# Import the new scout router
from api.scout import router as scout_router

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("nexus-api")

@asynccontextmanager
async def lifespan(app: FastAPI):
    """FastAPI lifespan manager for database initialization."""
    create_db_and_tables()
    yield

app = FastAPI(title="Nexus Research Engine", lifespan=lifespan)
app.include_router(scout_router)

# --- Data Models ---
class ResearchProtocol(BaseModel):
    theme: str
    queries: List[str]
    max_results: int = 10  # Low default for testing


# --- Background Tasks ---

def run_heavy_research_task(protocol: ResearchProtocol, project_id: UUID, search_run_id: UUID):
    """
    Background worker that executes REAL searches and saves results to DB.
    """
    with Session(engine) as session:
        service = NexusService(session)
        logger.info(f"Starting REAL research for Project: {project_id}, Run: {search_run_id}")

        # 1. Define providers to use (Using ones that don't need keys by default)
        # In production, these should come from your config.yml or Project.config
        providers_to_use = ["openalex", "arxiv"]
        provider_configs = {
            "openalex": ProviderConfig(name="openalex", rate_limit=1.0),
            "arxiv": ProviderConfig(name="arxiv", rate_limit=0.5),
        }

        # 2. Execute searches for each query across each provider
        for i, query_text in enumerate(protocol.queries):
            # Record/Get Query record
            query_record = service.record_query(
                project_id=project_id,
                query_id=f"Q{i+1:02d}",
                text=query_text,
                category=protocol.theme
            )

            # Create the SearchQuery object for the providers
            search_query_obj = SearchQuery(
                text=query_text,
                max_results=protocol.max_results
            )

            for prov_name in providers_to_use:
                try:
                    logger.info(f"Searching {prov_name} for: {query_text}")
                    provider = get_provider(prov_name, provider_configs[prov_name])
                    
                    # Execute search
                    results_iterator = provider.search(search_query_obj)
                    
                    count = 0
                    for doc in results_iterator:
                        # doc is a nexus.core.models.Document Pydantic object
                        # Convert it to a dictionary for the service
                        doc_dict = doc.model_dump()
                        
                        # Save to Database
                        service.add_document_result(
                            search_run_id=search_run_id,
                            query_id=query_record.id,
                            doc_data=doc_dict,
                            provider=prov_name,
                            provider_doc_id=doc.provider_id,
                            rank=count + 1
                        )
                        count += 1
                        if count >= protocol.max_results:
                            break
                            
                    logger.info(f"Found {count} results from {prov_name}")
                    
                except Exception as e:
                    logger.error(f"Error searching {prov_name}: {e}")

        # 3. Mark search run as completed
        run = session.get(SearchRun, search_run_id)
        if run:
            run.status = "completed"
            run.completed_at = datetime.datetime.now(datetime.timezone.utc)
            session.add(run)
            session.commit()
            
        logger.info(f"Finished REAL research for Theme: {protocol.theme}")


# --- Endpoints ---

@app.post("/api/v1/research/start")
async def start_research(
    protocol: ResearchProtocol, 
    background_tasks: BackgroundTasks,
    session: Session = Depends(get_session)
):
    service = NexusService(session)
    
    project = service.create_project(
        name=protocol.theme,
        description=f"Automatic research for: {protocol.theme}",
        config={"max_results": protocol.max_results}
    )
    
    run_id = f"RUN_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}"
    search_run = service.start_search_run(
        project_id=project.id,
        run_id=run_id,
        config={"queries_count": len(protocol.queries)}
    )
    
    background_tasks.add_task(
        run_heavy_research_task, 
        protocol, 
        project.id, 
        search_run.id
    )
    
    return {
        "status": "processing",
        "message": "Nexus engine has accepted the protocol and started REAL search.",
        "project_id": project.id,
        "search_run_id": search_run.id,
        "run_id": run_id,
        "theme": protocol.theme
    }

@app.get("/api/v1/projects")
async def list_projects(session: Session = Depends(get_session)):
    from sqlmodel import select
    from nexus.core.database import Project
    return session.exec(select(Project)).all()

@app.get("/api/v1/search-results/{search_run_id}")
async def get_search_results(search_run_id: UUID, session: Session = Depends(get_session)):
    """Fetch results for a specific search run."""
    from sqlmodel import select
    from nexus.core.database import SearchResult, Document
    
    # Simple join to get document details
    statement = select(SearchResult, Document).where(
        SearchResult.search_run_id == search_run_id
    ).join(Document)
    
    results = session.exec(statement).all()
    
    return [
        {
            "id": res.SearchResult.id,
            "provider": res.SearchResult.provider,
            "rank": res.SearchResult.rank,
            "title": res.Document.title,
            "year": res.Document.year,
            "doi": res.Document.doi,
            "url": res.Document.url
        }
        for res in results
    ]

# The router is now included in app.include_router(scout_router)
