# Graph-Guided Prompt-Based UI Generation (SKYLARR)

## Overview
SKYLARR is an AI-powered UI generation tool that combines Laravel Livewire with MaryUI components, powered by Graph Neural Networks (GNN) and scene graphs for intelligent component generation. It provides real-time code generation with live preview capabilities through Docker containerization.

### Key Features
- **AI-Powered Code Generation**: Generate Livewire components using OpenAI with GNN context
- **Live Preview System**: Real-time preview of generated components in isolated Docker containers
- **Multi-Project Support**: Each user can have multiple projects with separate preview environments
- **Streaming Chat Interface**: Real-time AI chat with code generation triggers
- **Social Authentication**: Google and GitHub OAuth integration
- **Two-Factor Authentication**: Enhanced security with TOTP support
- **Project Management**: Create, manage, and switch between multiple projects

### Architecture
- **Laravel Frontend** (`skylarr/`): Livewire components, MaryUI styling, user management
- **Python Backend** (`scripts/`): FastAPI service with OpenAI integration and GNN context
- **Docker Preview System**: Isolated containers for live component preview
- **Database**: User projects, chat threads, and component metadata

---

## Prerequisites
- **Docker**: Required for preview containers
- **Python 3.13**: For the AI backend service
- **Node 18+**: For Vite/Tailwind frontend build
- **PHP 8.2+**: For Laravel application
- **OpenAI API Key**: For AI code generation
- **Composer**: PHP dependency manager
- **Git**: For version control

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
- `GET /` → health check
- `GET /health` → health status
- `POST /chat/stream` → streams text chunks as they arrive from OpenAI
- `POST /generate/code` → generates Livewire component code with GNN context
- `GET /gnn/summary` → returns GNN graph summary

---

## Laravel app (skylarr/)

### Key Components
- **Chat System**:
  - `app/Livewire/ChatInterface.php` → chat component logic with code generation triggers
  - `resources/views/livewire/chat-interface.blade.php` → chat UI with message bubbles
  - `app/Models/ChatThread.php`, `ChatMessage.php` → Eloquent models for chat persistence

- **Preview System**:
  - `app/Services/DockerPreviewService.php` → Docker container management
  - `app/Http/Controllers/PreviewController.php` → Preview API endpoints
  - `app/Livewire/CodeGenerationEngine.php` → Code generation and preview integration
  - `resources/views/livewire/code-generation-engine.blade.php` → Split-screen code editor and preview

- **Project Management**:
  - `app/Models/Project.php` → Project model with container tracking
  - `app/Http/Controllers/ProjectController.php` → Project CRUD operations
  - `database/migrations/*projects*` → Project database schema

- **Authentication & Security**:
  - `app/Http/Controllers/Auth/SocialAuthController.php` → OAuth integration
  - `app/Livewire/Settings.php` → User settings with 2FA support
  - `resources/views/livewire/settings.blade.php` → Settings UI with tabs

- **AI Integration**:
  - `app/Services/AiGateway.php` → Communication with Python backend
  - `resources/css/app.css` → Tailwind configuration with brand colors
  - `resources/views/livewire/custom-components/navigation-bar.blade.php` → Themed navigation

### Environment Configuration
In `skylarr/.env`, configure:

```env
# Application
APP_URL=http://127.0.0.1:8001
APP_NAME="SKYLARR"

# Database
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

# AI Backend
PY_BACKEND_URL=http://127.0.0.1:8000

# OAuth (Google)
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://127.0.0.1:8001/auth/google/callback

# OAuth (GitHub)
GITHUB_CLIENT_ID=your_github_client_id
GITHUB_CLIENT_SECRET=your_github_client_secret
GITHUB_REDIRECT_URI=http://127.0.0.1:8001/auth/github/callback

# Mail (for 2FA)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
```

### Database Setup
Run migrations to create all necessary tables:

```bash
cd skylarr
php artisan migrate
```

This creates:
- `users` table (with 2FA and OAuth fields)
- `projects` table (for project management)
- `chat_threads` table (for chat persistence)
- `chat_messages` table (for message history)

### Docker Preview System Setup

1. **Build the preview Docker image**:
```bash
cd skylarr
chmod +x scripts/build-docker-preview.sh
./scripts/build-docker-preview.sh
```

2. **Verify Docker is running**:
```bash
docker --version
docker ps
```

