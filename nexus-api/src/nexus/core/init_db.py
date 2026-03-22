"""Database initialization script.

Creates all tables defined in the SQLModel schema.
"""

import sys
from pathlib import Path

# Add src to path if running directly
sys.path.append(str(Path(__file__).parent.parent.parent))

from nexus.core.database import create_db_and_tables


def main():
    print("Initializing Nexus database...")
    create_db_and_tables()
    print("Database initialized successfully at ./nexus.db")


if __name__ == "__main__":
    main()
