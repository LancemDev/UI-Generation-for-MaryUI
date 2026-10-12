"""Skylarr AI service: turns a chat message plus project context into Livewire components.

Run from the scripts/ directory:
    uvicorn main:app --host 127.0.0.1 --port 8001
"""

from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException
from fastapi.concurrency import run_in_threadpool

from core.config import get_settings
from core.exceptions import ConfigurationError, ProviderError
from core.security import require_service_token
from services.generator import generate
from services.knowledge import get_knowledge_base
from services.providers.base import make_provider
from services.schema import GenerateRequest, GenerateResponse


@asynccontextmanager
async def lifespan(app: FastAPI):
    get_knowledge_base()
    yield


app = FastAPI(title="Skylarr AI service", version="0.2.0", lifespan=lifespan)


@app.get("/health")
async def health() -> dict:
    settings = get_settings()
    model = settings.openai_model if settings.provider == "openai" else settings.gemini_model
    return {"status": "ok", "provider": settings.provider, "model": model, "maryui": get_knowledge_base().mary_version}


@app.post("/generate", response_model=GenerateResponse, dependencies=[Depends(require_service_token)])
async def post_generate(request: GenerateRequest) -> GenerateResponse:
    settings = get_settings()
    try:
        provider = make_provider(settings)
        # Provider SDK calls are blocking; keep the event loop free for other requests.
        return await run_in_threadpool(generate, request, provider, get_knowledge_base(), settings)
    except ConfigurationError as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc
    except ProviderError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("main:app", host="127.0.0.1", port=8001, reload=True)