3. **Test the preview image**:
```bash
docker run -d -p 8001:80 --name test-preview skylarr-preview:latest
curl http://localhost:8001/health
docker stop test-preview && docker rm test-preview
```

### Frontend Styling
Tailwind brand tokens are configured in `resources/css/app.css`:

```css
--color-primary: 239 150 81;    /* rgb(239,150,81) */
--color-secondary: 63 125 88;  /* rgb(63,125,88) */
```

Use classes like: `bg-primary`, `text-secondary`, `border-secondary/25`, `hover:bg-secondary/10`.

### Run Laravel Application

```bash
cd skylarr
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan serve --host=127.0.0.1 --port=8001
```

---

## How the System Works

### Chat and Code Generation Flow
1. **User Input**: User types a message in the chat interface
2. **Message Persistence**: Laravel saves the user message to `chat_messages` table
3. **AI Processing**: `AiGateway` sends message history to Python backend `/chat/stream`
4. **Streaming Response**: AI response streams back in real-time chunks
5. **Code Detection**: System detects code generation requests in AI responses
6. **Code Generation**: Triggers `/generate/code` endpoint with GNN context
7. **Preview Creation**: Docker container created/injected with generated code
8. **Live Preview**: User sees real-time preview in iframe

### Docker Preview System
1. **Container Creation**: Each project gets its own Docker container
2. **Code Injection**: Generated Livewire components injected into container
3. **Route Registration**: Dynamic routes created for component access
4. **Live Updates**: Real-time preview updates as code changes
5. **Resource Management**: Automatic cleanup of inactive containers

### Multi-Project Architecture
- **User Isolation**: Each user can have multiple projects
- **Container Per Project**: Each project runs in its own Docker container
- **Resource Limits**: Maximum 5 containers per user
- **Automatic Cleanup**: Containers cleaned up after 24 hours of inactivity

### GNN Context Integration
The system uses Graph Neural Networks to provide intelligent context:
- **Component Relationships**: Understands MaryUI component dependencies
- **Scene Graph Analysis**: Analyzes UI structure and relationships
- **Context Injection**: Enhances AI prompts with relevant component information
- **Smart Suggestions**: Provides better code generation based on component patterns

## API Endpoints

### Laravel API Routes
- `GET /api/projects` → List user projects
- `POST /api/projects` → Create new project
- `GET /api/projects/{id}` → Get project details
- `PUT /api/projects/{id}` → Update project
- `DELETE /api/projects/{id}` → Delete project
- `POST /api/preview/create` → Create preview for project
- `GET /api/preview/{project}/status` → Get preview status
- `PUT /api/preview/update` → Update preview with new code
- `DELETE /api/preview/{project}/stop` → Stop preview container

### Python Backend Endpoints
- `GET /` → Health check
- `GET /health` → Service status
- `POST /chat/stream` → Stream AI chat responses
- `POST /generate/code` → Generate Livewire component code
- `GET /gnn/summary` → Get GNN graph summary

## Security Features

### Authentication
- **Social OAuth**: Google and GitHub login integration
- **Two-Factor Authentication**: TOTP support with Google Authenticator
- **Session Management**: Secure session handling with Laravel

### Container Security
- **Isolation**: Each preview runs in isolated Docker container
- **Code Validation**: Generated code validated before execution
- **Resource Limits**: Container resource usage monitored
- **Network Isolation**: Containers have limited network access

## Troubleshooting

### Common Issues

**Docker Issues**:
```bash
# Check Docker status
docker --version
docker ps

# Rebuild preview image
./scripts/build-docker-preview.sh

# Clean up containers
docker system prune -f
```

**Python Backend Issues**:
- Ensure `.env` exists with `OPENAI_API_KEY`
- Check if port 8000 is available
- Verify Python dependencies are installed

**Laravel Issues**:
- Ensure `.env` is configured correctly
- Run `php artisan migrate` to create tables
- Check `PY_BACKEND_URL` matches Python backend port

**Preview Issues**:
- Verify Docker is running
- Check container logs: `docker logs <container_id>`
- Ensure ports 8001-8010 are available
- Check Laravel logs: `tail -f storage/logs/laravel.log`

### Performance Optimization
- **Container Pooling**: Pre-create containers for faster response
- **Resource Monitoring**: Monitor Docker resource usage
- **Cleanup Scheduling**: Set up automatic container cleanup
- **Caching**: Implement Redis caching for better performance
