<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DockerPreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PreviewController extends Controller
{
    public function __construct(
        private DockerPreviewService $dockerService
    ) {}

    /**
     * Create a new preview for a project.
     */
    public function createPreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'code' => 'required|string',
            'component_name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($request->project_id);

            // Check if user can create more containers
            if (!$this->dockerService->canUserCreateContainer(Auth::id())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum number of containers reached'
                ], 429);
            }

            // Get or create container for the project
            $previewUrl = $this->dockerService->getOrCreateProjectContainer($project);

            // Inject the generated code
            $success = $this->dockerService->injectCode(
                $project,
                $request->code,
                $request->component_name
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to inject code into container'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'preview_url' => $previewUrl,
                'project_id' => $project->id,
                'component_name' => $request->component_name
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create preview: ' . $e->getMessage()
            ], 500);
        }
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
     * Update preview with new code.
     */
    public function updatePreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'code' => 'required|string',
            'component_name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($request->project_id);

            // Inject the updated code
            $success = $this->dockerService->injectCode(
                $project,
                $request->code,
                $request->component_name
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update preview'
                ], 500);
            }

            // Update last accessed time
            $project->touchLastAccessed();

            return response()->json([
                'success' => true,
                'message' => 'Preview updated successfully',
                'preview_url' => $project->preview_url
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update preview: ' . $e->getMessage()
            ], 500);
        }
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
                
                $project->update([
                    'container_id' => null,
                    'container_name' => null,
                    'port' => null,
                    'preview_url' => null,
                    'status' => 'stopped'
                ]);
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

    /**
     * Proxy requests to Docker container preview.
     * This allows iframes to work by serving content from the same origin.
     * 
     * Route: /preview/{projectId}/{path?}
     * Example: /preview/1/register-form -> proxies to http://127.0.0.1:8002/register-form
     */
    public function proxy(Request $request, int $projectId, string $path = ''): Response
    {
        try {
            // Verify user owns the project
            $project = Project::where('user_id', Auth::id())
                             ->findOrFail($projectId);

            // Check if container is running
            if (!$project->container_id || !$this->dockerService->isContainerRunning($project->container_id)) {
                abort(503, 'Preview container is not running');
            }

            // Get container port
            if (!$project->port) {
                abort(503, 'Container port not available');
            }

            // Build target URL (use 127.0.0.1 to avoid DNS issues)
            $targetUrl = "http://127.0.0.1:{$project->port}";
            
            // Add path if provided (ensure leading slash)
            if ($path) {
                $targetUrl .= '/' . ltrim($path, '/');
            }
            
            // Add query string if present
            if ($request->getQueryString()) {
                $targetUrl .= '?' . $request->getQueryString();
            }

            Log::info('[PROXY] Proxying request', [
                'project_id' => $projectId,
                'path' => $path,
                'target_url' => $targetUrl,
                'method' => $request->method()
            ]);

            // Prepare headers to forward (exclude some that shouldn't be forwarded)
            $headers = [];
            foreach ($request->headers->all() as $key => $values) {
                $lowerKey = strtolower($key);
                // Skip headers that shouldn't be forwarded
                if (!in_array($lowerKey, ['host', 'connection', 'content-length', 'transfer-encoding', 'expect'])) {
                    $headers[$key] = $values[0] ?? '';
                }
            }

            // Build HTTP client request
            $httpClient = Http::timeout(30)->withHeaders($headers);
            
            // Handle request body based on method
            $method = strtoupper($request->method());
            $body = $request->getContent();
            
            // Make the request to the container
            if ($method === 'GET') {
                $response = $httpClient->get($targetUrl);
            } elseif ($method === 'POST') {
                $response = $httpClient->withBody($body, $request->header('Content-Type', 'application/x-www-form-urlencoded'))
                    ->post($targetUrl);
            } elseif ($method === 'PUT') {
                $response = $httpClient->withBody($body, $request->header('Content-Type', 'application/x-www-form-urlencoded'))
                    ->put($targetUrl);
            } elseif ($method === 'PATCH') {
                $response = $httpClient->withBody($body, $request->header('Content-Type', 'application/x-www-form-urlencoded'))
                    ->patch($targetUrl);
            } elseif ($method === 'DELETE') {
                $response = $httpClient->delete($targetUrl);
            } else {
                // Fallback for other methods
                $response = $httpClient->withBody($body, $request->header('Content-Type', 'application/x-www-form-urlencoded'))
                    ->send($method, $targetUrl);
            }

            // Get response content
            $content = $response->body();
            $statusCode = $response->status();

            // Get response headers (exclude some that shouldn't be forwarded)
            $responseHeaders = [];
            foreach ($response->headers() as $key => $values) {
                $lowerKey = strtolower($key);
                // Forward most headers, but skip some
                // Also remove X-Frame-Options from original response (we'll set our own)
                if (!in_array($lowerKey, ['transfer-encoding', 'connection', 'content-encoding', 'x-frame-options'])) {
                    $responseHeaders[$key] = $values[0] ?? '';
                }
            }

            // Add CSP header to allow iframe embedding from any origin
            // Note: We don't set X-Frame-Options because it doesn't support "allow all"
            // Content-Security-Policy: frame-ancestors * is the modern way to allow all origins
            $responseHeaders['Content-Security-Policy'] = "frame-ancestors *;";

            // Create response with proper content type
            $contentType = $response->header('Content-Type') ?: 'text/html; charset=utf-8';
            
            return response($content, $statusCode)
                ->withHeaders($responseHeaders)
                ->header('Content-Type', $contentType);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404, 'Project not found');
        } catch (\Exception $e) {
            Log::error('[PROXY] Proxy error', [
                'project_id' => $projectId,
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            abort(502, 'Failed to proxy request: ' . $e->getMessage());
        }
    }
}
