# Use the official uv image which includes Python
FROM ghcr.io/astral-sh/uv:python3.12-bookworm-slim

WORKDIR /app

# Enable system-wide installation inside the container
ENV UV_SYSTEM_PYTHON=1
ENV UV_COMPILE_BYTECODE=1

# Install dependencies first for better layer caching
COPY pyproject.toml uv.lock ./
RUN uv pip install --no-cache -r pyproject.toml

# Copy the rest of the application
COPY . .

# Set environment variables
ENV PYTHONPATH=/app/src:/app
# Explicitly use the psycopg (v3) driver
ENV DATABASE_URL="postgresql+psycopg://postgres:postgres@db:5432/nexus"

# Expose port
EXPOSE 8000

# Run uvicorn directly
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
