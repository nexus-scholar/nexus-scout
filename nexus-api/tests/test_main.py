import os
import pytest
from fastapi.testclient import TestClient
from sqlmodel import Session, SQLModel

# Set test environment before imports
os.environ["IN_TEST"] = "true"

from main import app
from nexus.core.database import engine, get_session

@pytest.fixture(name="session", scope="module")
def session_fixture():
    # Ensure tables are created in the in-memory test database
    SQLModel.metadata.create_all(engine)
    with Session(engine) as session:
        yield session


@pytest.fixture(name="client")
def client_fixture(session: Session):
    # No override needed as engine is already in-memory
    client = TestClient(app)
    yield client


# --- API Tests ---

def test_start_research_success(client: TestClient):
    """Test starting a research protocol."""
    payload = {
        "theme": "Machine Learning in Agriculture",
        "queries": [
            "deep learning AND crop monitoring",
            "computer vision AND weed detection"
        ],
        "max_results": 10
    }
    
    response = client.post("/api/v1/research/start", json=payload)
    
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "processing"
    assert data["theme"] == payload["theme"]
    assert "project_id" in data
    assert "search_run_id" in data
    assert "run_id" in data


def test_list_projects(client: TestClient):
    """Test listing projects after creation."""
    # 1. Start a research protocol
    payload = {
        "theme": "Autonomous Vehicles",
        "queries": ["lidar AND path planning"],
        "max_results": 5
    }
    client.post("/api/v1/research/start", json=payload)
    
    # 2. Get list of projects
    response = client.get("/api/v1/projects")
    
    assert response.status_code == 200
    projects = response.json()
    assert len(projects) >= 1
    assert any(p["name"] == payload["theme"] for p in projects)


def test_background_task_completion(client: TestClient, session: Session):
    """Test that the background task completes and updates search run status."""
    from sqlmodel import select
    from nexus.core.database import SearchRun
    import time

    payload = {
        "theme": "Background Check",
        "queries": ["test query"],
        "max_results": 1
    }
    
    response = client.post("/api/v1/research/start", json=payload)
    data = response.json()
    from uuid import UUID
    search_run_id = UUID(data["search_run_id"])

    # Wait for the background task to complete (it has a 2s sleep)
    # Give it some extra time for session handling
    time.sleep(3.5)

    # Re-fetch from DB to check status
    statement = select(SearchRun).where(SearchRun.id == search_run_id)
    search_run = session.exec(statement).first()
    
    assert search_run is not None
    assert search_run.status == "completed"


def test_invalid_payload(client: TestClient):
    """Test starting research with invalid payload."""
    payload = {
        "theme": "Missing Queries",
        # "queries" field is missing
        "max_results": 10
    }
    
    response = client.post("/api/v1/research/start", json=payload)
    
    assert response.status_code == 422 # Validation Error
