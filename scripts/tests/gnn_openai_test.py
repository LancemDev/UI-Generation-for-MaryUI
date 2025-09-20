import pickle
import os
import openai

# Set your OpenAI API key (replace with your actual key)
openai.api_key = 'your-openai-api-key-here'

# Directory where pkl files are saved (e.g., from Colab or local)
save_dir = './maryui_gnn_data'  # Or '/content/drive/MyDrive/maryui_gnn_data' if in Colab

# Load pkl files
graph_file = os.path.join(save_dir, 'maryui_graph.pkl')
features_file = os.path.join(save_dir, 'node_features.pkl')
adj_file = os.path.join(save_dir, 'adj_matrix.pkl')
components_file = os.path.join(save_dir, 'components.pkl')
model_file = os.path.join(save_dir, 'gnn_model.pkl')

# Load data
with open(graph_file, 'rb') as f:
    G = pickle.load(f)
with open(components_file, 'rb') as f:
    components = pickle.load(f)
with open(features_file, 'rb') as f:
    X = pickle.load(f)
with open(adj_file, 'rb') as f:
    adj = pickle.load(f)
with open(model_file, 'rb') as f:
    model_state = pickle.load(f)

# Example: Load the trained GNN model (assuming you have the class defined)
import torch
import torch.nn.functional as F
from torch import nn

class GCNLayer(nn.Module):
    def __init__(self, in_features, out_features):
        super().__init__()
        self.linear = nn.Linear(in_features, out_features)

    def forward(self, X, adj):
        out = torch.matmul(adj, X)
        out = self.linear(out)
        return F.relu(out)

class EdgePredictionGNN(nn.Module):
    def __init__(self, in_features, hidden_features):
        super().__init__()
        self.gcn1 = GCNLayer(in_features, hidden_features)
        self.gcn2 = GCNLayer(hidden_features, hidden_features)
        self.fc = nn.Linear(hidden_features * 2, 1)

    def forward(self, X, adj, edge_indices):
        h = self.gcn1(X, adj)
        h = self.gcn2(h, adj)
        edge_features = torch.cat([h[edge_indices[:, 0]], h[edge_indices[:, 1]]], dim=1)
        return torch.sigmoid(self.fc(edge_features)).squeeze()

# Instantiate and load model
feature_dim = 16
hidden_features = 8
model = EdgePredictionGNN(feature_dim, hidden_features)
model.load_state_dict(model_state)
model.eval()

# Function to get graph relationships as text (to guide the prompt)
def get_graph_summary(G, components):
    summary = "MaryUI Component Relationships:\n"
    for parent in G.nodes:
        children = list(G.successors(parent))
        if children:
            summary += f"{parent} can contain: {', '.join(children)}\n"
    return summary

graph_summary = get_graph_summary(G, components)

# Function to predict if a relationship is valid using GNN
def predict_relationship(model, X, adj, component1, component2, component_to_idx):
    if component1 not in component_to_idx or component2 not in component_to_idx:
        return 0.0
    idx1 = component_to_idx[component1]
    idx2 = component_to_idx[component2]
    edge_indices = torch.tensor([[idx1, idx2]])
    with torch.no_grad():
        prob = model(X, adj, edge_indices).item()
    return prob

component_to_idx = {c: i for i, c in enumerate(components)}

# Example user query for code generation
user_query = "Generate a MaryUI form with an input and a button."

# Build prompt guided by pkl data
prompt = f"""
You are a MaryUI code generator. MaryUI is a Laravel Blade UI component library for Livewire, styled with daisyUI and Tailwind.

Based on the following component relationships from the graph:
{graph_summary}

And using GNN predictions for validity (e.g., probability that 'form' contains 'input': {predict_relationship(model, X, adj, 'form', 'input', component_to_idx):.2f})

Generate valid MaryUI Blade code for the query: {user_query}

Ensure nestings are correct per the relationships. Output only the code snippet.
"""

# Call OpenAI API to generate code
response = openai.ChatCompletion.create(
    model="gpt-4o",  # Or your preferred model, e.g., "gpt-3.5-turbo"
    messages=[
        {"role": "system", "content": "You are a helpful assistant for generating MaryUI code."},
        {"role": "user", "content": prompt}
    ]
)

generated_code = response['choices'][0]['message']['content']

print("Generated MaryUI Code:")
print(generated_code)