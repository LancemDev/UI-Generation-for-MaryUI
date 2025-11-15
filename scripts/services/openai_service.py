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


def extract_component_name(prompt: str, code: str = "") -> str:
    """Extract a meaningful component name from prompt or code."""
    import re
    
    # First, try to extract from code if provided
    if code:
        # Look for class definition: class ComponentName extends
        match = re.search(r'class\s+([A-Z][a-zA-Z0-9]*)\s+extends', code)
        if match:
            return match.group(1)
    
    # Extract from prompt
    prompt_lower = prompt.lower()
    
    # Common patterns
    patterns = [
        r'(?:create|build|generate|make)\s+(?:a\s+)?(?:simple\s+)?(?:complex\s+)?(?:basic\s+)?([a-z]+)\s+(?:component|form|modal|table|dashboard|page|view)',
        r'([a-z]+)\s+(?:component|form|modal|table|dashboard|page|view)',
        r'(?:a|an|the)\s+([a-z]+)',
    ]
    
    for pattern in patterns:
        match = re.search(pattern, prompt_lower)
        if match:
            name = match.group(1)
            # Convert to PascalCase
            return name.capitalize()
    
    # Fallback: use first meaningful word
    words = re.findall(r'\b[a-z]{3,}\b', prompt_lower)
    if words:
        # Skip common words
        skip_words = {'create', 'build', 'generate', 'make', 'component', 'form', 'modal', 'table', 'with', 'that', 'this', 'the', 'a', 'an'}
        for word in words:
            if word not in skip_words:
                return word.capitalize()
    
    return "Component"


def generate_code(
    prompt: str,
    model: Optional[str] = None,
    temperature: float = 0.2,
    max_tokens: int = 1024,
) -> tuple[str, str]:
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

CRITICAL INSTRUCTIONS - YOU MUST FOLLOW THESE EXACTLY:
1. Generate ONLY Laravel Livewire component code - NO other frameworks (React, Vue, Angular, Svelte, etc.)
2. Generate ONLY PHP code - NO JavaScript, TypeScript, Python, or any other language
3. The code MUST be a Livewire component that extends Livewire\\Component
4. The code MUST use namespace App\\Livewire
5. The code MUST use Laravel Blade syntax for views
6. The code MUST use MaryUI components (x-form, x-input, x-button, etc.)
7. The code must start with <?php
8. Return a complete PHP class with namespace, imports, and methods
9. Do NOT include markdown code blocks (```php or ```)
10. Do NOT include explanations before or after the code
11. Do NOT generate React components, Vue components, or any frontend framework code
12. Return ONLY Laravel Livewire PHP code, nothing else

FORBIDDEN: React, Vue, Angular, Svelte, Next.js, Nuxt, JavaScript, TypeScript, JSX, TSX, Python, Ruby, Java, or any non-PHP code.

REQUIRED FORMAT:
<?php
namespace App\\Livewire;
use Livewire\\Component;
class MyComponent extends Component
{{
    public function render()
    {{
        return view('livewire.my-component');
    }}
}}"""

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
    
    # Remove markdown code blocks if present
    if content.startswith("```"):
        lines = content.split("\n")
        # Remove first line if it's a code block marker
        if lines[0].startswith("```"):
            lines = lines[1:]
        # Remove last line if it's a code block marker
        if lines and lines[-1].strip() == "```":
            lines = lines[:-1]
        content = "\n".join(lines)
    
    code = content.strip()
    
    # Extract component name from code or prompt
    component_name = extract_component_name(prompt, code)
    
    return code, component_name


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

CRITICAL: This tool ONLY generates Laravel Livewire components. We do NOT support React, Vue, Angular, Svelte, or any other frameworks.

IMPORTANT: When the user asks you to create/build/generate components:
1. DO NOT show code in the chat response
2. Instead, acknowledge their request and tell them you're working on it
3. Example: "I'll create that for you! Working on it now..." or "Building your component now..."
4. Keep responses short and conversational
5. The code generation happens in the background - just acknowledge the request
6. If the user asks for non-Laravel/Livewire code, politely redirect them to Laravel Livewire alternatives"""
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
