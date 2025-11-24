import networkx as nx
try:
    import torch
    import torch.nn.functional as F
    from torch import nn
    TORCH_AVAILABLE = True
except Exception:  # torch is optional
    torch = None  # type: ignore
    F = None      # type: ignore
    nn = None     # type: ignore
    TORCH_AVAILABLE = False
import numpy as np
import pickle
import os
from pathlib import Path


class GNNService:
    """Service to load MaryUI component graph and provide GNN-enhanced context for prompts."""

    def __init__(self):
        self.G = None
        self.components = None
        self.model = None
        self.X = None
        self.adj = None
        self._load_or_create_data()

    def _get_data_dir(self):
        """Get the data directory path relative to this file."""
        return Path(__file__).resolve().parents[1] / 'data' / 'maryui_gnn_data'

    def _load_or_create_data(self):
        """Load existing pickle files or create new graph data."""
        data_dir = self._get_data_dir()
        data_dir.mkdir(parents=True, exist_ok=True)

        graph_file = data_dir / 'maryui_graph.pkl'
        features_file = data_dir / 'node_features.pkl'
        adj_file = data_dir / 'adj_matrix.pkl'
        components_file = data_dir / 'components.pkl'

        # Try to load existing data
        if all(f.exists() for f in [graph_file, features_file, adj_file, components_file]):
            print("Loading existing GNN data...")
            with open(graph_file, 'rb') as f:
                self.G = pickle.load(f)
            with open(features_file, 'rb') as f:
                self.X = pickle.load(f)
            with open(adj_file, 'rb') as f:
                self.adj = pickle.load(f)
            with open(components_file, 'rb') as f:
                self.components = pickle.load(f)
        else:
            print("Creating new GNN data...")
            self._create_graph_data()
            self._save_data(graph_file, features_file, adj_file, components_file)

        # Initialize GNN model if torch is available
        if TORCH_AVAILABLE and self.X is not None:
            feature_dim = self.X.shape[1]
            self.model = GCNLayer(feature_dim, 8)
        else:
            self.model = None

    def _create_graph_data(self):
        """Create the MaryUI component graph and features."""
        # List components from MaryUI docs
        self.components = [
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
        self.G = nx.DiGraph()
        self.G.add_nodes_from(self.components)

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
        self.G.add_edges_from(edges)

        # Prepare data for GNN
        feature_dim = 16  # Arbitrary; could be doc-derived
        num_nodes = len(self.components)
        if TORCH_AVAILABLE:
            self.X = torch.rand(num_nodes, feature_dim)  # Random features
            adj_matrix = nx.adjacency_matrix(self.G).todense()
            self.adj = torch.tensor(adj_matrix, dtype=torch.float) + torch.eye(num_nodes)
        else:
            # Minimal placeholders when torch is not installed
            self.X = None
            self.adj = None

    def _save_data(self, graph_file, features_file, adj_file, components_file):
        """Save graph data to pickle files."""
        with open(graph_file, 'wb') as f:
            pickle.dump(self.G, f)
        # Only persist features/adjacency if available
        with open(features_file, 'wb') as f:
            pickle.dump(self.X, f)
        with open(adj_file, 'wb') as f:
            pickle.dump(self.adj, f)
        with open(components_file, 'wb') as f:
            pickle.dump(self.components, f)

    def get_graph_summary(self):
        """Generate a summary of component relationships for prompts."""
        if not self.G:
            return "No graph data available."

        summary = "MaryUI Component Relationships:\n"
        for parent in self.G.nodes:
            children = list(self.G.successors(parent))
            if children:
                summary += f"{parent} can contain: {', '.join(children)}\n"
        
        if not any(self.G.successors(n) for n in self.G.nodes):
            summary += "No relationships defined in the graph.\n"
        
        return summary

    def get_enhanced_context(self, user_message: str):
        """Generate GNN-enhanced context for a user message."""
        graph_summary = self.get_graph_summary()
        
        # Simple keyword matching to find relevant components
        relevant_components = []
        user_lower = user_message.lower()
        
        for component in self.components:
            if component.lower() in user_lower:
                relevant_components.append(component)
                # Add related components
                for successor in self.G.successors(component):
                    if successor not in relevant_components:
                        relevant_components.append(successor)
                for predecessor in self.G.predecessors(component):
                    if predecessor not in relevant_components:
                        relevant_components.append(predecessor)

        context = f"""
MaryUI Component Context:
{graph_summary}

Relevant components for your request: {', '.join(relevant_components) if relevant_components else 'None detected'}

Guidelines:
- Use <x-component> syntax (no maryui prefix)
- Follow parent-child relationships from the graph
- Ensure proper nesting based on component relationships
"""
        return context


if TORCH_AVAILABLE:
    class GCNLayer(nn.Module):
        """Simple Graph Convolutional Network layer."""
        
        def __init__(self, in_features, out_features):
            super().__init__()
            self.linear = nn.Linear(in_features, out_features)

        def forward(self, X, adj):
            out = torch.matmul(adj, X)
            out = self.linear(out)
            return F.relu(out)


# Global instance
_gnn_service = None


def get_gnn_service():
    """Get or create the global GNN service instance."""
    global _gnn_service
    if _gnn_service is None:
        _gnn_service = GNNService()
    return _gnn_service