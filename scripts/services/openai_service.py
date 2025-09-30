import os
from typing import Optional
from pathlib import Path
from dotenv import load_dotenv, find_dotenv

from openai import OpenAI


_client: Optional[OpenAI] = None

# Load environment variables from either CWD or the scripts folder explicitly
_loaded = load_dotenv(find_dotenv(usecwd=True))
if not _loaded:
    # Try scripts/.env relative to this file (scripts/services/openai_service.py -> scripts/.env)
    load_dotenv(Path(__file__).resolve().parents[1] / '.env')


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
    Generate code from a natural language prompt using OpenAI chat completions with GNN context.

    Args:
        prompt: Natural language description of the code to generate.
        model: Optional model override. Defaults to env OPENAI_MODEL or "gpt-4o-mini".
        temperature: Sampling temperature for creativity.
        max_tokens: Maximum tokens in the completion.

    Returns:
        The generated code/text as a string.
    """
    from .gnn_service import get_gnn_service
    
    selected_model = model or os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    client = _get_client()
    
    # Get GNN context for the prompt
    gnn_service = get_gnn_service()
    gnn_context = gnn_service.get_enhanced_context(prompt)
    
    system_message = f"""You are Skylarr, an AI assistant that helps build dynamic Livewire frontends with MaryUI.
MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind.

{gnn_context}

Generate valid MaryUI Blade code that follows the component relationships. Use <x-component> syntax (no maryui prefix) and ensure proper nesting. Return only the code unless asked to explain."""

    completion = client.chat.completions.create(
        model=selected_model,
        temperature=temperature,
        max_tokens=max_tokens,
        messages=[
            {"role": "system", "content": system_message},
            {"role": "user", "content": prompt},
        ],
    )

    content = completion.choices[0].message.content if completion.choices else ""
    return content.strip()


def stream_chat(
    messages: list[dict],
    model: Optional[str] = None,
    temperature: float = 0.2,
    max_tokens: int = 1024,
):
    """Yield chat chunks using OpenAI streaming with GNN-enhanced context."""
    from .gnn_service import get_gnn_service
    
    selected_model = model or os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    client = _get_client()
    
    # Get the last user message for GNN context
    last_user_message = ""
    for msg in reversed(messages):
        if msg.get("role") == "user":
            last_user_message = msg.get("content", "")
            break
    
    # If it's just a greeting, respond with a Skylarr-specific onboarding message
    if last_user_message.strip().lower() in {"hi", "hello", "hey", "yo", "sup", "hi!", "hello!", "hey!"}:
        canned = (
            "Hi, I’m Skylarr. What component do you want to build today? "
            "Examples: modal with form, login page, tabs + table, navbar, or a full Livewire flow. "
            "Tell me the components and I’ll scaffold MaryUI Blade with correct nesting and Livewire actions."
        )
        yield canned
        return

    # Enhance messages with GNN context
    enhanced_messages = messages.copy()
    if last_user_message:
        gnn_service = get_gnn_service()
        gnn_context = gnn_service.get_enhanced_context(last_user_message)
        
        # Add system message with GNN context
        system_message = {
            "role": "system",
            "content": f"""You are Skylarr, an AI assistant that helps build dynamic Livewire frontends with MaryUI.
MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind.

{gnn_context}

Generate valid MaryUI Blade code that follows the component relationships. Use <x-component> syntax (no maryui prefix) and ensure proper nesting."""
        }
        
        # Insert system message at the beginning
        enhanced_messages.insert(0, system_message)

    stream = client.chat.completions.create(
        model=selected_model,
        temperature=temperature,
        max_tokens=max_tokens,
        stream=True,
        messages=enhanced_messages,
    )

    for event in stream:
        try:
            delta = event.choices[0].delta.content
            if delta:
                yield delta
        except Exception:
            # Some events may not have content (e.g., role updates)
            continue
