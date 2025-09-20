import networkx as nx
import torch
import torch.nn.functional as F
from torch import nn
import numpy as np
import pickle
import os
from google.colab import drive  # For Colab Google Drive integration

# Step 0: Mount Google Drive (Colab-specific, optional)
drive.mount('/content/drive')  # Mounts Google Drive at /content/drive/MyDrive
save_dir = '/content/drive/MyDrive/maryui_gnn_data'  # Directory to save .pkl files
os.makedirs(save_dir, exist_ok=True)  # Create directory if it doesn't exist

# Step 1: Define file paths for pickle files
graph_file = os.path.join(save_dir, 'maryui_graph.pkl')
features_file = os.path.join(save_dir, 'node_features.pkl')
adj_file = os.path.join(save_dir, 'adj_matrix.pkl')
components_file = os.path.join(save_dir, 'components.pkl')

# Step 2: Check if pickle files exist; load if available
def load_data():
    if all(os.path.exists(f) for f in [graph_file, features_file, adj_file, components_file]):
        print("Loading data from pickle files...")
        with open(graph_file, 'rb') as f:
            G = pickle.load(f)
        with open(features_file, 'rb') as f:
            X = pickle.load(f)
        with open(adj_file, 'rb') as f:
            adj = pickle.load(f)
        with open(components_file, 'rb') as f:
            components = pickle.load(f)
        return G, X, adj, components
    return None, None, None, None

# Step 3: Create or load data
G, X, adj, components = load_data()
if G is None:  # If pickle files don't exist, create the data
    print("Creating new graph and data...")
    # List components from MaryUI docs
    components = [
        # Form Components
        'form', 'input', 'label', 'select', 'checkbox', 'toggle', 'group', 'radio', 
        'textarea', 'colorpicker', 'choices', 'datetime', 'file', 'imagelibrary', 
        'range', 'tags',
        # List data
        'list-item', 'table',
        # Menus
        'menu', 'dropdown',
        # Dialogs
        'drawer', 'modal', 'toast',
        # UI
        'alert', 'avatar', 'breadcrumbs', 'button', 'badges', 'card', 'carousel', 
        'collapse', 'header', 'icon', 'kbd', 'pin', 'popover', 'progress', 'rating', 
        'spotlight', 'statistic', 'steps', 'swap', 'timeline', 'tabs', 'theme-toggle',
        # Slots
        'slot:actions', 'slot:figure', 'slot:menu',
        # Added from edges
        'link'
    ]

    # Create directed graph
    G = nx.DiGraph()
    G.add_nodes_from(components)

    # Inferred edges (from doc examples)
    edges = [
        # Form contains form elements
        ('form', 'input'), ('form', 'label'), ('form', 'checkbox'), ('form', 'toggle'), 
        ('form', 'group'), ('form', 'radio'), ('form', 'textarea'), ('form', 'colorpicker'), 
        ('form', 'choices'), ('form', 'datetime'), ('form', 'file'), ('form', 'imagelibrary'), 
        ('form', 'range'), ('form', 'tags'),
        # Wrapping
        ('modal', 'form'), ('form', 'slot:actions'), ('slot:actions', 'button'), 
        ('drawer', 'form'), ('drawer', 'menu'),
        # Menus and navigation
        ('menu', 'link'), ('dropdown', 'menu'), ('tabs', 'card'), ('collapse', 'header'),
    ]
    G.add_edges_from(edges)

    # Verify node consistency
    num_nodes = len(components)
    if len(G.nodes) != num_nodes:
        missing = set(G.nodes) - set(components)
        extra = set(components) - set(G.nodes)
        raise ValueError(f"Node mismatch! Graph has {len(G.nodes)} nodes, expected {num_nodes}. "
                         f"Missing from components: {missing}, Extra in components: {extra}")

    # Prepare data for GNN
    feature_dim = 16  # Arbitrary; could be doc-derived
    X = torch.rand(num_nodes, feature_dim)  # Random features
    adj = nx.adjacency_matrix(G).todense()
    adj = torch.tensor(adj, dtype=torch.float) + torch.eye(num_nodes)

    # Save to pickle files
    print("Saving data to pickle files...")
    with open(graph_file, 'wb') as f:
        pickle.dump(G, f)
    with open(features_file, 'wb') as f:
        pickle.dump(X, f)
    with open(adj_file, 'wb') as f:
        pickle.dump(adj, f)
    with open(components_file, 'wb') as f:
        pickle.dump(components, f)

# Step 4: Define simple GCN layer
class GCNLayer(nn.Module):
    def __init__(self, in_features, out_features):
        super().__init__()
        self.linear = nn.Linear(in_features, out_features)

    def forward(self, X, adj):
        out = torch.matmul(adj, X)
        out = self.linear(out)
        return F.relu(out)

# Step 5: Simulate GNN
model = GCNLayer(feature_dim, 8)  # Output dim 8 for reduced embeddings
output = model(X, adj)

# Results
print("Graph Summary:")
print(f"- Nodes (components): {len(components)}")
print(f"- Edges (relationships): {len(G.edges)}")
print("\nSample GNN Output (updated node embeddings):")
print(output[:5])  # First 5 nodes' embeddings (random each run)