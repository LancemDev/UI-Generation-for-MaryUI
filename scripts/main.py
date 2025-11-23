"""
FastAPI application for serving static files and API endpoints for SKYLARR
"""

import os
from fastapi import FastAPI, HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from dotenv import load_dotenv, find_dotenv
from pathlib import Path

# Load environment variables
_loaded = load_dotenv(find_dotenv(usecwd=True))
if not _loaded:
    load_dotenv(Path(__file__).resolve().parent / '.env')

# Determine which AI provider to use (default: openai)
AI_PROVIDER = os.getenv("AI_PROVIDER", "openai").lower()

# Import the appropriate service based on AI_PROVIDER
if AI_PROVIDER == "gemini":
    try:
        from .services.gemini_service import generate_code, stream_chat  # type: ignore
    except Exception:  # pragma: no cover
        from services.gemini_service import generate_code, stream_chat  # type: ignore
    provider_name = "Gemini"
else:
    try:
        from .services.openai_service import generate_code, stream_chat  # type: ignore
    except Exception:  # pragma: no cover
        from services.openai_service import generate_code, stream_chat  # type: ignore
    provider_name = "OpenAI"

app = FastAPI(
    title="Skylarr AI Backend",
    description=(
        f"Skylarr: AI assistant to help build dynamic Livewire frontends with MaryUI. "
        f"Backed by a GNN-powered scene-graph context and {provider_name} streaming."
    ),
    version="0.1.0",
)


@app.on_event("startup")
async def preload_gnn():
    # Preload GNN once on startup so we don't rebuild on first request
    try:
        from .services.gnn_service import get_gnn_service
        get_gnn_service()
    except Exception:
        # Optional; continue even if preload fails
        pass


class GenerateRequest(BaseModel):
	prompt: str
	messages: list[dict] | None = None  # Conversation history for context
	model: str | None = None
	temperature: float = 0.2
	max_tokens: int = 1024


@app.get("/")
async def read_root():
	return {"message": "Hello, World!"}


@app.post("/generate/code")
async def post_generate_code(body: GenerateRequest):
    try:
        code, component_name = generate_code(
            prompt=body.prompt,
            messages=body.messages or [],
            model=body.model,
            temperature=body.temperature,
            max_tokens=body.max_tokens,
        )
        return {
            "success": True,
            "code": code,
            "component_name": component_name,
            "message": "Code generated successfully"
        }
    except Exception as exc:
        return {
            "success": False,
            "message": f"Error generating code: {str(exc)}"
        }


class ChatRequest(BaseModel):
    messages: list[dict]
    model: str | None = None
    temperature: float = 0.2
    max_tokens: int = 1024


@app.post("/chat/stream")
async def post_chat_stream(body: ChatRequest):
    try:
        def _gen():
            for chunk in stream_chat(
                messages=body.messages,
                model=body.model,
                temperature=body.temperature,
                max_tokens=body.max_tokens,
            ):
                yield chunk

        return StreamingResponse(_gen(), media_type="text/event-stream")
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


if __name__ == "__main__":
    import uvicorn, os
    # Enable auto-reload by using an import string. Works from repo root or scripts/ dir.
    module_path = "scripts.main:app" if os.path.basename(os.getcwd()) != "scripts" else "main:app"
    uvicorn.run(module_path, host="127.0.0.1", port=8001, reload=True)


# python main.py