# SKYLARR

SKYLARR turns chat messages into working Laravel Livewire components built with [MaryUI](https://mary-ui.com), and shows them running in a live, per-project preview.

You describe what you want ("a users table with a delete button", "add a phone field to the signup form"). SKYLARR writes the PHP class and Blade view, runs them in an isolated container, fixes anything that fails to render, and shows the result next to the chat.

## How it works

```
Browser ── Livewire (skylarr/) ── queue ──> ProcessChatMessage job
                                               │
                                               ├─ 1. POST /generate ──> AI service (scripts/)
                                               │       message + history + current component source
                                               │       <── { reply, actions: [{op, component, php, blade}] }
                                               │
                                               ├─ 2. write files into the project's preview container
                                               ├─ 3. render each component; on errors, send them back
                                               │     to the model (up to AI_MAX_REPAIR_ATTEMPTS)
                                               └─ 4. save reply + versions; UI polls and refreshes
```

- **One model call per message.** The model returns structured JSON (strict JSON schema on OpenAI, response schema on Gemini). It decides whether to just answer, create components or update them. Nothing is parsed out of free text, and no keyword lists decide what you meant.
- **Edits see the real code.** Updates are sent with the component's current PHP and Blade, read from the container, so your own edits in the code tab are kept.
- **Grounded in the real MaryUI.** `scripts/knowledge/maryui_reference.json` is extracted from the MaryUI version the previews run: every component, its props and slots, and all 1,288 Heroicons. Each request gets the relevant usage guides and exact props. Unknown icons are swapped for the closest real one, and unknown tags are flagged.
- **Verified before you see it.** Each generated component is linted and rendered over HTTP inside its container. Render errors (with the message from the Laravel log) go back to the model for a fix.
- **Background processing.** Generation runs in a queued job, one per project at a time. The request returns immediately and the chat and preview poll for progress.

## Repository layout

| Path | What it is |
| --- | --- |
| `skylarr/` | Laravel 12 + Livewire 3 app: auth, projects, chat, preview panel, queue jobs, evals |
| `scripts/` | Python FastAPI AI service: prompt building, MaryUI retrieval, OpenAI/Gemini adapters |
| `scripts/knowledge/` | MaryUI reference (generated) and hand-written usage guides |
| `scripts/services/prompts/` | System and repair prompts, as plain files |
| `preview-reference/` | The Laravel app baked into the preview image (`skylarr-preview`) |
| `skylarr/evals/cases.json` | Prompts used to score generation quality |

## Setup

Requirements: PHP 8.2+, Composer, Node 18+, Python 3.11+, Docker, and an OpenAI or Gemini API key.

### 1. AI service

```bash
cd scripts
python3 -m venv venv
venv/bin/pip install -r requirements.txt
cat > .env <<'EOF'
AI_PROVIDER=openai            # or gemini
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4.1          # any model with structured outputs
# GEMINI_API_KEY=...
# GEMINI_MODEL=gemini-2.5-flash
AI_SERVICE_TOKEN=change-me    # shared secret with Laravel
EOF
venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001
```

`GET /health` reports the provider, model and MaryUI version.

### 2. Laravel app

```bash
cd skylarr
composer install
cp .env.example .env && php artisan key:generate
# set AI_SERVICE_TOKEN to the same value as scripts/.env
# set SKYLARR_ADMIN_EMAILS to your email for container maintenance endpoints
php artisan migrate
npm install && npm run build
composer dev     # web server, queue worker, scheduler, logs, Vite
```

A queue worker must be running, because generation happens in `ProcessChatMessage`. `composer dev` starts one. In production, run `php artisan queue:work --timeout=900` under a process supervisor, and the scheduler (`php artisan schedule:work` or cron).

`./start.sh` at the repo root starts the AI service and `composer dev` together.

### 3. Preview image

```bash
cd skylarr
./scripts/build-docker-preview.sh
```

Rebuild the image whenever `preview-reference/` or `skylarr/docker/` changes. Containers created from an older image should be stopped (`php artisan docker:cleanup-orphaned --all`) so projects restart on the new image.

## Preview containers

Each project gets its own container from `skylarr-preview:latest`:

- **Isolation and limits:** `--memory 512m --cpus 1 --pids-limit 256`, all capabilities dropped except the five nginx/php-fpm need, `no-new-privileges`, and the port bound to `127.0.0.1` only. All of these are configurable in `config/skylarr.php` / `.env`.
- **No internet for generated code (recommended):** set `PREVIEW_NETWORK` to a Docker network whose egress you block at the host firewall. Published ports still work.
- **Instant first preview:** `PREVIEW_POOL_SIZE` idle containers are kept warm (`preview:fill-pool` runs every five minutes). A new project claims one with an atomic rename.
- **Routing without edits:** the preview app registers `/<kebab-name>` for every class in `app/Livewire`. Writing two files is all it takes; there are no route edits, cache clears or autoload dumps.
- **Cleanup:** `preview:cleanup-idle` (hourly) stops containers of projects untouched for `PREVIEW_IDLE_HOURS`. When a project's container is recreated, its saved components are written back automatically.
- **Admin-only maintenance:** `/api/preview/cleanup*`, `/api/preview/port/{port}` and `/api/preview/remove-all` need an email listed in `SKYLARR_ADMIN_EMAILS`.

## Measuring quality

```bash
cd skylarr
php artisan skylarr:eval                 # all cases
php artisan skylarr:eval --case=users-table --keep
```

Each case in `evals/cases.json` runs through the real pipeline (AI service plus preview container). It is checked for render errors, how many components changed, create vs. update, and required or forbidden markup (for example, `x-table` must use `@scope`, not the non-existent `<x-table.th>`). The command prints the pass rate, render rate, average repair attempts and token usage, and writes a JSON report to `storage/app/evals/`. Run it before and after changing prompts, guides or models.

Thumbs up/down in the chat is saved on the message, together with the components it produced. The model sees it as context for the rest of the conversation. To turn it into regression cases:

```bash
php artisan skylarr:export-feedback --rating=negative   # writes evals/feedback-cases.json for review
```

## Updating MaryUI

After bumping MaryUI in `preview-reference/composer.json`:

```bash
cd preview-reference && composer update robsontenorio/mary && cd ..
php scripts/knowledge/extract_maryui.php preview-reference > scripts/knowledge/maryui_reference.json
cd scripts && venv/bin/python -m pytest   # checks guides and icon lists still match the reference
```

## Tests

```bash
cd scripts && venv/bin/python -m pytest
cd skylarr && php artisan test
```

## Configuration reference

| Variable | Where | Default | Purpose |
| --- | --- | --- | --- |
| `AI_PROVIDER` | scripts | `openai` | `openai` or `gemini` |
| `OPENAI_MODEL` / `GEMINI_MODEL` | scripts | `gpt-4.1` / `gemini-2.5-flash` | Model used for generation |
| `AI_MAX_OUTPUT_TOKENS` | scripts | `16000` | Output budget per call (multi-page requests need room) |
| `AI_SERVICE_TOKEN` | both | empty | Shared secret; empty disables auth (local only) |
| `AI_SERVICE_URL` | skylarr | `http://127.0.0.1:8001` | AI service address |
| `AI_MAX_REPAIR_ATTEMPTS` | skylarr | `2` | Fix rounds when a component fails to render |
| `SKYLARR_ADMIN_EMAILS` | skylarr | empty | Who may run container maintenance |
| `PREVIEW_*` | skylarr | see `config/skylarr.php` | Image, limits, network, pool size, ports |
| `DB_QUEUE_RETRY_AFTER` | skylarr | `960` | Must exceed the 900s job timeout |

## License

MIT
