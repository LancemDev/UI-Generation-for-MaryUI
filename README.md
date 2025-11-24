# Graph-Guided Prompt-Based UI Generation for MaryUI (SKYLARR)

SKYLARR is an AI-powered UI generation platform that combines Laravel Livewire with MaryUI components, powered by Graph Neural Networks (GNN) and scene graphs for intelligent component generation. It provides real-time code generation with live preview capabilities through Docker containerization.

## Overview

SKYLARR enables developers to generate production-ready Laravel Livewire components using natural language prompts. The system uses OpenAI's GPT models enhanced with GNN context to understand component relationships and generate beautiful, minimal UI components using MaryUI.

### Key Features

* **AI-Powered Code Generation**: Generate complete Livewire components (PHP class + Blade view) using OpenAI with GNN-enhanced context
* **Live Preview System**: Real-time preview of generated components in isolated Docker containers
* **Multi-Project Support**: Each user can manage multiple projects with separate preview environments
* **Streaming Chat Interface**: Real-time AI chat with automatic code generation triggers
* **Conversational Code Generation**: Maintains conversation history for iterative refinement
* **Social Authentication**: Google and GitHub OAuth integration
* **Two-Factor Authentication**: Enhanced security with TOTP support
* **Theme Selector**: Dynamic daisyUI theme switching for previews
* **Auto-Error Detection & Correction**: Automatically detects and fixes common code errors
* **Component Overwrite Protection**: Prevents accidental overwrites with confirmation modals

### Architecture

* **Laravel Frontend** (`skylarr/`): Livewire components, MaryUI styling, user management, project management
* **Python Backend** (`scripts/`): FastAPI service with OpenAI integration and GNN context enhancement
* **Docker Preview System**: Isolated containers for live component preview with automatic route management
* **Database**: SQLite database for user projects, chat threads, component metadata, and notifications

## Requirements

