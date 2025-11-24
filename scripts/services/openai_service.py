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


def _extract_feedback_context(messages: list[dict]) -> str:
    """
    Extract feedback context from conversation history to help the AI learn.
    
    Returns a formatted string with feedback insights that can be included in prompts.
    """
    if not messages:
        return ""
    
    feedback_summary = []
    positive_examples = []
    negative_examples = []
    
    # Look for feedback indicators in messages
    # Feedback is typically embedded in user messages as context
    for i, msg in enumerate(messages):
        content = msg.get('content', '')
        
        # Check if message contains feedback context (from Laravel backend)
        if 'User feedback context:' in content:
            # Extract feedback information
            if 'positive' in content.lower():
                positive_examples.append(content[:200])  # Store preview
            if 'negative' in content.lower():
                negative_examples.append(content[:200])
    
    # Build feedback context string
    if positive_examples or negative_examples:
        feedback_summary.append("User has provided feedback on previous responses:")
        
        if positive_examples:
            feedback_summary.append(f"- {len(positive_examples)} positive feedback(s): User appreciated these responses. Continue similar patterns.")
        
        if negative_examples:
            feedback_summary.append(f"- {len(negative_examples)} negative feedback(s): User was not satisfied with these responses. Avoid similar patterns and improve.")
        
        feedback_summary.append("Use this feedback to adapt your responses and improve user satisfaction.")
        
        return "\n".join(feedback_summary)
    
    return ""


