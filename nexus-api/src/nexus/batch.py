import asyncio
import logging
import yaml
from pathlib import Path
from typing import AsyncGenerator, Dict, List, Any
from datetime import datetime, timezone

from nexus.core.config import load_config, SLRConfig
from nexus.core.database import get_session
from nexus.core.models import Query as SearchQuery
from nexus.core.service import NexusService
from nexus.providers import get_provider

logger = logging.getLogger("nexus-batch")

async def run_batch_research(
    config_path: str = "config.yml",
    queries_path: str = "queries.yml"
) -> AsyncGenerator[str, None]:
    """
    Async generator that runs batch research based on config and queries files.
    Yields log messages as it progresses.
    """
    yield f"[{datetime.now().isoformat()}] Starting batch research...\n"

    # 1. Load Configuration
    try:
        config: SLRConfig = load_config(Path(config_path))
        yield f"[{datetime.now().isoformat()}] Configuration loaded from {config_path}\n"
    except Exception as e:
        yield f"[{datetime.now().isoformat()}] ERROR loading config: {e}\n"
        return

    # 2. Load Queries
    try:
        with open(queries_path, "r", encoding="utf-8") as f:
            queries_data = yaml.safe_load(f)
        
        queries_list = queries_data.get("queries", [])
        project_name = queries_data.get("project", "default_project")
        yield f"[{datetime.now().isoformat()}] Loaded {len(queries_list)} queries for project '{project_name}' from {queries_path}\n"
    except Exception as e:
        yield f"[{datetime.now().isoformat()}] ERROR loading queries: {e}\n"
        return

    # 3. Initialize Service and Project
    # We use a context manager for the session to ensure it's closed
    from nexus.core.database import Session, engine
    with Session(engine) as session:
        service = NexusService(session)
        
        # Get or create project
        project = service.create_project(name=project_name, description=f"Batch research started at {datetime.now()}")
        yield f"[{datetime.now().isoformat()}] Project initialized: {project.id}\n"

        # Start a Search Run
        run_id = datetime.now().strftime("%Y%m%d_%H%M%S")
        search_run = service.start_search_run(project.id, run_id, config.model_dump(mode="json"))
        yield f"[{datetime.now().isoformat()}] Search run started: {run_id} ({search_run.id})\n"

        # 4. Initialize Providers
        enabled_provider_names = config.providers.get_enabled_providers()
        providers = {}
        for name in enabled_provider_names:
            prov_config = config.providers.get_provider(name)
            if prov_config:
                providers[name] = get_provider(name, prov_config)
        
        yield f"[{datetime.now().isoformat()}] Enabled providers: {', '.join(providers.keys())}\n"

        # 5. Execute Queries
        total_queries = len(queries_list)
        for i, q_data in enumerate(queries_list):
            q_id = q_data.get("id", f"Q{i}")
            q_text = q_data.get("query", "").strip()
            q_theme = q_data.get("theme", "general")
            
            if not q_text:
                yield f"[{datetime.now().isoformat()}] Skipping empty query {q_id}\n"
                continue

            yield f"[{datetime.now().isoformat()}] Processing query {i+1}/{total_queries}: {q_id} ({q_theme})\n"
            
            # Record query in DB
            query_record = service.record_query(project.id, q_id, q_text, category=q_theme)

            # Prepare SearchQuery object
            search_query_obj = SearchQuery(
                text=q_text,
                year_min=config.year_min,
                year_max=config.year_max,
                language=config.language,
                max_results=q_data.get("max_results", 20) # Use individual limit if exists
            )

            # Run search across providers
            for prov_name, provider in providers.items():
                yield f"[{datetime.now().isoformat()}]   Searching {prov_name}...\n"
                try:
                    count = 0
                    # We wrap the synchronous iterator in a way that doesn't block too much
                    # or just run it as is since this is a background-style task
                    for doc in provider.search(search_query_obj):
                        # Add to database
                        service.add_document_result(
                            search_run_id=search_run.id,
                            query_id=query_record.id,
                            doc_data=doc.model_dump(),
                            provider=prov_name,
                            provider_doc_id=doc.provider_id,
                            rank=count + 1
                        )
                        count += 1
                        if count % 10 == 0:
                            yield f"[{datetime.now().isoformat()}]     Found {count} docs from {prov_name}...\n"
                    
                    yield f"[{datetime.now().isoformat()}]   Completed {prov_name}: {count} docs found.\n"
                    
                except Exception as e:
                    yield f"[{datetime.now().isoformat()}]   ERROR in {prov_name}: {e}\n"
                    logger.error(f"Error in batch search for {prov_name}: {e}", exc_info=True)
                
                # Small sleep to be polite between providers/queries
                await asyncio.sleep(0.1)

        # Update search run status
        search_run.status = "completed"
        search_run.completed_at = datetime.now(timezone.utc)
        session.add(search_run)
        session.commit()

    yield f"[{datetime.now().isoformat()}] Batch research COMPLETED.\n"
