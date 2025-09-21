import pickle
import os
from openai import OpenAI

client = OpenAI(api_key='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')
import dotenv
dotenv.load_dotenv()  # Load environment variables from .env file

# Set OpenAI API key (replace with your actual key)

# Directory where pkl file is stored locally
save_dir = 'data/maryui_gnn_data' 

# Load pkl file (only maryui_graph.pkl needed)
graph_file = os.path.join(save_dir, 'maryui_graph.pkl')

try:
    with open(graph_file, 'rb') as f:
        G = pickle.load(f)
except FileNotFoundError:
    print(f"Error: Missing {graph_file}. Ensure it is in {save_dir}")
    exit(1)

# Extract components from graph nodes
components = list(G.nodes)
if not components:
    print("Error: Graph contains no nodes. Check {graph_file}")
    exit(1)

# Function to summarize graph relationships for prompt
def get_graph_summary(G):
    summary = "MaryUI Component Relationships:\n"
    for parent in G.nodes:
        children = list(G.successors(parent))
        if children:
            summary += f"{parent} can contain: {', '.join(children)}\n"
    if not any(G.successors(n) for n in G.nodes):
        summary += "No relationships defined in the graph.\n"
    return summary

graph_summary = get_graph_summary(G)

# User query for code generation
user_query = "Generate a MaryUI form with an input and a button."

# Build prompt for OpenAI
prompt = f"""
You are a MaryUI code generator. MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind.
Based on the following component relationships from the MaryUI documentation:
{graph_summary}
Generate valid MaryUI Blade code for the query: {user_query}
Ensure nestings follow the relationships (e.g., only use parent-child pairs listed).
Do not add a maryui prefix to component names but maintain the <x-component> structure
Output only the code snippet.
"""

# Call OpenAI API
try:
    response = client.chat.completions.create(
        model="gpt-4o",
        messages=[
            {"role": "system", "content": "You are a helpful assistant for generating MaryUI code."},
            {"role": "user", "content": prompt}
        ],
        max_tokens=150,
        temperature=0.3
    )
    generated_code = response.choices[0].message.content.strip()
except Exception as e:
    print(f"OpenAI API error: {e}")
    exit(1)

# Print result
print("Generated MaryUI Code:")
print(generated_code)