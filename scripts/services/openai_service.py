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
    max_tokens: int = 4096,
) -> tuple[str, str]:
    """
    Generate complete, production-ready Livewire component code with full Blade views.
    
    Returns both PHP class code and Blade view code in a structured format.

    Args:
        prompt: Natural language description of the code to generate.
        model: Optional model override. Defaults to env OPENAI_MODEL or "gpt-4o-mini".
        temperature: Sampling temperature for creativity.
        max_tokens: Maximum tokens in the completion (increased for complete code).

    Returns:
        Tuple of (combined_code_string, component_name) where combined_code_string contains
        both PHP class and Blade view separated by markers.
    """
    from .gnn_service import get_gnn_service
    
    selected_model = model or os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    client = _get_client()
    
    # Get GNN context for the prompt
    gnn_service = get_gnn_service()
    gnn_context = gnn_service.get_enhanced_context(prompt)
    
    system_message = f"""You are Skylarr, an AI assistant that generates PRODUCTION-READY, HOLISTIC Laravel Livewire components with complete, beautiful Blade views using MaryUI.

MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind CSS.
Available MaryUI components include: x-form, x-input, x-textarea, x-select, x-checkbox, x-radio, x-button, x-card, x-modal, x-table, x-badge, x-alert, x-dropdown, x-tabs, x-avatar, x-progress, x-link, x-stat, x-menu, and more.

{gnn_context}

CRITICAL REQUIREMENTS - YOU MUST GENERATE COMPLETE, PRODUCTION-READY, HOLISTIC CODE:

1. **GENERATE BOTH PHP CLASS AND COMPLETE BLADE VIEW** - Do not use placeholders like "component here" or "<!-- Component view content -->"
2. **USE MARYUI COMPONENTS** - Use proper MaryUI components (x-form, x-input, x-button, etc.) with proper styling
3. **BEAUTIFUL, MODERN UI** - Create polished, professional-looking interfaces with proper spacing, colors, and layout
4. **COMPLETE FUNCTIONALITY** - Include all necessary properties, methods, validation, and user interactions
5. **PROPER TAILWIND STYLING** - Use Tailwind CSS classes for spacing, colors, typography, and responsive design
6. **NO PLACEHOLDERS** - Every element must be fully implemented, not just commented placeholders
7. **ROUTE-AWARE** - Components will be automatically accessible at /component-name route. Design components to work standalone or as part of a larger application
8. **HOLISTIC DESIGN** - Think about the complete user experience: navigation, forms, data display, feedback, error handling, loading states
9. **REAL-WORLD READY** - Generate code that works in production, not just demos. Include proper validation, error handling, and user feedback

OUTPUT FORMAT - You MUST return code in this exact format:

===PHP===
<?php
namespace App\\Livewire;
use Livewire\\Component;
use Mary\\Traits\\Toast;

class ComponentName extends Component
{{
    use Toast;
    
    // Properties
    public $name = '';
    public $email = '';
    
    // Methods with toast feedback (toasts are automatic, no session needed)
    public function submit()
    {{
        $this->validate([
            'name' => 'required',
            'email' => 'required|email',
        ]);
        
        // Save data...
        
        $this->success('Saved successfully!'); // Toast automatically shown
    }}
    
    public function render()
    {{
        return view('livewire.component-name');
    }}
}}
===BLADE===
<div class="min-h-screen bg-gray-50 py-12 px-4">
    <div class="max-w-2xl mx-auto">
        <x-card class="shadow-xl">
            <x-slot:header>
                <h2 class="text-3xl font-bold">Create Account</h2>
            </x-slot:header>
            
            <x-form wire:submit="submit" class="space-y-6">
                <x-input label="Name" wire:model="name" class="input-bordered" />
                <x-input label="Email" type="email" wire:model="email" class="input-bordered" />
                
                <x-slot:actions>
                    <x-button type="submit" class="btn-primary" spinner="submit">Submit</x-button>
                </x-slot:actions>
            </x-form>
        </x-card>
    </div>
</div>
===END===

EXAMPLES OF HOLISTIC, PRODUCTION-READY BLADE VIEWS:

Complete Form with Validation Feedback:
<div class="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl mx-auto">
        <x-card class="shadow-xl">
            <x-slot:header>
                <div class="flex items-center justify-between">
                    <h2 class="text-3xl font-bold text-gray-900">Create Account</h2>
                    <x-badge value="New" class="badge-primary" />
                </div>
            </x-slot:header>
            
            <x-form wire:submit="submit" class="space-y-6">
                <x-input 
                    label="Full Name" 
                    wire:model="name" 
                    hint="Enter your full name"
                    class="input-bordered" />
                
                <x-input 
                    label="Email Address" 
                    type="email" 
                    wire:model="email"
                    hint="We'll never share your email"
                    class="input-bordered" />
                
                <x-textarea 
                    label="Message" 
                    wire:model="message" 
                    rows="4"
                    placeholder="Tell us about yourself..."
                    class="textarea-bordered" />
                
                <x-slot:actions>
                    <x-button type="submit" class="btn-primary" spinner="submit">
                        <x-icon name="o-paper-airplane" class="w-4 h-4 mr-2" />
                        Submit
                    </x-button>
                    <x-button wire:click="cancel" class="btn-ghost">
                        Cancel
                    </x-button>
                </x-slot:actions>
            </x-form>
        </x-card>
    </div>
</div>

CRITICAL - MARYUI AUTOMATIC FEATURES:
- Toast trait: use Mary\Traits\Toast; then $this->success('Message') AUTOMATICALLY shows a toast - NO frontend code needed
- Validation errors: Livewire/MaryUI AUTOMATICALLY displays validation errors - DO NOT use @error() directives or manual <x-alert> components
- DO NOT add @error('field') <x-alert>...</x-alert> @enderror - this is redundant and wrong
- DO NOT use session()->has('success') or session('success') - toasts are automatic
- Just use clean form inputs - validation errors and toasts are handled automatically by the framework

Complete Data Table with Search and Actions:
<div class="min-h-screen bg-gray-50 py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-7xl mx-auto">
        <x-card class="shadow-xl">
            <x-slot:header>
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-900">Users</h2>
                        <p class="text-sm text-gray-500 mt-1">Manage your user accounts</p>
                    </div>
                    <x-button wire:click="create" class="btn-primary">
                        <x-icon name="o-plus" class="w-4 h-4 mr-2" />
                        Add User
                    </x-button>
                </div>
            </x-slot:header>
            
            <div class="mb-4">
                <x-input 
                    wire:model.live.debounce.300ms="search" 
                    placeholder="Search users..." 
                    icon="o-magnifying-glass"
                    class="input-bordered w-full max-w-md" />
            </div>
            
            @if($users->isEmpty())
                <div class="text-center py-12">
                    <x-icon name="o-inbox" class="w-16 h-16 mx-auto text-gray-400 mb-4" />
                    <p class="text-gray-500">No users found</p>
                </div>
            @else
                <x-table>
                    <x-slot:headers>
                        <x-table.th>Name</x-table.th>
                        <x-table.th>Email</x-table.th>
                        <x-table.th>Status</x-table.th>
                        <x-table.th>Created</x-table.th>
                        <x-table.th class="text-right">Actions</x-table.th>
                    </x-slot:headers>
                    @foreach($users as $user)
                        <x-table.tr wire:key="user-{{ $user->id }}">
                            <x-table.td>
                                <div class="flex items-center gap-3">
                                    <x-avatar image="{{ $user->avatar }}" class="w-10 h-10" />
                                    <span class="font-medium">{{ $user->name }}</span>
                                </div>
                            </x-table.td>
                            <x-table.td>{{ $user->email }}</x-table.td>
                            <x-table.td>
                                <x-badge value="{{ $user->status }}" class="badge-{{ $user->status === 'active' ? 'success' : 'warning' }}" />
                            </x-table.td>
                            <x-table.td class="text-sm text-gray-500">{{ $user->created_at->format('M d, Y') }}</x-table.td>
                            <x-table.td>
                                <div class="flex justify-end gap-2">
                                    <x-button wire:click="edit({{ $user->id }})" class="btn-sm btn-ghost" spinner="edit({{ $user->id }})">
                                        <x-icon name="o-pencil" class="w-4 h-4" />
                                    </x-button>
                                    <x-button wire:click="delete({{ $user->id }})" wire:confirm="Are you sure?" class="btn-sm btn-error" spinner="delete({{ $user->id }})">
                                        <x-icon name="o-trash" class="w-4 h-4" />
                                    </x-button>
                                </div>
                            </x-table.td>
                        </x-table.tr>
                    @endforeach
                </x-table>
                
                <div class="mt-4">
                    {{ $users->links() }}
                </div>
            @endif
        </x-card>
    </div>
</div>

FORBIDDEN:
- React, Vue, Angular, Svelte, or any other framework
- Placeholder text like "component here" or "<!-- Component view content -->"
- Incomplete views with missing UI elements
- Plain HTML without MaryUI components
- JavaScript/TypeScript code
- Incomplete forms without validation feedback
- Empty states without proper messaging
- Missing error handling or user feedback

REQUIRED:
- Complete, beautiful Blade views with MaryUI components
- Proper Tailwind CSS styling with responsive design
- Full functionality (forms work, buttons have actions, validation, etc.)
- Professional, polished appearance that looks production-ready
- Use Mary\\Traits\\Toast in PHP class: use Mary\\Traits\\Toast; then $this->success('Message') - toasts are AUTOMATIC, no frontend code needed
- Validation errors are AUTOMATICALLY displayed by Livewire/MaryUI - DO NOT add @error() directives or manual alert components
- Keep Blade views clean - just form inputs, no manual error handling
- Empty states when no data is available
- Proper spacing, typography, and visual hierarchy
- Icons from Heroicons (using x-icon component)
- Loading states with spinners on async actions (spinner="methodName" attribute)
- Confirmation dialogs for destructive actions (wire:confirm="Are you sure?")
- Holistic user experience - think about the complete flow, not just individual elements

ROUTING NOTE:
- Routes are automatically generated at /component-name (kebab-case)
- Design components to work standalone at their route
- Consider navigation between related components if applicable
- Use proper Livewire wire:model, wire:click, wire:submit for interactivity"""

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
    
    # Parse the structured response (PHP and Blade separated by markers)
    # Format: ===PHP=== ... ===BLADE=== ... ===END===
    php_code = ""
    blade_code = ""
    
    if "===PHP===" in content and "===BLADE===" in content:
        parts = content.split("===BLADE===")
        php_part = parts[0].replace("===PHP===", "").strip()
        blade_part = parts[1].replace("===END===", "").strip() if len(parts) > 1 else ""
        
        php_code = php_part
        blade_code = blade_part
    elif "===BLADE===" in content:
        # Only Blade provided, extract it
        blade_code = content.split("===BLADE===")[1].replace("===END===", "").strip()
        # Try to extract PHP from elsewhere or use default
        php_code = content.split("===BLADE===")[0].strip()
    else:
        # Fallback: treat entire content as PHP code (backward compatibility)
        php_code = content.strip()
        blade_code = ""
    
    # If no Blade code extracted, try to find it in markdown blocks
    if not blade_code:
        # Look for blade code blocks
        import re
        blade_blocks = re.findall(r'```(?:blade)?\s*\n(.*?)\n```', content, re.DOTALL)
        if blade_blocks:
            blade_code = blade_blocks[-1].strip()  # Take the last blade block
    
    # Combine PHP and Blade with a marker for parsing later
    if blade_code:
        combined_code = f"{php_code}\n\n===BLADE_VIEW===\n{blade_code}\n===END_BLADE==="
    else:
        combined_code = php_code
    
    # Extract component name from code or prompt
    component_name = extract_component_name(prompt, php_code or combined_code)
    
    return combined_code, component_name


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
