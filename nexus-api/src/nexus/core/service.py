"""Service layer for Nexus Research Engine.

Provides higher-level abstractions for managing projects, searches, and documents.
"""

from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from uuid import UUID

from sqlmodel import Session, select

from nexus.core.database import (
    Author,
    Cluster,
    ClusterMemberLink,
    Document,
    DocumentAuthorLink,
    Project,
    QueryRecord,
    SearchRun,
    SearchResult,
    Screening,
)


class NexusService:
    """Service for managing research workflows and data."""

    def __init__(self, session: Session):
        self.session = session

    # --- Project Management ---

    def create_project(self, name: str, description: Optional[str] = None, config: Optional[Dict[str, Any]] = None) -> Project:
        """Create a new research project."""
        project = Project(name=name, description=description, config=config or {})
        self.session.add(project)
        self.session.commit()
        self.session.refresh(project)
        return project

    def get_project(self, project_id: UUID) -> Optional[Project]:
        """Fetch a project by ID."""
        return self.session.get(Project, project_id)

    # --- Search & Results ---

    def start_search_run(self, project_id: UUID, run_id: str, config: Optional[Dict[str, Any]] = None) -> SearchRun:
        """Initialize a new search run for a project."""
        search_run = SearchRun(
            project_id=project_id,
            run_id=run_id,
            config_snapshot=config or {},
            status="running"
        )
        self.session.add(search_run)
        self.session.commit()
        self.session.refresh(search_run)
        return search_run

    def record_query(self, project_id: UUID, query_id: str, text: str, category: Optional[str] = None) -> QueryRecord:
        """Save a query definition to the database."""
        # Check if query already exists for this project
        statement = select(QueryRecord).where(
            QueryRecord.project_id == project_id,
            QueryRecord.query_id == query_id
        )
        existing = self.session.exec(statement).first()
        if existing:
            return existing

        query = QueryRecord(
            project_id=project_id,
            query_id=query_id,
            text=text,
            category=category
        )
        self.session.add(query)
        self.session.commit()
        self.session.refresh(query)
        return query

    def add_document_result(
        self,
        search_run_id: UUID,
        query_id: UUID,
        doc_data: Dict[str, Any],
        provider: str,
        provider_doc_id: str,
        rank: Optional[int] = None
    ) -> Document:
        """Add a document result and link it to the search run/query."""
        
        # 1. Author Management: Get or create authors
        authors = []
        for author_info in doc_data.get("authors", []):
            author = self.get_or_create_author(
                family_name=author_info.get("family_name", "Unknown"),
                given_name=author_info.get("given_name"),
                orcid=author_info.get("orcid")
            )
            authors.append(author)

        # 2. Document Management: Check for existing document by DOI or Provider ID
        doi = doc_data.get("external_ids", {}).get("doi")
        doc = self.get_document_by_doi(doi) if doi else None
        
        if not doc:
            # Create new document
            doc = Document(
                title=doc_data["title"],
                year=doc_data.get("year"),
                abstract=doc_data.get("abstract"),
                venue=doc_data.get("venue"),
                url=doc_data.get("url"),
                language=doc_data.get("language", "en"),
                cited_by_count=doc_data.get("cited_by_count", 0),
                doi=doi,
                arxiv_id=doc_data.get("external_ids", {}).get("arxiv_id"),
                openalex_id=doc_data.get("external_ids", {}).get("openalex_id"),
                raw_data=doc_data.get("raw_data", {}),
                authors=authors
            )
            self.session.add(doc)
            self.session.commit()
            self.session.refresh(doc)

        # 3. Create SearchResult link
        result = SearchResult(
            search_run_id=search_run_id,
            query_id=query_id,
            document_id=doc.id,
            provider=provider,
            provider_doc_id=provider_doc_id,
            rank=rank
        )
        self.session.add(result)
        self.session.commit()
        
        return doc

    def get_or_create_author(self, family_name: str, given_name: Optional[str] = None, orcid: Optional[str] = None) -> Author:
        """Find an existing author by ORCID or create a new one."""
        if orcid:
            statement = select(Author).where(Author.orcid == orcid)
            author = self.session.exec(statement).first()
            if author:
                return author

        # Fuzzy match by name if ORCID is not available (optional improvement)
        author = Author(family_name=family_name, given_name=given_name, orcid=orcid)
        self.session.add(author)
        self.session.commit()
        self.session.refresh(author)
        return author

    def get_document_by_doi(self, doi: str) -> Optional[Document]:
        """Fetch a document by its DOI."""
        statement = select(Document).where(Document.doi == doi)
        return self.session.exec(statement).first()

    # --- Deduplication & Screening ---

    def create_cluster(self, search_run_id: UUID, representative_id: UUID, member_ids: List[UUID], strategy: str, confidence: float = 1.0) -> Cluster:
        """Create a new deduplication cluster."""
        cluster = Cluster(
            search_run_id=search_run_id,
            representative_id=representative_id,
            strategy=strategy,
            confidence=confidence
        )
        self.session.add(cluster)
        self.session.commit()
        self.session.refresh(cluster)

        # Link members
        for doc_id in member_ids:
            link = ClusterMemberLink(cluster_id=cluster.id, document_id=doc_id)
            self.session.add(link)
        
        self.session.commit()
        return cluster

    def submit_screening(self, document_id: UUID, decision: str, reason: Optional[str] = None, layers: Optional[Dict] = None) -> Screening:
        """Submit a screening decision for a document."""
        statement = select(Screening).where(Screening.document_id == document_id)
        screening = self.session.exec(statement).first()

        if screening:
            screening.decision = decision
            screening.reason = reason
            screening.layers = layers or {}
            screening.updated_at = datetime.now(timezone.utc)
        else:
            screening = Screening(
                document_id=document_id,
                decision=decision,
                reason=reason,
                layers=layers or {}
            )
            self.session.add(screening)

        self.session.commit()
        self.session.refresh(screening)
        return screening
