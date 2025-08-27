"""
FastAPI application for serving static files and API endpoints for SKYLARR
"""

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

from services.openai_service import generate_code

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