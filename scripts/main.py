"""Skylarr AI service: turns a chat message plus project context into Livewire components.

Run from the scripts/ directory:
    uvicorn main:app --host 127.0.0.1 --port 8001
"""

from __future__ import annotations

import json
import queue
import threading
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

import anyio
from fastapi import Depends, FastAPI, HTTPException
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import StreamingResponse

from core.config import get_settings
from core.exceptions import ConfigurationError, GenerationCancelled, ProviderError
from core.security import require_service_token
from services.generator import generate
from services.knowledge import get_knowledge_base
from services.providers.base import make_provider
from services.schema import GenerateRequest, GenerateResponse


@asynccontextmanager
async def lifespan(app: FastAPI):
    get_knowledge_base()
    yield


app = FastAPI(title="Skylarr AI service", version="0.3.0", lifespan=lifespan)


@app.get("/health")
async def health() -> dict:
    settings = get_settings()
    return {
        "status": "ok",
        "provider": settings.provider,
        "model": settings.main_model,
        "fast_model": settings.fast_model,
        "maryui": get_knowledge_base().mary_version,
    }


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


@app.post("/generate/stream", dependencies=[Depends(require_service_token)])
def post_generate_stream(request: GenerateRequest) -> StreamingResponse:
    """Same as /generate, as newline-delimited JSON events:

        {"type": "reply", "text": "<next part of the reply>"}   (zero or more)
        {"type": "done", "response": {...GenerateResponse...}}
        {"type": "error", "status": 502, "detail": "..."}        (instead of done)
    """

    settings = get_settings()
    try:
        provider = make_provider(settings)
    except ConfigurationError as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    events: queue.Queue[dict | None] = queue.Queue()
    cancelled = threading.Event()

    def work() -> None:
        try:
            response = generate(
                request,
                provider,
                get_knowledge_base(),
                settings,
                on_reply=lambda text: events.put({"type": "reply", "text": text}),
                cancelled=cancelled,
            )
            events.put({"type": "done", "response": response.model_dump()})
        except GenerationCancelled:
            pass
        except ProviderError as exc:
            events.put({"type": "error", "status": 502, "detail": str(exc)})
        except Exception as exc:  # surfaced to Laravel instead of a broken stream
            events.put({"type": "error", "status": 500, "detail": f"Generation failed: {exc}"})
        finally:
            events.put(None)

    async def stream() -> AsyncIterator[str]:
        threading.Thread(target=work, daemon=True).start()
        try:
            # Async so a client disconnect cancels this loop (a sync generator would sit
            # blocked in events.get() and never reach `finally`).
            while (event := await anyio.to_thread.run_sync(events.get, abandon_on_cancel=True)) is not None:
                yield json.dumps(event) + "\n"
        finally:
            # Stop further model calls once nobody is listening.
            cancelled.set()

    return StreamingResponse(stream(), media_type="application/x-ndjson")


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("main:app", host="127.0.0.1", port=8001, reload=True)
