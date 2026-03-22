# Research Platform (Nexus + Scout)

This project consists of two main services:
- **Nexus API**: A FastAPI-based research engine for academic search and deduplication.
- **Scout**: A Laravel-based frontend and management platform for research workflows.

## Prerequisites

- Docker and Docker Compose
- Gemini API Key (for Scout)

## Getting Started

### 1. Clone the repository

```bash
git clone <repository-url>
cd research-platform
```

### 2. Configure Environment Variables

Create a `.env` file in the root directory (or use the one in `scout/`):

```bash
# Database Password
DB_PASSWORD=your_secure_password

# Gemini API Key (Required for Scout)
GEMINI_API_KEY=your_gemini_api_key_here
```

#### How to get a Gemini API Key:
1. Go to [Google AI Studio](https://aistudio.google.com/).
2. Sign in with your Google account.
3. Click on **'Get API key'** in the sidebar.
4. Create a new API key in a new project or an existing one.
5. Copy the key and paste it into your `.env` file as `GEMINI_API_KEY`.

### 3. Build and Run with Docker Compose

```bash
docker-compose up -d --build
```

This will start:
- **Nexus API** on [http://localhost:8000](http://localhost:8000)
- **Scout Frontend** on [http://localhost:8001](http://localhost:8001)
- **Database (PostgreSQL)** on port 5432
- **Adminer** (DB Management) on [http://localhost:8080](http://localhost:8080)
- **pgAdmin** on [http://localhost:5050](http://localhost:5050)

### 4. Initialize Scout (First Run)

```bash
docker exec -it scout-laravel php artisan migrate --seed
```

## Project Structure

- `nexus-api/`: Python FastAPI backend.
- `scout/`: Laravel/React frontend.
- `docker-compose.yml`: Orchestration for all services.