def analyze_intent(prompt: str, messages: list[dict] | None = None) -> dict:
    """
    Use AI to analyze user intent and determine operation type and component names.
    
    Returns:
        dict with:
        - operation_type: "CREATE", "UPDATE", or "BOTH"
        - target_components: list of existing component names to update (if any)
        - new_components: list of new component names to create (if any)
    """
    client = _get_client()
    selected_model = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    
    # Build context from conversation history
    context = ""
    if messages:
        # Extract component names mentioned in conversation
        import re
        mentioned_components = []
        for msg in reversed(messages):
            if msg.get('role') == 'assistant' and msg.get('content'):
                content = msg['content']
                # Look for component names in backticks
                matches = re.findall(r'`([A-Z][a-zA-Z0-9]+)`', content)
                mentioned_components.extend(matches)
        
        if mentioned_components:
            context = f"Previously mentioned components: {', '.join(set(mentioned_components))}\n"
    
    intent_prompt = f"""{context}
Analyze the user's request and determine:
1. Operation type: CREATE (new component), UPDATE (modify existing), or BOTH
2. Target components: Which existing components should be updated (if any)
3. New components: What new components should be created (if any)

User request: "{prompt}"

Respond in JSON format:
{{
    "operation_type": "CREATE" | "UPDATE" | "BOTH",
    "target_components": ["ComponentName1", "ComponentName2"],
    "new_components": ["NewComponentName1", "NewComponentName2"],
    "reasoning": "brief explanation"
}}

Examples:
- "add another login form" → {{"operation_type": "CREATE", "target_components": [], "new_components": ["LoginFormComponent"]}}
- "add 2 fields to register form" → {{"operation_type": "UPDATE", "target_components": ["RegisterFormComponent"], "new_components": []}}
- "add 2 fields to register form and add another login form" → {{"operation_type": "BOTH", "target_components": ["RegisterFormComponent"], "new_components": ["LoginFormComponent"]}}
- "create a dashboard" → {{"operation_type": "CREATE", "target_components": [], "new_components": ["DashboardComponent"]}}
"""
    
    try:
        response = client.chat.completions.create(
            model=selected_model,
            temperature=0.1,
            max_tokens=200,
            messages=[
                {"role": "system", "content": "You are an expert at analyzing user intent for code generation. Respond ONLY with valid JSON."},
                {"role": "user", "content": intent_prompt}
            ],
            response_format={"type": "json_object"}
        )
        
        import json
        result = json.loads(response.choices[0].message.content)
        
        # Validate and normalize
        operation_type = result.get("operation_type", "CREATE").upper()
        if operation_type not in ["CREATE", "UPDATE", "BOTH"]:
            operation_type = "CREATE"
        
        return {
            "operation_type": operation_type,
            "target_components": result.get("target_components", []),
            "new_components": result.get("new_components", []),
            "reasoning": result.get("reasoning", "")
        }
    except Exception as e:
        # Fallback to keyword-based detection
        import re
        prompt_lower = prompt.lower()
        
        has_create = any(kw in prompt_lower for kw in ["add another", "create", "new", "another"])
        has_update = any(kw in prompt_lower for kw in ["add", "update", "modify", "change", "edit"]) and any(kw in prompt_lower for kw in ["field", "fields", "button", "input"])
        
        # Extract component names from conversation
        target_components = []
        if messages:
            for msg in reversed(messages):
                if msg.get('role') == 'assistant' and msg.get('content'):
                    match = re.search(r'`([A-Z][a-zA-Z0-9]+)`', msg['content'])
                    if match:
                        target_components.append(match.group(1))
                        break
        
        if has_create and has_update:
            operation_type = "BOTH"
        elif has_update and target_components:
            operation_type = "UPDATE"
        else:
            operation_type = "CREATE"
        
        return {
            "operation_type": operation_type,
            "target_components": target_components if operation_type in ["UPDATE", "BOTH"] else [],
            "new_components": [],
            "reasoning": "Fallback keyword-based detection"
        }


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
    messages: list[dict] | None = None,
    model: Optional[str] = None,
    temperature: float = 0.2,
    max_tokens: int = 4096,
) -> tuple[str, str, dict]:
    """
    Generate complete, production-ready Livewire component code with full Blade views.
    
    Returns both PHP class code and Blade view code in a structured format.

    Args:
        prompt: Natural language description of the code to generate.
        messages: Optional conversation history for context. Format: [{'role': 'user'|'assistant', 'content': '...'}, ...]
        model: Optional model override. Defaults to env OPENAI_MODEL or "gpt-4o-mini".
        temperature: Sampling temperature for creativity.
        max_tokens: Maximum tokens in the completion (increased for complete code).

    Returns:
        Tuple of (combined_code_string, component_name, intent_info) where:
        - combined_code_string contains both PHP class and Blade view separated by markers
        - component_name is the primary component name
        - intent_info is a dict with operation_type, target_components, new_components
    """
    from .gnn_service import get_gnn_service
    
    selected_model = model or os.getenv("OPENAI_MODEL", "gpt-4o-mini")
    client = _get_client()
    
    # Get GNN context for the prompt
    gnn_service = get_gnn_service()
    gnn_context = gnn_service.get_enhanced_context(prompt)
    
    # Extract feedback context from conversation history
    feedback_context = _extract_feedback_context(messages or [])
    
    # Analyze user intent using AI (with error handling)
    try:
        intent_info = analyze_intent(prompt, messages)
    except Exception as e:
        # If intent analysis fails, use fallback
        import logging
        logging.warning(f"Intent analysis failed: {e}. Using fallback.")
        intent_info = {
            "operation_type": "CREATE",
            "target_components": [],
            "new_components": [],
            "reasoning": f"Intent analysis failed: {str(e)}"
        }
    
    # Parse user intent from prompt and conversation history (keep for backward compatibility)
    import re
    
    # Detect follow-up keywords
    follow_up_keywords = ['add', 'update', 'modify', 'change', 'edit', 'remove', 'delete', 'field', 'fields']
    prompt_lower = prompt.lower()
    is_followup = any(keyword in prompt_lower for keyword in follow_up_keywords)
    
    # Extract component names from conversation history
    existing_component_name = None
    mentioned_components = []
    
    if messages:
        # Look for component names in conversation history
        for msg in reversed(messages):
            if msg.get('role') == 'assistant' and msg.get('content'):
                content = msg['content']
                # Extract component name from messages like "I've created the `ComponentName` component"
                match = re.search(r'`([A-Z][a-zA-Z0-9]+)`', content)
                if match:
                    comp_name = match.group(1)
                    if comp_name not in mentioned_components:
                        mentioned_components.append(comp_name)
                    if not existing_component_name:
                        existing_component_name = comp_name
                # Also check for component names in code blocks or explicit mentions
                match = re.search(r'([A-Z][a-zA-Z0-9]+(?:Form|Component|Modal|Dashboard))', content)
                if match:
                    comp_name = match.group(1)
                    if comp_name not in mentioned_components:
                        mentioned_components.append(comp_name)
                    if not existing_component_name:
                        existing_component_name = comp_name
    
    # Parse prompt for multiple operations
    # Check if user wants to update existing AND create new
    has_update_operation = any(keyword in prompt_lower for keyword in ['add', 'update', 'modify', 'change', 'edit'])
    has_create_operation = any(keyword in prompt_lower for keyword in ['add another', 'create', 'new', 'another'])
    
    # Detect if user mentions specific component types
    wants_register = 'register' in prompt_lower
    wants_login = 'login' in prompt_lower
    
    # Build follow-up instruction
    followup_instruction = ""
    if is_followup and existing_component_name:
        # Check if this is a multi-operation request
        if has_create_operation and has_update_operation:
            # User wants to update existing AND create new
            followup_instruction = f"""
CRITICAL: This is a MULTI-OPERATION request:
1. UPDATE the existing component "{existing_component_name}" - add/modify fields as requested
2. CREATE a NEW separate component for the new form/component requested

OPERATION 1 - UPDATE "{existing_component_name}":
- You MUST use the EXACT same component class name: {existing_component_name}
- UPDATE the existing component by adding/modifying fields, methods, or views as requested
- Preserve existing functionality unless explicitly asked to change it

OPERATION 2 - CREATE NEW COMPONENT:
- Generate a SEPARATE, NEW component with its own class name
- If user asks for "login form", create "LoginFormComponent" or "LoginComponent"
- If user asks for "register form", create "RegisterFormComponent" or "RegisterComponent"
- Each component should be complete and independent

OUTPUT FORMAT: Generate BOTH components in sequence:
===PHP===
[UPDATE: {existing_component_name} class code]
===BLADE===
[UPDATE: {existing_component_name} view code]
===END===
===PHP===
[NEW: NewComponentName class code]
===BLADE===
[NEW: NewComponentName view code]
===END===
"""
        else:
            # Single operation - just update existing
            followup_instruction = f"""
IMPORTANT: This is a FOLLOW-UP request to UPDATE an existing component named "{existing_component_name}".
- You MUST use the EXACT same component class name: {existing_component_name}
- UPDATE the existing component by adding/modifying fields, methods, or views as requested
- DO NOT create a new component with a different name
- Preserve existing functionality unless explicitly asked to change it
- The user wants to modify the existing {existing_component_name} component, not create a new one
"""
    
    # Build feedback instruction
    feedback_instruction = ""
    if feedback_context:
        feedback_instruction = f"""

LEARNING FROM USER FEEDBACK:
{feedback_context}

Use this feedback to improve code generation. Learn from positive examples and avoid patterns that received negative feedback.
"""
    
    system_message = f"""You are Skylarr, an AI assistant that generates PRODUCTION-READY, HOLISTIC Laravel Livewire components with complete, beautiful Blade views using MaryUI.

MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind CSS.
Available MaryUI components include: x-form, x-input, x-textarea, x-select, x-checkbox, x-radio, x-button, x-card, x-modal, x-table, x-badge, x-alert, x-dropdown, x-tabs, x-avatar, x-progress, x-link, x-stat, x-menu, and more.

{gnn_context}
{followup_instruction}
{feedback_instruction}

⚠️ CRITICAL: FOLLOW USER INSTRUCTIONS EXACTLY - Parse the user's request carefully and do EXACTLY what they ask:

1. **READ THE PROMPT CAREFULLY** - Understand what the user wants:
   - "add 2 fields to register form" = UPDATE existing RegisterFormComponent, add 2 fields
   - "add another login form" = CREATE a NEW LoginFormComponent (separate component)
   - "add 2 fields to register form AND add another login form" = BOTH operations:
     * UPDATE RegisterFormComponent (add 2 fields)
     * CREATE new LoginFormComponent (separate component)

2. **MULTIPLE OPERATIONS** - If user requests multiple things:
   - Generate SEPARATE components for each new component requested
   - UPDATE existing components when user says "add", "update", "modify" to existing component
   - DO NOT combine multiple components into one unless explicitly asked

3. **COMPONENT NAMING**:
   - For updates: Use the EXACT existing component name
   - For new components: Use appropriate names (RegisterFormComponent, LoginFormComponent, etc.)
   - DO NOT create generic names like "AuthComponent" when user asks for specific forms

4. **FOCUS ON CURRENT REQUEST**:
   - If user asks for "register form" or "login form" → Generate ONLY forms, NOT dashboards
   - If user asks for "modal" → Generate ONLY modals with the requested content
   - DO NOT generate dashboards, sidebars, or multi-page apps unless explicitly requested
   - DO NOT add extra features that weren't requested
   - Previous conversation history is for context only - focus on the CURRENT user request

CRITICAL REQUIREMENTS - YOU MUST GENERATE COMPLETE, PRODUCTION-READY, HOLISTIC CODE:

0. **BLADE FILES ARE PURE TEMPLATES** - Blade view files (.blade.php) must NEVER contain:
   - PHP namespace declarations (namespace App\\Livewire;)
   - PHP opening tags (<?php)
   - use statements
   - Any PHP code outside Blade directives
   Blade files should ONLY contain HTML, Blade syntax ({{ }}, @if, etc.), and MaryUI components.

1. **GENERATE BOTH PHP CLASS AND COMPLETE BLADE VIEW** - Do not use placeholders like "component here" or "<!-- Component view content -->"
2. **USE MARYUI COMPONENTS** - Use proper MaryUI components (x-form, x-input, x-button, etc.) with proper styling
3. **BEAUTIFUL, MODERN UI** - Create polished, professional-looking interfaces with proper spacing, colors, and layout
4. **COMPLETE FUNCTIONALITY** - Include all necessary properties, methods, validation, and user interactions
5. **MINIMAL STYLING - CRITICAL** - MaryUI components are pre-styled with daisyUI themes. Use ABSOLUTELY MINIMAL CSS:
   - NO custom CSS classes unless absolutely necessary
   - NO Tailwind utility classes like min-h-screen, bg-gray-50, py-12, px-4, max-w-2xl, mx-auto
   - NO inline styles except for very specific cases
   - Use ONLY MaryUI component attributes for styling (class="p-6" for padding is acceptable, but prefer MaryUI's built-in spacing)
   - Let MaryUI and daisyUI handle ALL styling - they support theme switching automatically
   - Themes are handled via data-theme attribute on HTML element - components automatically adapt
6. **SHARED LAYOUT PATTERN - DASHBOARDS & MULTI-PAGE APPS ONLY** - ONLY use this pattern when the user EXPLICITLY requests a dashboard, admin panel, or multi-page application. For simple forms, modals, or single components, DO NOT use this layout:
   - ALWAYS use the shared layout component: `<x-layouts.app-with-sidebar>`
   - The layout provides: mobile navbar, collapsible sidebar, navigation menu, and content area
   - Page components should ONLY contain their specific content wrapped in the layout:
     ```blade
     <x-layouts.app-with-sidebar>
         <div class="p-6">
             <!-- Page-specific content here -->
         </div>
     </x-layouts.app-with-sidebar>
     ```
   - DO NOT recreate sidebar/navbar in individual components - use the shared layout
   - Navigation menu items should use `<x-menu-item>` with `link` attribute for routing
   - Use `activate-by-route` on `<x-menu>` for automatic active state highlighting
7. **NO PLACEHOLDERS** - Every element must be fully implemented, not just commented placeholders
8. **ROUTE-AWARE** - Components will be automatically accessible at /component-name route. Design components to work standalone or as part of a larger application
9. **MULTIPLE COMPONENTS - ONLY FOR DASHBOARDS** - ONLY when user EXPLICITLY requests a "dashboard with sidebar navigation" or "multi-page application". For forms, modals, or single components, generate ONE component:
   - ⚠️ CRITICAL: You MUST generate SEPARATE components for EACH sidebar menu item/page
   - ⚠️ NEVER create a single component with all content bundled together - that is WRONG and LAZY
   - Each sidebar item MUST be its own component with its own route
   - Example: User says "dashboard with sidebar showing users, orders, and revenue"
     * You MUST generate 4 separate components:
       1. DashboardComponent (main dashboard with overview stats)
       2. UsersComponent (users management page)
       3. OrdersComponent (orders management page)  
       4. RevenueComponent (revenue page)
     * Each component uses `<x-layouts.app-with-sidebar>` and contains ONLY its specific content
     * IMPORTANT: The sidebar menu in the layout component should list ALL routes for ALL components
     * Each component's Blade view should include the full menu with all routes:
       - <x-menu-item title="Dashboard" icon="o-sparkles" link="/dashboard-component" />
       - <x-menu-item title="Users" icon="o-users" link="/users-component" />
       - <x-menu-item title="Orders" icon="o-shopping-cart" link="/orders-component" />
       - <x-menu-item title="Revenue" icon="o-currency-dollar" link="/revenue-component" />
     * Routes use kebab-case of component name (DashboardComponent → /dashboard-component)
   - If user says "dashboard with sidebar showing X, Y, Z", create separate pages for X, Y, Z PLUS a main dashboard page
   - OUTPUT FORMAT: Generate each component in sequence with ===PHP===, ===BLADE===, ===END=== markers
10. **MULTIPLE COMPONENTS (GENERAL)** - If the user requests multiple components (e.g., "login form, register form, and dashboard"), generate ALL components in separate code blocks. Each component should be complete with its own ===PHP=== and ===BLADE=== sections. Use redirect()->to('/route-path') to navigate between components.
11. **HOLISTIC DESIGN** - Think about the complete user experience: navigation, forms, data display, feedback, error handling, loading states
12. **REAL-WORLD READY** - Generate code that works in production, not just demos. Include proper validation, error handling, and user feedback

OUTPUT FORMAT - You MUST return code in this exact format:

CRITICAL: DO NOT include any explanatory text, descriptions, or comments outside the code blocks. 
ONLY output the code markers (===PHP===, ===BLADE===, ===END===) and the actual code.
DO NOT add text like "This code creates..." or "The following code..." - just output the code directly.

IF MULTIPLE COMPONENTS ARE REQUESTED: Generate each component in sequence, each with its own ===PHP=== and ===BLADE=== sections.

EXAMPLE 1 - Dashboard with sidebar navigation:
User prompt: "Create a dashboard with sidebar navigation showing statistics for users, orders, and revenue"

YOU MUST GENERATE 4 COMPONENTS:
===PHP===
<?php
namespace App\\Livewire;
use Livewire\\Component;
use Mary\\Traits\\Toast;

class DashboardComponent extends Component
{{
    use Toast;
    public $usersCount = 1234;
    public $ordersCount = 456;
    public $revenue = 56789;
    
    public function render()
    {{
        return view('livewire.dashboard-component');
    }}
}}
===BLADE===
<x-layouts.app-with-sidebar>
    <div class="p-6">
        <x-card>
            <x-slot:header>
                <h2 class="text-2xl font-bold">Dashboard Overview</h2>
            </x-slot:header>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-stat label="Users" value="{{ $usersCount }}" icon="o-users" />
                <x-stat label="Orders" value="{{ $ordersCount }}" icon="o-shopping-cart" />
                <x-stat label="Revenue" value="${{ number_format($revenue, 2) }}" icon="o-currency-dollar" />
            </div>
        </x-card>
    </div>
</x-layouts.app-with-sidebar>
===END===
===PHP===
<?php
namespace App\\Livewire;
use Livewire\\Component;
use Mary\\Traits\\Toast;

class UsersComponent extends Component
{{
    use Toast;
    
    public function render()
    {{
        return view('livewire.users-component');
    }}
}}
===BLADE===
<x-layouts.app-with-sidebar>
    <div class="p-6">
        <x-card title="Users Management">
            <!-- Users content here -->
        </x-card>
    </div>
</x-layouts.app-with-sidebar>
===END===
===PHP===
<?php
namespace App\\Livewire;
use Livewire\\Component;
use Mary\\Traits\\Toast;

class OrdersComponent extends Component
{{
    use Toast;
    
    public function render()
    {{
        return view('livewire.orders-component');
    }}
}}
===BLADE===
<x-layouts.app-with-sidebar>
    <div class="p-6">
        <x-card title="Orders Management">
            <!-- Orders content here -->
        </x-card>
    </div>
</x-layouts.app-with-sidebar>
===END===
===PHP===
<?php
namespace App\\Livewire;
use Livewire\\Component;
use Mary\\Traits\\Toast;

class RevenueComponent extends Component
{{
    use Toast;
    
    public function render()
    {{
        return view('livewire.revenue-component');
    }}
}}
===BLADE===
<x-layouts.app-with-sidebar>
    <div class="p-6">
        <x-card title="Revenue">
            <!-- Revenue content here -->
        </x-card>
    </div>
</x-layouts.app-with-sidebar>
===END===

EXAMPLE 2 - Simple multiple components:
If asked for "login form and dashboard", generate:
===PHP===
[First component PHP code]
===BLADE===
[First component Blade view]
===END===
===PHP===
[Second component PHP code]
===BLADE===
[Second component Blade view]
===END===

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
CRITICAL: Blade view files are PURE TEMPLATE FILES - they must NEVER contain:
- PHP namespace declarations (namespace App\\Livewire;)
- PHP opening tags (<?php)
- use statements
- Any PHP code outside of Blade directives ({{ }}, @if, @foreach, etc.)

Blade files should ONLY contain HTML, Blade directives, and MaryUI components.

MINIMALISM IS KEY: MaryUI components are already beautifully styled. DO NOT add unnecessary wrapper divs with Tailwind classes like min-h-screen, bg-gray-50, py-12, px-4, max-w-2xl, mx-auto, etc. Keep it simple - just use a basic <div> wrapper and let MaryUI handle the styling.

⚠️ IMPORTANT: The examples below show different patterns. Use the SIMPLE FORM/MODAL pattern for register/login forms. Only use the dashboard layout pattern if the user EXPLICITLY requests a dashboard.

FOR DASHBOARDS AND MULTI-PAGE APPS ONLY (when user explicitly requests): Use the shared layout component pattern:
<x-layouts.app-with-sidebar>
    <div class="p-6">
        <!-- Page content here - use MaryUI components -->
        <x-card title="Dashboard">
            <x-stat label="Users" value="1,234" icon="o-users" />
            <x-stat label="Revenue" value="$5,678" icon="o-currency-dollar" />
            <x-stat label="Orders" value="456" icon="o-shopping-cart" />
        </x-card>
    </div>
</x-layouts.app-with-sidebar>

Example of MINIMAL, CORRECT approach for standalone components:
<div>
    <x-button wire:click="openRegisterModal" label="Register"/>
    <x-button wire:click="openLoginModal" label="Login" />

    <x-modal wire:model="registerModal">
        <x-form wire:submit="registerUser">
            <x-input wire:model="name" label="Name" />
            <x-input wire:model="email" label="Email" type="email" />
            <x-input wire:model="password" label="Password" type="password" />
            <x-slot:actions>
                <x-button type="submit">Register</x-button>
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="loginModal">
        <x-form wire:submit="loginUser">
            <x-input wire:model="email" label="Email" type="email" />
            <x-input wire:model="password" label="Password" type="password" />
            <x-slot:actions>
                <x-button type="submit">Login</x-button>
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
===END===

MINIMALISM PRINCIPLE: MaryUI components are pre-styled and beautiful. DO NOT wrap them in unnecessary divs with Tailwind utility classes. Use a simple <div> wrapper and let MaryUI handle styling.

REFERENCE EXAMPLE - Minimal Authentication (from https://dev.to/lancemdev/laravel-authentication-with-maryui-fmb):
<div>
    <x-button wire:click="openRegisterModal" label="Register"/>
    <x-button wire:click="openLoginModal" label="Login" />

    <x-modal wire:model="registerModal">
        <x-form wire:submit="registerUser">
            <x-input wire:model="name" label="Name" />
            <x-input wire:model="email" label="Email" type="email" />
            <x-input wire:model="password" label="Password" type="password" />
            <x-slot:actions>
                <x-button type="submit">Register</x-button>
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="loginModal">
        <x-form wire:submit="loginUser">
            <x-input wire:model="email" label="Email" type="email" />
            <x-input wire:model="password" label="Password" type="password" />
            <x-slot:actions>
                <x-button type="submit">Login</x-button>
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>

EXAMPLES OF HOLISTIC, PRODUCTION-READY BLADE VIEWS (MINIMAL APPROACH):

Complete Form with Validation Feedback:
<div>
        <x-card>
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

CRITICAL - MARYUI AUTOMATIC FEATURES:
- Toast trait: use Mary\Traits\Toast; then $this->success('Message') AUTOMATICALLY shows a toast - NO frontend code needed
- Validation errors: Livewire/MaryUI AUTOMATICALLY displays validation errors - DO NOT use @error() directives or manual <x-alert> components
- DO NOT add @error('field') <x-alert>...</x-alert> @enderror - this is redundant and wrong
- DO NOT use session()->has('success') or session('success') - toasts are automatic
- Just use clean form inputs - validation errors and toasts are handled automatically by the framework

Complete Data Table with Search and Actions:
<div>
        <x-card>
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
                <div>
                    <x-icon name="o-inbox" />
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
- Icons from Heroicons (using x-icon component with "o-" prefix for outline icons)
  CRITICAL: Only use icons that exist in Heroicons v2.1.5 (https://heroicons.com/)
  Common valid icons: o-users, o-cog-6-tooth, o-sparkles, o-power, o-trash, o-pencil, o-plus, o-magnifying-glass, o-inbox, o-paper-airplane
  For currency/money: use "o-currency-dollar" (NOT "o-dollar-sign"), "o-currency-euro", "o-currency-pound", etc.
  For shopping: use "o-shopping-cart" or "o-shopping-bag" (NOT "o-cart")
  For charts: use "o-chart-bar", "o-chart-pie" (NOT "o-chart-bar-square")
  Always verify icon names match exactly what's on https://heroicons.com/ - use the exact name with "o-" prefix
- Loading states with spinners on async actions (spinner="methodName" attribute)
- Confirmation dialogs for destructive actions (wire:confirm="Are you sure?")
- Holistic user experience - think about the complete flow, not just individual elements

ROUTING NOTE:
- Routes are automatically generated at /component-name (kebab-case)
- Design components to work standalone at their route
- Consider navigation between related components if applicable
- Use proper Livewire wire:model, wire:click, wire:submit for interactivity"""

    # Build messages array with conversation history
    messages_list = [
        {"role": "system", "content": system_message},
    ]
    
    # Add conversation history if provided (for conversational context)
    if messages:
        # Filter out system messages from history (we already have one)
        conversation_messages = [msg for msg in messages if msg.get("role") != "system"]
        messages_list.extend(conversation_messages)
    
    # Add the current prompt as the final user message
    messages_list.append({"role": "user", "content": prompt})
    
    completion = client.chat.completions.create(
        model=selected_model,
        temperature=temperature,
        max_tokens=max_tokens,
        messages=messages_list,
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
        
        # Remove any explanatory text from Blade part (common patterns)
        import re
        blade_part = re.sub(r'^(This code|This creates|The following|The code|This component|This form|This modal|This table|This dashboard)[^<]*?(\n|$)', '', blade_part, flags=re.IGNORECASE | re.MULTILINE)
        blade_part = re.sub(r'^(The modal|The form|The component|The table|The dashboard)[^<]*?(\n|$)', '', blade_part, flags=re.IGNORECASE | re.MULTILINE)
        # Remove paragraphs that are pure text (no HTML/Blade syntax) at the start
        blade_part = re.sub(r'^([A-Z][^<]*?\.)(\s*\n)', '', blade_part, flags=re.MULTILINE)
        
        php_code = php_part.strip()
        blade_code = blade_part.strip()
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
    # For follow-ups, prefer the existing component name
    if is_followup and existing_component_name:
        # Verify the extracted name matches, but prefer the existing one
        extracted_name = extract_component_name(prompt, php_code or combined_code)
        # If the code contains the existing component name, use it; otherwise use extracted
        if existing_component_name.lower() in (php_code or combined_code).lower():
            component_name = existing_component_name
        else:
            component_name = extracted_name
            # Log warning if names don't match
            if component_name != existing_component_name:
                import logging
                logging.warning(f"Component name mismatch: expected {existing_component_name}, got {component_name}. Using {component_name}.")
    else:
        component_name = extract_component_name(prompt, php_code or combined_code)
    
    return combined_code, component_name, intent_info


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

    # Extract feedback context from messages
    feedback_context = _extract_feedback_context(messages)
    
    # Enhance messages with GNN context
    enhanced_messages = messages.copy()
    if last_user_message:
        gnn_service = get_gnn_service()
        gnn_context = gnn_service.get_enhanced_context(last_user_message)
        
        # Build system message with GNN and feedback context
        feedback_instruction = ""
        if feedback_context:
            feedback_instruction = f"""

LEARNING FROM USER FEEDBACK:
{feedback_context}

Use this feedback to improve your responses. Learn from positive examples and avoid patterns that received negative feedback.
"""
        
        # Add system message with GNN context
        system_message = {
            "role": "system",
            "content": f"""You are Skylarr, an AI assistant that helps build dynamic Livewire frontends with MaryUI.
MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind.

{gnn_context}
{feedback_instruction}

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
