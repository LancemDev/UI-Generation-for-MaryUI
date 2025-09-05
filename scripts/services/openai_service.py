import os
from typing import Optional
import dotenv

from openai import OpenAI


_client: Optional[OpenAI] = None


def _get_client() -> OpenAI:
    global _client
    if _client is None:
        api_key = os.getenv("OPENAI_API_KEY")
        if not api_key:
            raise RuntimeError("OPENAI_API_KEY environment variable is not set")
        _client = OpenAI(api_key=api_key)
    return _client


def generate_code(
    prompt: str,
    model: Optional[str] = None,
    temperature: float = 0.2,
    max_tokens: int = 1024,
) -> str:
    """
    Generate code from a natural language prompt using OpenAI chat completions.

    Args:
        prompt: Natural language description of the code to generate.
        model: Optional model override. Defaults to env OPENAI_MODEL or "gpt-4o-mini".
        temperature: Sampling temperature for creativity.
        max_tokens: Maximum tokens in the completion.

    Returns:
        The generated code/text as a string.
    """
    selected_model = model or os.getenv("OPENAI_MODEL", "gpt-4o-mini")

    client = _get_client()

    completion = client.chat.completions.create(
        model=selected_model,
        temperature=temperature,
        max_tokens=max_tokens,
        messages=[
            {
                "role": "system",
                "content": (
                    "You are a helpful assistant that writes clean, runnable code. "
                    "Return only the code unless asked to explain."
                ),
            },
            {"role": "user", "content": prompt},
        ],
    )

    content = completion.choices[0].message.content if completion.choices else ""
    return content.strip()

