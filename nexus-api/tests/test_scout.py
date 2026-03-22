import os
import pytest
from fastapi.testclient import TestClient
from sqlmodel import Session, SQLModel

# Set test environment before imports
os.environ["IN_TEST"] = "true"

from main import app
from nexus.core.database import engine
from nexus.core.models import Document, ExternalIds

# --- Test Database Setup ---

@pytest.fixture(name="session", scope="module")
def session_fixture():
    # Ensure tables are created in the in-memory test database
    SQLModel.metadata.create_all(engine)
    with Session(engine) as session:
        yield session


@pytest.fixture(name="client")
def client_fixture(session: Session):
    client = TestClient(app)
    yield client


# --- Scout API Tests ---

def test_scout_literature_search_success(client: TestClient, monkeypatch: pytest.MonkeyPatch):
    """Test scout literature search with PICO, deduplication, and limit."""

    class FakeProvider:
        def search(self, query):
            # Simulate returning duplicates that the Deduplicator should handle
            docs = [
                Document(
                    title="Deduplicated Paper",
                    abstract="Abstract A",
                    year=2024,
                    provider="openalex",
                    external_ids=ExternalIds(doi="10.1000/dedup"),
                ),
                Document(
                    title="Deduplicated Paper", # Same title and DOI
                    abstract="Abstract A",
                    year=2024,
                    provider="arxiv",
                    external_ids=ExternalIds(doi="10.1000/dedup"),
                ),
                Document(
                    title="Unique Paper",
                    abstract="Abstract B",
                    year=2023,
                    provider="openalex",
                    external_ids=ExternalIds(doi="10.1000/unique"),
                ),
            ]
            yield from docs

    def fake_get_provider(_name, _config):
        return FakeProvider()

    # Mock get_provider in the scout module
    monkeypatch.setattr("api.scout.get_provider", fake_get_provider)

    payload = {
        "pico": {
            "population": "patients with diabetes",
            "intervention": "insulin",
            "outcome": "glucose levels"
        },
        "providers": ["openalex", "arxiv"],
        "limit": 1
    }

    response = client.post("/api/v1/scout/literature-search", json=payload)
    assert response.status_code == 200

    data = response.json()
    assert data["status"] == "success"
    # Even though we found 3 docs (2 unique), limit is 1
    assert len(data["data"]) == 1
    assert data["data"][0]["title"] in ["Deduplicated Paper", "Unique Paper"]


def test_boolean_search_success(client: TestClient, monkeypatch: pytest.MonkeyPatch):
    """Test the new boolean search endpoint with paging."""

    class FakeProvider:
        def search(self, query):
            # Return 5 papers
            for i in range(5):
                yield Document(
                    title=f"Paper {i}",
                    year=2020 + i,
                    provider="openalex",
                    provider_id=f"id-{i}"
                )
        def get_last_query(self):
            return "https://api.openalex.org/works?search=test"

    def fake_get_provider(_name, _config):
        return FakeProvider()

    monkeypatch.setattr("api.scout.get_provider", fake_get_provider)

    # Test limit=2, offset=1 (should get Paper 1 and Paper 2)
    payload = {
        "query": "test query",
        "provider": "openalex",
        "limit": 2,
        "offset": 1
    }

    response = client.post("/api/v1/scout/search", json=payload)
    assert response.status_code == 200
    
    data = response.json()
    assert data["status"] == "success"
    assert data["count"] == 2
    assert data["offset"] == 1
    assert data["data"][0]["title"] == "Paper 1"
    assert data["data"][1]["title"] == "Paper 2"
    assert "translated_query" in data


def test_boolean_search_invalid_provider(client: TestClient):
    """Test boolean search with an unconfigured provider."""
    payload = {
        "query": "test",
        "provider": "non-existent-provider"
    }
    response = client.post("/api/v1/scout/search", json=payload)
    assert response.status_code == 200
    assert response.json()["status"] == "error"
    assert "not configured" in response.json()["message"]


def test_batch_research_generator(session: Session, monkeypatch: pytest.MonkeyPatch, tmp_path):
    """Test the run_batch_research generator directly."""
    from nexus.batch import run_batch_research
    import yaml

    # 1. Create temporary config and queries
    test_config = {
        "mailto": "test@example.com",
        "providers": {
            "openalex": {"enabled": True, "rate_limit": 10.0}
        }
    }
    test_queries = {
        "project": "Test Project",
        "queries": [
            {"id": "Q1", "theme": "test", "query": "test query", "max_results": 5}
        ]
    }

    config_file = tmp_path / "config.yml"
    queries_file = tmp_path / "queries.yml"
    
    with open(config_file, "w") as f:
        yaml.dump(test_config, f)
    with open(queries_file, "w") as f:
        yaml.dump(test_queries, f)

    # 2. Mock provider
    class FakeProvider:
        def search(self, query):
            yield Document(title="Batch Result", provider="openalex", provider_id="b1")

    monkeypatch.setattr("nexus.batch.get_provider", lambda _n, _c: FakeProvider())

    # 3. Run generator
    async def run_test():
        logs = []
        async for log in run_batch_research(str(config_file), str(queries_file)):
            logs.append(log)
        return logs

    import asyncio
    logs = asyncio.run(run_test())

    # 4. Verify logs
    if not any("Project initialized" in l for l in logs):
        print("\nBatch Research Logs:")
        for l in logs:
            print(f"  {l.strip()}")

    assert any("Starting batch research" in l for l in logs)
    assert any("Project initialized" in l for l in logs)
    assert any("Processing query 1/1: Q1" in l for l in logs)
    assert any("Completed openalex: 1 docs found" in l for l in logs)
    assert any("Batch research COMPLETED" in l for l in logs)


def test_batch_research_endpoint(client: TestClient, monkeypatch: pytest.MonkeyPatch):
    """Test the /batch-research endpoint streaming output."""
    
    async def fake_run_batch_research():
        yield "Log line 1\n"
        yield "Log line 2\n"

    monkeypatch.setattr("api.scout.run_batch_research", fake_run_batch_research)

    response = client.get("/api/v1/scout/batch-research")
    assert response.status_code == 200
    assert response.headers["content-type"] == "text/plain; charset=utf-8"
    
    # Check streaming content
    lines = response.text.splitlines()
    assert "Log line 1" in lines[0]
    assert "Log line 2" in lines[1]
