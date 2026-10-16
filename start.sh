#!/bin/sh
# Start the AI service and the Laravel app (server, queue worker, scheduler, Vite)
# for local development. Assumes the setup steps in README.md are done.
set -e
ROOT="$(cd "$(dirname "$0")" && pwd)"

(cd "$ROOT/scripts" && exec venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001) &
AI_PID=$!
trap 'kill $AI_PID 2>/dev/null' EXIT INT TERM

cd "$ROOT/skylarr" && composer dev
