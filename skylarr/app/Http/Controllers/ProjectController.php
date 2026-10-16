<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DockerPreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProjectController extends Controller
{
    public function __construct(
        private DockerPreviewService $dockerService
    ) {}

    /**
     * Get all projects for the authenticated user.
     */
    public function index(): JsonResponse
    {
        try {
            $projects = Project::where('user_id', Auth::id())
                              ->orderBy('last_accessed_at', 'desc')
                              ->orderBy('created_at', 'desc')
                              ->get();

            return response()->json([
                'success' => true,
                'projects' => $projects
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch projects: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new project.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if user can create more containers
            if (!$this->dockerService->canUserCreateContainer(Auth::id())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum number of projects reached'
                ], 429);
            }

            $project = Project::create([
                'user_id' => Auth::id(),
                'name' => $request->name,
                'description' => $request->description,
                'status' => 'creating',
                'metadata' => [
                    'components' => [],
                    'routes' => [],
                    'created_at' => now()->toISOString()
                ]
            ]);

            return response()->json([
                'success' => true,
                'project' => $project,
                'message' => 'Project created successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific project.
     */
    public function show(int $projectId): JsonResponse
    {
        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            return response()->json([
                'success' => true,
                'project' => $project
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }
    }

    /**
     * Update a project.
     */
    public function update(Request $request, int $projectId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            $project->update($request->only(['name', 'description']));

            return response()->json([
                'success' => true,
                'project' => $project,
                'message' => 'Project updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a project.
     */
    public function destroy(int $projectId): JsonResponse
    {
        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            // Remove associated container
            if ($project->container_id) {
                $this->dockerService->removeContainer($project->container_id);
            }

            $project->delete();

            return response()->json([
                'success' => true,
                'message' => 'Project deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete project: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initialize preview for a project.
     */
    public function initializePreview(int $projectId): JsonResponse
    {
        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            // Check if user can create more containers
            if (!$this->dockerService->canUserCreateContainer(Auth::id())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum number of containers reached'
                ], 429);
            }

            // Create container for the project
            $previewUrl = $this->dockerService->createProjectContainer($project);

            return response()->json([
                'success' => true,
                'preview_url' => $previewUrl,
                'project' => $project->fresh(),
                'message' => 'Preview initialized successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get project statistics.
     */
    public function getStats(int $projectId): JsonResponse
    {
        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            $stats = [
                'total_components' => count($project->metadata['components'] ?? []),
                'total_routes' => count($project->metadata['routes'] ?? []),
                'last_accessed' => $project->last_accessed_at,
                'created_at' => $project->created_at,
                'status' => $project->status,
                'is_active' => $project->isActive()
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get project stats: ' . $e->getMessage()
            ], 500);
        }
    }
}