* **Docker**: Required for preview containers (Docker Desktop or Docker Engine)
* **Python 3.11+**: For the AI backend service (Python 3.13 recommended)
* **Node.js 18+**: For Vite/Tailwind frontend build
* **PHP 8.2+**: For Laravel application
* **Composer**: PHP dependency manager
* **OpenAI API Key**: For AI code generation (get from https://platform.openai.com/)
* **Git**: For version control
* **8GB+ RAM**: Recommended for running Docker containers and Laravel application

## Local Setup

### 1. Clone the repository

```bash
git clone https://github.com/yourusername/Graph-Guided-Prompt-Based-UI-Generation-for-MaryUI-using-Scene-Graphs-and-GNN.git
cd Graph-Guided-Prompt-Based-UI-Generation-for-MaryUI-using-Scene-Graphs-and-GNN
```

### 2. Python Backend Setup

**Create and activate virtual environment:**

```bash
cd scripts
python3.11 -m venv venv
```

**Note:** Ensure you have Python 3.11 or later installed. Check your version:

```bash
python3 --version  # Should show 3.11.x or higher
```

**Activate virtual environment:**

macOS/Linux:
```bash
source venv/bin/activate
```

Windows (Command Prompt):
```bash
venv\Scripts\activate.bat
```

Windows (PowerShell):
```bash
venv\Scripts\Activate.ps1
```

**Note:** You should see `(venv)` in your terminal prompt when activated.

**Install dependencies:**

```bash
pip install --upgrade pip setuptools wheel
pip install -r requirements.txt
```

**Create environment file:**

Create a `.env` file in the `scripts/` directory (or at repo root):

```env
OPENAI_API_KEY=sk-your-api-key-here
OPENAI_MODEL=gpt-4o-mini
```

The service attempts to load the nearest `.env` and also falls back to `scripts/.env`.

**Run the Python backend:**

```bash
# Option A (from scripts directory):
python -m uvicorn main:app --reload --host 127.0.0.1 --port 8000

# Option B (from repo root):
python -m uvicorn scripts.main:app --reload --host 127.0.0.1 --port 8000

# Option C (direct file run):
python scripts/main.py
```

The server exposes:
* `GET /` → Health check
* `GET /health` → Service status
* `POST /chat/stream` → Streams text chunks as they arrive from OpenAI
* `POST /generate/code` → Generates Livewire component code with GNN context
* `GET /gnn/summary` → Returns GNN graph summary

### 3. Laravel Application Setup

**Navigate to Laravel directory:**

```bash
cd skylarr
```

**Install PHP dependencies:**

```bash
composer install
```

**Create environment file:**

```bash
cp .env.example .env
```

**Generate application key:**

```bash
php artisan key:generate
```

**Configure environment variables:**

Edit `skylarr/.env` and configure:

```env
# Application
APP_URL=http://127.0.0.1:8001
APP_NAME="SKYLARR"

# Database (SQLite)
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

# AI Backend
PY_BACKEND_URL=http://127.0.0.1:8000

# OAuth (Google) - Optional
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://127.0.0.1:8001/auth/google/callback

# OAuth (GitHub) - Optional
GITHUB_CLIENT_ID=your_github_client_id
GITHUB_CLIENT_SECRET=your_github_client_secret
GITHUB_REDIRECT_URI=http://127.0.0.1:8001/auth/github/callback

# Mail (for 2FA) - Optional
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
```

**Run database migrations:**

```bash
php artisan migrate
```

This creates:
* `users` table (with 2FA and OAuth fields)
* `projects` table (for project management)
* `chat_threads` table (for chat persistence)
* `chat_messages` table (for message history)
* `notifications` table (for system notifications)

**Install Node.js dependencies and build assets:**

```bash
npm install
npm run build
```

**Run Laravel application:**

```bash
php artisan serve --host=127.0.0.1 --port=8001
```

### 4. Docker Preview System Setup

**Build the preview Docker image:**

```bash
cd skylarr
chmod +x scripts/build-docker-preview.sh
./scripts/build-docker-preview.sh
```

This creates a Docker image named `skylarr-preview:latest` that contains:
* Laravel 12 with Livewire 3
* MaryUI components
* daisyUI themes (all 35 themes enabled)
* Nginx web server
* PHP 8.4-FPM

**Verify Docker is running:**

```bash
docker --version
docker ps
```

**Test the preview image (optional):**

```bash
docker run -d -p 8002:80 --name test-preview skylarr-preview:latest
curl http://localhost:8002
docker stop test-preview && docker rm test-preview
```

### 5. Verify Installation

**Check Python backend:**

```bash
curl http://127.0.0.1:8000/health
```

Expected response:
```json
{"status": "healthy"}
```

**Check Laravel application:**

Open http://127.0.0.1:8001 in your browser. You should see the SKYLARR login page.

**Test code generation:**

1. Create an account or log in
2. Create a new project
3. Open the chat interface
4. Type: "create a registration form"
5. Watch as the AI generates code and displays it in the preview

## Project Structure

```
Graph-Guided-Prompt-Based-UI-Generation-for-MaryUI-using-Scene-Graphs-and-GNN/
├── skylarr/                      # Laravel application
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── PreviewController.php      # Preview proxy endpoints
│   │   │   │   ├── ProjectController.php      # Project CRUD
│   │   │   │   └── Auth/
│   │   │   │       └── SocialAuthController.php  # OAuth integration
│   │   ├── Livewire/
│   │   │   ├── ChatInterface.php              # Chat component with code generation
│   │   │   ├── CodeGenerationEngine.php       # Code generation & preview
│   │   │   ├── CodeGenerator.php              # Main code generator page
│   │   │   └── CustomComponents/
│   │   │       └── NavigationBar.php           # Navigation with project selector
│   │   ├── Models/
│   │   │   ├── Project.php                    # Project model
│   │   │   ├── ChatThread.php                 # Chat thread model
│   │   │   ├── ChatMessage.php                # Chat message model
│   │   │   └── Notification.php                # Notification model
│   │   └── Services/
│   │       ├── AiGateway.php                   # Communication with Python backend
│   │       ├── DockerPreviewService.php        # Docker container management
│   │       └── NotificationService.php         # Notification management
│   ├── resources/
│   │   ├── views/
│   │   │   └── livewire/
│   │   │       ├── chat-interface.blade.php    # Chat UI
│   │   │       ├── code-generation-engine.blade.php  # Code & preview split view
│   │   │       └── custom-components/
│   │   │           └── navigation-bar.blade.php  # Navigation component
│   │   ├── css/
│   │   │   └── app.css                          # Tailwind + daisyUI config
│   │   └── js/
│   │       └── app.js                          # Frontend JavaScript
│   ├── docker/
│   │   ├── Dockerfile.preview                 # Preview container Dockerfile
│   │   ├── nginx.conf                          # Nginx configuration
│   │   └── start.sh                            # Container startup script
│   ├── scripts/
│   │   └── build-docker-preview.sh             # Docker image build script
│   ├── database/
│   │   └── migrations/                         # Database migrations
│   └── routes/
│       └── web.php                             # Application routes
├── scripts/                                    # Python backend
│   ├── main.py                                 # FastAPI app entrypoint
│   ├── services/
│   │   ├── openai_service.py                   # OpenAI client + streaming
│   │   └── gnn_service.py                      # GNN data prep and context
│   ├── data/
│   │   └── maryui_gnn_data/                    # GNN graph data (pickle files)
│   │       ├── adj_matrix.pkl                  # Adjacency matrix
│   │       ├── components.pkl                  # Component data
│   │       ├── maryui_graph.pkl                # Graph structure
│   │       └── node_features.pkl               # Node features
│   ├── core/
│   │   ├── config.py                           # Configuration management
│   │   ├── exceptions.py                       # Custom exceptions
│   │   └── security.py                         # Security utilities
│   ├── tests/                                  # Test suite
│   │   ├── gnn_openai_test.py                  # GNN + OpenAI tests
│   │   └── raw_pipeline.py                     # Pipeline tests
│   └── requirements.txt                        # Python dependencies
├── preview-reference/                          # Reference Laravel app for Docker
│   ├── app/
│   │   └── Livewire/                           # Generated components go here
│   ├── resources/
│   │   ├── views/
│   │   │   └── components/
│   │   │       └── layouts/
│   │   │           └── app.blade.php           # Main layout with theme support
│   │   ├── css/
│   │   │   └── app.css                         # daisyUI themes config
│   │   └── js/
│   │       └── app.js                          # Livewire imports
│   └── routes/
│       └── web.php                             # Dynamic route registration
└── README.md                                   # This file
```

## How the System Works

### Chat and Code Generation Flow

1. **User Input**: User types a message in the chat interface
2. **Message Persistence**: Laravel saves the user message to `chat_messages` table
3. **AI Processing**: `AiGateway` sends message history to Python backend `/chat/stream`
4. **Streaming Response**: AI response streams back in real-time chunks via Server-Sent Events
5. **Code Detection**: System detects code generation keywords (create, build, add, update, etc.)
6. **Code Generation**: Triggers `/generate/code` endpoint with:
   - User prompt
   - Conversation history for context
   - GNN-enhanced context for component relationships
7. **Code Parsing**: Python backend parses generated code (PHP class + Blade view)
8. **Code Validation**: Laravel validates syntax and structure
9. **Preview Creation**: Docker container created/injected with generated code
10. **Route Registration**: Dynamic routes created for component access
11. **Live Preview**: User sees real-time preview in iframe with theme support

### Docker Preview System

1. **Container Creation**: Each project gets its own Docker container (max 5 per user)
2. **Code Injection**: Generated Livewire components injected into container:
   * PHP class file → `/var/www/html/app/Livewire/ComponentName.php`
   * Blade view file → `/var/www/html/resources/views/livewire/component-name.blade.php`
3. **Route Registration**: Dynamic routes created in `routes/web.php`:
   * Format: `Route::get('/component-name', ComponentName::class);`
   * No root route (`/`) - each component has unique route
4. **Live Updates**: Real-time preview updates as code changes
5. **Resource Management**: Automatic cleanup of inactive containers (24 hours)
6. **Theme Support**: Dynamic theme switching via `data-theme` attribute

### Multi-Project Architecture

* **User Isolation**: Each user can have multiple projects
* **Container Per Project**: Each project runs in its own Docker container
* **Resource Limits**: Maximum 5 containers per user
* **Automatic Cleanup**: Containers cleaned up after 24 hours of inactivity
* **Port Management**: Automatic port assignment (8001-8010)

### GNN Context Integration

The system uses Graph Neural Networks to provide intelligent context:

* **Component Relationships**: Understands MaryUI component dependencies
* **Scene Graph Analysis**: Analyzes UI structure and relationships
* **Context Injection**: Enhances AI prompts with relevant component information
* **Smart Suggestions**: Provides better code generation based on component patterns
* **Conversational Memory**: Maintains conversation history for iterative refinement

### Error Detection & Auto-Correction

The system automatically detects and fixes common errors:

* **Null Property Access**: Converts `$var->property` to `$var?->property`
* **Namespace in Blade**: Removes PHP namespace declarations from Blade files
* **Missing Namespaces**: Adds proper namespaces to PHP classes
* **Runtime Errors**: Detects errors via HTTP requests and Laravel logs
* **AI-Powered Correction**: Re-generates code with error context if auto-fix fails

## API Endpoints

### Laravel API Routes

* `GET /api/projects` → List user projects
* `POST /api/projects` → Create new project
* `GET /api/projects/{id}` → Get project details
* `PUT /api/projects/{id}` → Update project
* `DELETE /api/projects/{id}` → Delete project
* `GET /preview/{projectId}` → Proxy preview requests (iframe support)
* `POST /api/preview/create` → Create preview for project
* `GET /api/preview/{project}/status` → Get preview status
* `PUT /api/preview/update` → Update preview with new code
* `DELETE /api/preview/{project}/stop` → Stop preview container

### Python Backend Endpoints

* `GET /` → Health check
* `GET /health` → Service status
* `POST /chat/stream` → Stream AI chat responses (Server-Sent Events)
* `POST /generate/code` → Generate Livewire component code
  * Request body:
    ```json
    {
      "prompt": "create a registration form",
      "messages": [{"role": "user", "content": "..."}],
      "model": "gpt-4o-mini",
      "temperature": 0.2,
      "max_tokens": 4096
    }
    ```
  * Response:
    ```json
    {
      "success": true,
      "code": "===PHP===...===BLADE===...===END===",
      "component_name": "RegisterForm"
    }
    ```
* `GET /gnn/summary` → Get GNN graph summary

## Features in Detail

### AI Code Generation

* **Multi-Component Support**: Generate multiple connected components in one request
* **Conversational Refinement**: Iteratively improve components through conversation
* **GNN-Enhanced Context**: Better code generation through component relationship understanding
* **Minimalist Approach**: Generates clean, minimal code using MaryUI's built-in styling
* **Auto-Error Correction**: Automatically detects and fixes common errors

### Live Preview System

* **Isolated Containers**: Each preview runs in its own Docker container
* **Real-Time Updates**: Preview updates automatically when code is generated
* **Theme Support**: Switch between 35 daisyUI themes dynamically
* **Route Management**: Automatic route creation and management
* **Copy Code**: One-click code copying from the code viewer

### Chat Interface

* **Streaming Responses**: Real-time AI responses via Server-Sent Events
* **Code Generation Triggers**: Automatic detection of code generation requests
* **Conversation History**: Maintains full conversation context
* **Message Persistence**: All messages saved to database
* **Loading States**: Visual feedback during message sending and code generation

### Project Management

* **Multi-Project Support**: Create and manage multiple projects
* **Project Switching**: Easy switching between projects via dropdown
* **Container Tracking**: Automatic container lifecycle management
* **Resource Limits**: Maximum 5 containers per user
* **Auto-Cleanup**: Inactive containers cleaned up after 24 hours

## Security Features

### Authentication

* **Social OAuth**: Google and GitHub login integration
* **Two-Factor Authentication**: TOTP support with Google Authenticator
* **Session Management**: Secure session handling with Laravel
* **Password Hashing**: Bcrypt password hashing

### Container Security

* **Isolation**: Each preview runs in isolated Docker container
* **Code Validation**: Generated code validated before execution
* **Resource Limits**: Container resource usage monitored
* **Network Isolation**: Containers have limited network access
* **File Permissions**: Proper file ownership and permissions in containers

## Troubleshooting

### Common Issues

**Docker Issues:**

```bash
# Check Docker status
docker --version
docker ps

# Rebuild preview image
cd skylarr
./scripts/build-docker-preview.sh

# Clean up containers
docker system prune -f

# Check container logs
docker logs <container_id>

# List all containers
docker ps -a
```

**Python Backend Issues:**

* Ensure `.env` exists with `OPENAI_API_KEY`
* Check if port 8000 is available: `lsof -i :8000`
* Verify Python dependencies are installed: `pip list`
* Check Python version: `python3 --version` (should be 3.11+)
* Activate virtual environment: `source scripts/venv/bin/activate`

**Laravel Issues:**

* Ensure `.env` is configured correctly
* Run `php artisan migrate` to create tables
* Check `PY_BACKEND_URL` matches Python backend port
* Clear caches: `php artisan config:clear && php artisan cache:clear`
* Check Laravel logs: `tail -f skylarr/storage/logs/laravel.log`

**Preview Issues:**

* Verify Docker is running: `docker ps`
* Check container logs: `docker logs <container_id>`
* Ensure ports 8001-8010 are available
* Rebuild Docker image: `./scripts/build-docker-preview.sh`
* Check file permissions in container

**Code Generation Issues:**

* Verify OpenAI API key is valid
* Check Python backend is running: `curl http://127.0.0.1:8000/health`
* Review Laravel logs for errors
* Check Docker container logs for runtime errors
* Verify GNN data files exist in `scripts/data/maryui_gnn_data/`

**UI/Preview Not Updating:**

* Clear browser cache
* Check browser console for errors
* Verify Livewire is working: Check for `wire:` attributes
* Check if iframe is loading: Inspect network tab
* Verify route is registered: Check `routes/web.php` in container

### Performance Optimization

* **Container Pooling**: Pre-create containers for faster response (future enhancement)
* **Resource Monitoring**: Monitor Docker resource usage
* **Cleanup Scheduling**: Set up automatic container cleanup
* **Caching**: Implement Redis caching for better performance (future enhancement)
* **Database Optimization**: Use PostgreSQL for production (instead of SQLite)

## Development

### Running Tests

**Python Backend:**

```bash
cd scripts
source venv/bin/activate
python -m pytest tests/
```

**Laravel Application:**

```bash
cd skylarr
php artisan test
```

### Code Style

**PHP (Laravel):**

```bash
cd skylarr
./vendor/bin/pint
```

**Python:**

```bash
cd scripts
source venv/bin/activate
# Use black or flake8 (if configured)
```

### Debugging

**Enable Debug Mode:**

In `skylarr/.env`:
```env
APP_DEBUG=true
LOG_LEVEL=debug
```

**View Logs:**

```bash
# Laravel logs
tail -f skylarr/storage/logs/laravel.log

# Docker container logs
docker logs <container_id> -f

# Python backend (if using uvicorn with --reload)
# Logs appear in terminal
```

## Contributing

We welcome contributions! Please follow these guidelines:

1. **Fork the repository**
2. **Create a feature branch**: `git checkout -b feature/amazing-feature`
3. **Make your changes** with clear commit messages
4. **Test your changes** thoroughly
5. **Submit a pull request** with a detailed description

### Code Style Guidelines

* **PHP**: Follow PSR-12 coding standards
* **Python**: Follow PEP 8 style guide
* **JavaScript**: Use modern ES6+ syntax
* **Blade Templates**: Use MaryUI components, keep it minimal
* **Comments**: Explain **why**, not **what**

## License

MIT License - see LICENSE file for details

## Acknowledgments

* **Laravel**: The PHP framework for web artisans
* **Livewire**: Full-stack framework for Laravel
* **MaryUI**: Beautiful Laravel Blade UI components
* **daisyUI**: Tailwind CSS component library
* **OpenAI**: AI models for code generation
* **FastAPI**: Modern Python web framework
* **Docker**: Containerization platform

## Support

For issues, questions, or contributions:

* **GitHub Issues**: [Create an issue](https://github.com/yourusername/Graph-Guided-Prompt-Based-UI-Generation-for-MaryUI-using-Scene-Graphs-and-GNN/issues)
* **Documentation**: Check this README and code comments
* **Logs**: Review Laravel and Docker logs for error details

---

**Built with ❤️ using Laravel, Livewire, MaryUI, and OpenAI**
