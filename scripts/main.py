"""
FastAPI application for serving static files and API endpoints for SKYLARR
"""

from fastapi import FastAPI, HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

# Import works both when executed as a package (python -m uvicorn scripts.main:app)
# and when run directly (python scripts/main.py)
try:
    from .services.openai_service import generate_code, stream_chat  # type: ignore
except Exception:  # pragma: no cover
    from services.openai_service import generate_code, stream_chat  # type: ignore

app = FastAPI()


class GenerateRequest(BaseModel):
	prompt: str
	model: str | None = None
	temperature: float = 0.2
	max_tokens: int = 1024


@app.get("/")
async def read_root():
	return {"message": "Hello, World!"}


@app.post("/generate-code")
async def post_generate_code(body: GenerateRequest):
	try:
		code = generate_code(
			prompt=body.prompt,
			model=body.model,
			temperature=body.temperature,
			max_tokens=body.max_tokens,
		)
		return {"code": code}
	except Exception as exc:
		raise HTTPException(status_code=500, detail=str(exc))


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