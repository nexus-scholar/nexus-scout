"""Database schema definitions for Nexus Research Engine using SQLModel.

This module provides the database representation of project metadata,
search runs, query history, academic documents, authors, and screening results.
"""

from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from uuid import UUID, uuid4

from sqlalchemy import Column, JSON
from sqlmodel import Field, Relationship, SQLModel, create_engine


# --- Link Tables for Many-to-Many Relationships ---

class DocumentAuthorLink(SQLModel, table=True):
    """Link table between Documents and Authors."""
    document_id: UUID = Field(foreign_key="document.id", primary_key=True)
    author_id: UUID = Field(foreign_key="author.id", primary_key=True)
    author_order: int = Field(default=0)


class ClusterMemberLink(SQLModel, table=True):
    """Link table between Clusters and their constituent Documents."""
    cluster_id: UUID = Field(foreign_key="cluster.id", primary_key=True)
    document_id: UUID = Field(foreign_key="document.id", primary_key=True)


# --- Core Metadata & Workflow Tables ---

class Project(SQLModel, table=True):
    """Top-level container for a research effort."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    name: str = Field(index=True)
    description: Optional[str] = None
    config: Dict[str, Any] = Field(default_factory=dict, sa_column=Column(JSON))
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

    # Relationships
    search_runs: List["SearchRun"] = Relationship(back_populates="project")
    queries: List["QueryRecord"] = Relationship(back_populates="project")


class SearchRun(SQLModel, table=True):
    """Tracks a specific execution of a search across providers."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    project_id: UUID = Field(foreign_key="project.id")
    run_id: str = Field(index=True)  # Human-readable ID from CLI (e.g., '20260321_120000')
    status: str = Field(default="pending")  # pending, running, completed, failed
    config_snapshot: Dict[str, Any] = Field(default_factory=dict, sa_column=Column(JSON))
    started_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    completed_at: Optional[datetime] = None

    # Relationships
    project: Project = Relationship(back_populates="search_runs")
    search_results: List["SearchResult"] = Relationship(back_populates="search_run")
    clusters: List["Cluster"] = Relationship(back_populates="search_run")


class QueryRecord(SQLModel, table=True):
    """Database record of a specific search query used in a project."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    project_id: UUID = Field(foreign_key="project.id")
    query_id: str = Field(index=True)  # e.g., 'Q01'
    text: str  # The boolean query string
    category: Optional[str] = Field(default=None, index=True)
    metadata_json: Dict[str, Any] = Field(default_factory=dict, sa_column=Column(JSON))

    # Relationships
    project: Project = Relationship(back_populates="queries")
    search_results: List["SearchResult"] = Relationship(back_populates="query")


# --- Academic Content Tables ---

class Author(SQLModel, table=True):
    """Unique author information."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    family_name: str = Field(index=True)
    given_name: Optional[str] = None
    orcid: Optional[str] = Field(default=None, unique=True, index=True)

    # Relationships
    documents: List["Document"] = Relationship(
        back_populates="authors", link_model=DocumentAuthorLink
    )


class Document(SQLModel, table=True):
    """The unified document representation across all providers."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    title: str = Field(index=True)
    year: Optional[int] = Field(default=None, index=True)
    abstract: Optional[str] = None
    venue: Optional[str] = None
    url: Optional[str] = None
    language: Optional[str] = Field(default="en", index=True)
    cited_by_count: Optional[int] = Field(default=0)
    
    # Flattened External Identifiers
    doi: Optional[str] = Field(default=None, index=True, unique=True)
    arxiv_id: Optional[str] = Field(default=None, index=True)
    pubmed_id: Optional[str] = Field(default=None, index=True)
    openalex_id: Optional[str] = Field(default=None, index=True)
    s2_id: Optional[str] = Field(default=None, index=True)

    # For audit and debugging
    raw_data: Dict[str, Any] = Field(default_factory=dict, sa_column=Column(JSON))
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

    # Relationships
    authors: List[Author] = Relationship(
        back_populates="documents", link_model=DocumentAuthorLink
    )
    search_provenance: List["SearchResult"] = Relationship(back_populates="document")
    screening: Optional["Screening"] = Relationship(back_populates="document")
    clusters: List["Cluster"] = Relationship(
        back_populates="members", link_model=ClusterMemberLink
    )


# --- Search Provenance & Result Tracking ---

class SearchResult(SQLModel, table=True):
    """The link between a search run, a query, and a document from a specific provider."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    search_run_id: UUID = Field(foreign_key="searchrun.id")
    query_id: UUID = Field(foreign_key="queryrecord.id")
    document_id: UUID = Field(foreign_key="document.id")
    
    provider: str = Field(index=True)  # e.g., 'arxiv', 'openalex'
    provider_doc_id: str  # Original ID from provider
    rank: Optional[int] = None
    retrieved_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

    # Relationships
    search_run: SearchRun = Relationship(back_populates="search_results")
    query: QueryRecord = Relationship(back_populates="search_results")
    document: Document = Relationship(back_populates="search_provenance")


# --- Deduplication & Screening Tables ---

class Cluster(SQLModel, table=True):
    """A cluster of documents identified as duplicates."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    search_run_id: UUID = Field(foreign_key="searchrun.id")
    representative_id: UUID = Field(foreign_key="document.id")
    
    strategy: str  # conservative, semantic, etc.
    confidence: float = Field(default=1.0)
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

    # Relationships
    search_run: SearchRun = Relationship(back_populates="clusters")
    representative: Document = Relationship()
    members: List[Document] = Relationship(
        back_populates="clusters", link_model=ClusterMemberLink
    )


class Screening(SQLModel, table=True):
    """Decisions and metadata from the document screening process."""
    id: UUID = Field(default_factory=uuid4, primary_key=True)
    document_id: UUID = Field(foreign_key="document.id", unique=True)
    
    decision: str = Field(default="pending", index=True)  # pending, include, exclude, maybe
    reason: Optional[str] = None
    confidence: Optional[int] = None
    layers: Dict[str, Any] = Field(default_factory=dict, sa_column=Column(JSON))
    updated_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

    # Relationships
    document: Document = Relationship(back_populates="screening")


# --- Database Helpers ---

import os

# Get database URL from environment or default to local SQLite
DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:///./nexus.db")

# Automatically use in-memory database if in test environment
if os.getenv("IN_TEST") == "true":
    DATABASE_URL = "sqlite:///file:test_nexus?mode=memory&cache=shared"

# Automatically upgrade plain 'postgresql://' to use 'psycopg' (v3) driver
if DATABASE_URL.startswith("postgresql://"):
    DATABASE_URL = DATABASE_URL.replace("postgresql://", "postgresql+psycopg://", 1)

# In production, this would be a PostgreSQL URL
engine = create_engine(DATABASE_URL, connect_args={"check_same_thread": False} if "sqlite" in DATABASE_URL else {})


def create_db_and_tables():
    """Initialize the database and create all tables."""
    SQLModel.metadata.create_all(engine)


from sqlmodel import Session


def get_session():
    """Dependency for obtaining a database session."""
    with Session(engine) as session:
        yield session
