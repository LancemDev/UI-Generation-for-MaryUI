<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DockerPreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PreviewController extends Controller
{
    public function __construct(
        private DockerPreviewService $dockerService
    ) {}

    /**
     * Write a component into a project's preview (starting the container if needed)
     * and report whether it renders.
     */
    public function createPreview(Request $request): JsonResponse
    {
        return $this->writeComponent($request);
    }

    /**
     * Get preview status for a project.
     */
    public function getPreviewStatus(int $projectId): JsonResponse
    {
        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            $isActive = $project->isActive() && 
                       $project->container_id && 
                       $this->dockerService->isContainerRunning($project->container_id);

            return response()->json([
                'success' => true,
                'project_id' => $project->id,
                'status' => $project->status,
                'is_active' => $isActive,
                'preview_url' => $project->preview_url,
                'last_accessed' => $project->last_accessed_at
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get preview status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Replace a component's files in a running preview.
     */
    public function updatePreview(Request $request): JsonResponse
    {
        return $this->writeComponent($request);
    }

    private function writeComponent(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'project_id' => 'required|integer',
            'component_name' => ['required', 'string', 'regex:' . DockerPreviewService::COMPONENT_NAME_PATTERN],
            'php' => 'required|string',
            'blade' => 'required|string',
        ])->validate();

        $project = Project::where('user_id', Auth::id())->findOrFail($validated['project_id']);

        try {
            $previewUrl = $this->dockerService->getOrCreateProjectContainer($project);
            $this->dockerService->writeComponent($project, $validated['component_name'], $validated['php'], $validated['blade']);
            $errors = $this->dockerService->validateComponent($project, $validated['component_name']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        $kebab = DockerPreviewService::kebab($validated['component_name']);
        $project->addComponent($validated['component_name'], "/{$kebab}", $kebab, $validated['php'], $validated['blade']);

        return response()->json([
            'success' => $errors === [],
            'preview_url' => "{$previewUrl}/{$kebab}",
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Stop a preview container.
     */
    public function stopPreview(int $projectId): JsonResponse
    {
        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            if ($project->container_id) {
                $this->dockerService->removeContainer($project->container_id);
                $this->dockerService->detach($project);
            }

            return response()->json([
                'success' => true,
                'message' => 'Preview stopped successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to stop preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all user containers.
     */
    public function getUserContainers(): JsonResponse
    {
        try {
            $containers = $this->dockerService->getUserContainers(Auth::id());

            return response()->json([
                'success' => true,
                'containers' => $containers,
                'can_create_more' => $this->dockerService->canUserCreateContainer(Auth::id())
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get containers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clean up expired containers (admin endpoint).
     */
    public function cleanupExpiredContainers(): JsonResponse
    {
        try {
            $cleaned = $this->dockerService->cleanupExpiredContainers();

            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$cleaned} expired containers"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup containers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clean up orphaned containers (containers without database records).
     */
    public function cleanupOrphanedContainers(): JsonResponse
    {
        try {
            $cleaned = $this->dockerService->cleanupOrphanedContainers();

            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$cleaned} orphaned containers"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup orphaned containers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove container by port number.
     */
    public function removeContainerByPort(int $port): JsonResponse
    {
        try {
            $removed = $this->dockerService->removeContainerByPort($port);

            if ($removed) {
                return response()->json([
                    'success' => true,
                    'message' => "Container on port {$port} removed successfully"
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "No container found on port {$port}"
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove container: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove all Skylarr containers (nuclear option).
     */
    public function removeAllContainers(): JsonResponse
    {
        try {
            $removed = $this->dockerService->removeAllSkylarrContainers();

            return response()->json([
                'success' => true,
                'message' => "Removed {$removed} Skylarr containers"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove containers: ' . $e->getMessage()
            ], 500);
        }
    }
}
