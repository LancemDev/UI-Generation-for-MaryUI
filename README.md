# Graph-Guided Prompt-Based UI Generation (SKYLARR)

## Overview
This project ties together a Laravel (Livewire + MaryUI + Tailwind) frontend with a Python FastAPI backend to generate and guide UI with scene graphs and a GNN, while providing an AI chat experience.

- Laravel app (in `skylarr/`):
  - Livewire chat with threaded message persistence and streaming deltas
  - Collapsible/resizable chat sidebar, themed navbar, global brand colors
  - Models/migrations for `chat_threads` and `chat_messages`
  - Service `AiGateway` streams from Python backend

- Python app (in `scripts/`):
  - FastAPI service exposing `POST /chat/stream` (OpenAI streaming)
  - OpenAI service with `.env` loading from repo or `scripts/.env`
  - GNN scaffolding and data assets (for context injection)

---

## Prerequisites
- Python 3.13 (venv recommended)
- Node 18+ (for Vite/Tailwind) and PHP 8.2+ for Laravel
- OpenAI API key

---

## Python backend (scripts/)

### Structure
- `scripts/main.py`: FastAPI app entrypoint
- `scripts/services/openai_service.py`: OpenAI client + streaming
- `scripts/services/gnn_service.py`: GNN data prep and example (optional demo)
- `scripts/data/maryui_gnn_data/*.pkl`: persisted graph/features (if present)

### Environment
Create a `.env` either at repo root or in `scripts/` with:

```
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
```

The service attempts to load the nearest `.env` and also falls back to `scripts/.env`.

### Install deps and run

```
cd scripts
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Option A (repo root):
# python -m uvicorn scripts.main:app --reload --host 127.0.0.1 --port 8000

# Option B (inside scripts/):
python -m uvicorn main:app --reload --host 127.0.0.1 --port 8000

# Option C (direct file run):
python scripts/main.py
```

The server exposes:
- `GET /` → health
- `POST /chat/stream` → streams text chunks as they arrive from OpenAI
- `POST /generate-code` → non-streaming example (optional)

---

## Laravel app (skylarr/)

### Key paths
- `skylarr/app/Livewire/ChatInterface.php` → chat component logic (threaded, streaming)
- `skylarr/resources/views/livewire/chat-interface.blade.php` → chat UI (bubbles, status chips)
- `skylarr/app/Services/AiGateway.php` → streams from Python FastAPI (`PY_BACKEND_URL`)
- `skylarr/app/Models/ChatThread.php`, `ChatMessage.php` → Eloquent models
- `skylarr/database/migrations/*chat_*` → DB schema
- `skylarr/resources/css/app.css` → Tailwind v4 config, brand colors
- `skylarr/resources/views/livewire/custom-components/navigation-bar.blade.php` → themed navbar
- `skylarr/resources/views/livewire/code-generator.blade.php` → layout with collapsible chat sidebar

### Env configuration
In `skylarr/.env` (or project `.env` used by Laravel), ensure:

```
APP_URL=http://127.0.0.1:8001
PY_BACKEND_URL=http://127.0.0.1:8000
```

Set `PY_BACKEND_URL` to match your FastAPI port. Laravel uses this in `AiGateway` to call `/chat/stream`.

### Database
Run migrations inside `skylarr/`:

```
php artisan migrate
```

### Frontend
Tailwind brand tokens are declared in `resources/css/app.css`:

```
--color-primary: 239 150 81;    # rgb(239,150,81)
--color-secondary: 63 125 88;   # rgb(63,125,88)
```

Use classes like: `bg-primary`, `text-secondary`, `border-secondary/25`, `hover:bg-secondary/10`.

### Run Laravel

```
cd skylarr
composer install
cp .env.example .env # configure DB and PY_BACKEND_URL
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8001
```

---

## How chat streaming works
1. User submits a message in the Livewire chat.
2. Laravel persists a `ChatMessage` (role=user), then creates a placeholder assistant message (status=streaming).
3. `AiGateway` posts the message history to `scripts` `POST /chat/stream` and reads streamed chunks.
4. Livewire appends deltas to the assistant bubble and updates status on completion/error.
5. History is kept per authenticated user via `ChatThread` ownership.

---

## GNN context (optional)
You can inject scene-graph/GNN context into the AI prompt:
- Add a context builder in `scripts/services/` that loads your pickles and computes relevant context for the current UI graph.
- Prepend a `system` message or augment the last `user` message before calling `stream_chat`.

---

## Troubleshooting
- If Python shows `OPENAI_API_KEY environment variable is not set`, ensure `.env` exists (repo root or `scripts/.env`) and restart the server.
- If `ModuleNotFoundError: scripts` or reload warnings occur, run using the `python -m uvicorn ...` forms above.
- Ensure Laravel `.env` has the correct `PY_BACKEND_URL`.
