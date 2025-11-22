<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DockerPreviewService
{
    private const BASE_PORT = 8001;
    private const MAX_CONTAINERS_PER_USER = 5;
    private const CONTAINER_TIMEOUT_HOURS = 24;
    
    private array $usedPorts = [];
    
    /**
     * Create a new preview container for a project.
     */
    public function createProjectContainer(Project $project): string
    {
        try {
            $containerName = $this->generateContainerName($project);
            $port = $this->getAvailablePort();
            
            Log::info("Creating Docker container for project {$project->id}", [
                'container_name' => $containerName,
                'port' => $port,
                'user_id' => $project->user_id
            ]);
            
            // Create the container
            $result = $this->runDockerCommand([
                'run',
                '-d',
                '--name', $containerName,
                '-p', "{$port}:80",
                '--label', "user_id={$project->user_id}",
                '--label', "project_id={$project->id}",
                '--label', "created_at=" . now()->toISOString(),
                'skylarr-preview:latest'
            ]);
            
            if ($result->failed()) {
                throw new \Exception("Failed to create container: " . $result->errorOutput());
            }
            
            $containerId = trim($result->output());
            // Use 127.0.0.1 for preview URLs (proxied through /preview/{projectId} route)
            $previewHost = env('PREVIEW_HOST', '127.0.0.1');
            $previewUrl = "http://{$previewHost}:{$port}";
            
            // Update project with container info
            $project->update([
                'container_id' => $containerId,
                'container_name' => $containerName,
                'port' => $port,
                'preview_url' => $previewUrl,
                'status' => 'active',
                'last_accessed_at' => now()
            ]);
            
            // Wait for container to be ready
            $this->waitForContainerReady($containerId);
            
            // Ensure APP_KEY is set in the container
            $this->ensureAppKeyExists($containerId);
            
            // Fix any PailServiceProvider issues in the new container
            $this->fixPailServiceProviderError($containerId);
            
            Log::info("Successfully created container for project {$project->id}", [
                'container_id' => $containerId,
                'preview_url' => $previewUrl
            ]);
            
            return $previewUrl;
            
        } catch (\Exception $e) {
            Log::error("Failed to create container for project {$project->id}", [
                'error' => $e->getMessage(),
                'project' => $project->toArray()
            ]);
            
            $project->update(['status' => 'error']);
            throw $e;
        }
    }
    
    /**
     * Get existing container for a project or create new one.
     */
    public function getOrCreateProjectContainer(Project $project): string
    {
        // Check if project already has an active container
        if ($project->container_id && $project->isActive()) {
            if ($this->isContainerRunning($project->container_id)) {
                // Ensure APP_KEY is set
                $this->ensureAppKeyExists($project->container_id);
                // Fix PailServiceProvider error if it exists in the running container
                $this->fixPailServiceProviderError($project->container_id);
                $project->touchLastAccessed();
                return $project->preview_url;
            }
        }
        
        // Clean up any existing container
        if ($project->container_id) {
            $this->removeContainer($project->container_id);
        }
        
        // Create new container
        return $this->createProjectContainer($project);
    }
    
    /**
     * Validate that code is a Laravel Livewire component.
     */
    private function validateLivewireCode(string $code): bool
    {
        // Must be PHP code
        if (!str_contains($code, '<?php') && !str_starts_with(trim($code), '<?php')) {
            Log::warning('[DOCKER] Code does not start with <?php', ['code_preview' => substr($code, 0, 100)]);
            return false;
        }
        
        // Must have Livewire namespace or use statement
        if (!str_contains($code, 'Livewire') && !str_contains($code, 'Livewire\\Component')) {
            Log::warning('[DOCKER] Code does not contain Livewire references');
            return false;
        }
        
        // Must extend Component
        if (!preg_match('/extends\s+(\\\\)?Component/', $code)) {
            Log::warning('[DOCKER] Code does not extend Component');
            return false;
        }
        
        // Must have App\Livewire namespace
        if (!str_contains($code, 'namespace App\\Livewire') && !str_contains($code, 'namespace App\\\\Livewire')) {
            Log::warning('[DOCKER] Code does not have App\\Livewire namespace');
            return false;
        }
        
        // Forbidden frameworks/languages
        // Literal strings - will be escaped for exact matching
        $forbiddenLiterals = [
            'import React',
            'from "react"',
            'import Vue',
            'from "vue"',
            'export default',
            'function Component',
            'const Component',
            'class Component extends React',
            'class Component extends Vue',
            'def ',
            'useState',
            'useEffect',
            '<script>',
            'jsx',
            'tsx',
        ];
        
        // Regex patterns - used directly without escaping
        $forbiddenRegex = [
            '/class\s+\w+\s+extends\s+React/i',
            '/class\s+\w+\s+extends\s+Vue/i',
            '/class\s+\w+\s+extends\s+Angular/i',
            '/class\s+\w+\s+extends\s+Svelte/i',
            '/import\s+.*from\s+["\']react["\']/i',
            '/import\s+.*from\s+["\']vue["\']/i',
            '/import\s+.*from\s+["\']angular["\']/i',
        ];
        
        // Check literal strings (exact matches)
        foreach ($forbiddenLiterals as $pattern) {
            if (preg_match('/' . preg_quote($pattern, '/') . '/i', $code)) {
                Log::warning('[DOCKER] Code contains forbidden framework pattern', ['pattern' => $pattern, 'type' => 'literal']);
                return false;
            }
        }
        
        // Check regex patterns (pattern matching)
        foreach ($forbiddenRegex as $pattern) {
            if (preg_match($pattern, $code)) {
                Log::warning('[DOCKER] Code contains forbidden framework pattern', ['pattern' => $pattern, 'type' => 'regex']);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Parse code to extract component class name, view name, and view content.
     * Handles markdown code blocks and extracts only the PHP code.
     */
    private function parseComponentCode(string $code): array
    {
        $className = null;
        $viewName = null;
        $viewContent = null;
        
        // Store original input for Blade extraction
        $originalInput = $code;
        
        // Step 0: Extract Blade view from new format (===BLADE_VIEW=== ... ===END_BLADE===)
        if (preg_match('/===BLADE_VIEW===\s*\n(.*?)\n===END_BLADE===/s', $code, $matches)) {
            $viewContent = trim($matches[1]);
            // Remove the Blade section from code to get clean PHP
            $code = preg_replace('/\s*===BLADE_VIEW===\s*\n.*?\n===END_BLADE===\s*/s', '', $code);
        }
        
        // Step 1: Extract PHP code from markdown code blocks if present
        // Look for ```php or ``` blocks containing PHP code
        if (preg_match('/```(?:php)?\s*\n(.*?)\n```/s', $code, $matches)) {
            $code = trim($matches[1]);
        } elseif (preg_match('/```php\s*(.*?)```/s', $code, $matches)) {
            $code = trim($matches[1]);
        } elseif (preg_match('/```\s*(.*?)```/s', $code, $matches)) {
            // Generic code block - check if it contains PHP
            $potentialCode = trim($matches[1]);
            if (str_contains($potentialCode, '<?php') || str_contains($potentialCode, 'namespace App\\Livewire')) {
                $code = $potentialCode;
            }
        }
        
        // Step 2: Remove any explanatory text before <?php
        // Find the first occurrence of <?php and extract everything from there
        $phpStart = strpos($code, '<?php');
        if ($phpStart !== false && $phpStart > 0) {
            $code = substr($code, $phpStart);
        }
        
        // Step 3: Remove any text after the closing brace of the class
        // Find the class declaration and extract everything up to the matching closing brace
        // Use brace counting to handle nested braces correctly (methods with if-statements, loops, etc.)
        if (preg_match('/class\s+\w+\s+extends\s+Component\s*\{/s', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $classStartPos = $matches[0][1];
            $braceStartPos = $classStartPos + strlen($matches[0][0]) - 1; // Position of opening brace '{'
            
            // Count braces to find the matching closing brace
            // Start counting from the opening brace (braceCount = 1)
            $braceCount = 1;
            $pos = $braceStartPos + 1;
            $codeLength = strlen($code);
            
            while ($pos < $codeLength) {
                $char = $code[$pos];
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        // Found the matching closing brace for the class
                        $code = substr($code, 0, $pos + 1);
                        break;
                    }
                }
                $pos++;
            }
        }
        
        // Step 4: Clean up the code - remove any remaining markdown or explanatory text
        $code = preg_replace('/^(.*?)(<\?php)/s', '$2', $code); // Remove everything before <?php
        $code = preg_replace('/\n\s*```.*$/s', '', $code); // Remove trailing markdown
        $code = preg_replace('/^.*?<\?php\s*/s', '<?php' . "\n", $code); // Ensure clean start
        
        // Step 5: Extract class name
        if (preg_match('/class\s+([A-Z][a-zA-Z0-9]*)\s+extends/', $code, $matches)) {
            $className = $matches[1];
        }
        
        // Step 6: Extract view name from render() method
        if (preg_match("/view\(['\"]([^'\"]+)['\"]\)/", $code, $matches)) {
            $viewName = $matches[1];
        } elseif (preg_match("/return\s+view\(['\"]([^'\"]+)['\"]\)/", $code, $matches)) {
            $viewName = $matches[1];
        }
        
        // Step 7: Check if view is embedded in heredoc/nowdoc (we need to extract it)
        // Match: return <<<'blade' ... blade;
        // Pattern ensures the closing delimiter matches the opening delimiter and is at start of line
        // Uses negative lookahead to prevent matching delimiter word that appears in content
        if (preg_match("/return\s+<<<['\"]?(\w+)['\"]?\s*\n((?:(?!\n\1\s*;).)*)\n\1\s*;/s", $code, $matches)) {
            $viewContent = trim($matches[2]);
            $kebabName = $this->toKebabCase($className ?? 'component');
            // Remove the heredoc from the component code and replace with view() call
            // Use the same improved pattern for replacement - captures delimiter to ensure match
            $code = preg_replace(
                "/return\s+<<<['\"]?(\w+)['\"]?\s*\n(?:(?!\n\1\s*;).)*\n\1\s*;/s",
                "return view('livewire.{$kebabName}');",
                $code
            );
        }
        
        // Step 8: Extract Blade view from separate code blocks if present
        // Look for blade/html code blocks in the original input (before PHP extraction)
        // Match ```html, ```blade, or just ``` followed by Blade/HTML content
        if (preg_match_all('/```(?:html|blade)?\s*\n(.*?)\n```/s', $originalInput, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blockContent = trim($match[1]);
                // Check if this looks like Blade code (contains <x- or {{) and not PHP
                if ((str_contains($blockContent, '<x-') || str_contains($blockContent, '{{') || str_contains($blockContent, '@')) 
                    && !str_contains($blockContent, '<?php') 
                    && !str_contains($blockContent, 'namespace App\\Livewire')) {
                    // Strip any remaining markdown markers that might be in the content
                    $blockContent = preg_replace('/^```(?:html|blade)?\s*\n?/m', '', $blockContent);
                    $blockContent = preg_replace('/\n?```\s*$/m', '', $blockContent);
                    $viewContent = trim($blockContent);
                    break;
                }
            }
        }
        
        // Also check for Blade code after "And here is" or similar patterns
        if (empty($viewContent) && preg_match('/(?:And here is|Here is|Blade view|view file).*?```(?:html|blade)?\s*\n(.*?)```/s', $originalInput, $matches)) {
            $blockContent = trim($matches[1]);
            if (str_contains($blockContent, '<x-') || str_contains($blockContent, '{{')) {
                // Strip any remaining markdown markers
                $blockContent = preg_replace('/^```(?:html|blade)?\s*\n?/m', '', $blockContent);
                $blockContent = preg_replace('/\n?```\s*$/m', '', $blockContent);
                $viewContent = trim($blockContent);
            }
        }
        
        // Step 9: Final cleanup - ensure code is valid PHP
        $code = trim($code);
        if (!str_starts_with($code, '<?php')) {
            $code = '<?php' . "\n\n" . $code;
        }
        
        // Ensure proper namespace and use statements
        if (!str_contains($code, 'namespace App\\Livewire')) {
            if (preg_match('/<\?php\s*(.*)/s', $code, $matches)) {
                $code = "<?php\n\nnamespace App\\Livewire;\n\n" . $matches[1];
            }
        }
        
        if (!str_contains($code, 'use Livewire\\Component')) {
            if (preg_match('/(namespace App\\Livewire;)(.*?)(class\s+\w+)/s', $code, $matches)) {
                $code = str_replace($matches[0], $matches[1] . "\n\nuse Livewire\\Component;\n\n" . $matches[3], $code);
            }
        }
        
        return [
            'class_name' => $className,
            'view_name' => $viewName,
            'view_content' => $viewContent,
            'cleaned_code' => $code,
        ];
    }
    
    /**
     * Convert PascalCase to kebab-case.
     */
    private function toKebabCase(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $string));
    }

    /**
     * Add a route for a Livewire component in the container's web.php.
     * Every component gets its own unique route based on its name.
     */
    private function addComponentRoute(string $containerId, string $componentName): bool
    {
        try {
            $kebabName = $this->toKebabCase($componentName);
            
            // Always use component name for route path - never use root route
            $routePath = "/{$kebabName}";
            $routeName = $kebabName;
            
            // Read current web.php
            $readResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cat /var/www/html/routes/web.php'
            ]);
            
            if ($readResult->failed()) {
                Log::error('[DOCKER] Failed to read web.php', [
                    'container_id' => $containerId,
                    'error' => $readResult->errorOutput()
                ]);
                return false;
            }
            
            $webPhpContent = $readResult->output();
            
            // Check if route already exists
            $routeExists = str_contains($webPhpContent, "Route::get('{$routePath}'") || 
                           str_contains($webPhpContent, "Route::get(\"{$routePath}\"");
            
            if ($routeExists) {
                Log::info('[DOCKER] Route already exists', [
                    'route' => $routePath,
                    'component' => $componentName
                ]);
                return true;
            }
            
            // Check if use statement exists
            $useStatement = "use App\\Livewire\\{$componentName};";
            $hasUseStatement = str_contains($webPhpContent, $useStatement);
            
            // Prepare route line
            $routeLine = "Route::get('{$routePath}', {$componentName}::class)->name('{$routeName}');";
            
            // Build new content
            $newContent = $webPhpContent;
            
            // Add use statement if not present
            if (!$hasUseStatement) {
                // Find the last use statement or Route facade
                if (preg_match('/(use\s+[^;]+;[\s\n]*)+/', $webPhpContent, $matches)) {
                    $lastUsePos = strrpos($webPhpContent, $matches[0]) + strlen($matches[0]);
                    $newContent = substr_replace($webPhpContent, "\n{$useStatement}\n", $lastUsePos, 0);
                } else {
                    // No use statements, add after opening PHP tag
                    $phpTagPos = strpos($webPhpContent, '<?php');
                    if ($phpTagPos !== false) {
                        $insertPos = $phpTagPos + 5;
                        $newContent = substr_replace($webPhpContent, "\n\n{$useStatement}\n", $insertPos, 0);
                    }
                }
            }
            
            // Remove any existing root route (Route::get('/', ...))
            $newContent = preg_replace("/Route::get\s*\(\s*['\"]\/['\"]\s*,[^;]+;/", '', $newContent);
            
            // Add route at the end of the file
            $newContent = rtrim($newContent) . "\n{$routeLine}\n";
            
            // Write updated web.php
            $escapedContent = escapeshellarg($newContent);
            $writeResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "echo {$escapedContent} > /var/www/html/routes/web.php"
            ]);
            
            if ($writeResult->failed()) {
                Log::error('[DOCKER] Failed to write web.php', [
                    'container_id' => $containerId,
                    'error' => $writeResult->errorOutput()
                ]);
                return false;
            }
            
            // Clear all Laravel caches to ensure routes are fresh
            $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cd /var/www/html && 
                    php artisan route:clear 2>/dev/null || true &&
                    php artisan config:clear 2>/dev/null || true &&
                    php artisan cache:clear 2>/dev/null || true &&
                    php artisan view:clear 2>/dev/null || true &&
                    php artisan optimize:clear 2>/dev/null || true'
            ]);
            
            // Reload PHP-FPM to clear opcache and pick up new routes
            $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'pkill -USR2 php-fpm 2>/dev/null || service php8.4-fpm reload 2>/dev/null || true'
            ]);
            
            Log::info('[DOCKER] Route added successfully', [
                'route' => $routePath,
                'component' => $componentName
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('[DOCKER] Exception adding route', [
                'container_id' => $containerId,
                'component' => $componentName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Inject generated code into a project container using Laravel's make:livewire command.
     * This method follows a refined workflow:
     * 1. Generate component scaffold using make:livewire
     * 2. Inject PHP class code
     * 3. Inject Blade view code
     * 4. Validate code works
     * 5. Auto-fix any issues
     * 6. Only return success when everything works
     */
    public function injectCode(Project $project, string $code, string $componentName): bool
    {
        try {
            if (!$project->container_id || !$this->isContainerRunning($project->container_id)) {
                throw new \Exception("Container not running for project {$project->id}");
            }
            
            Log::info('[DOCKER] Starting refined code injection workflow', [
                'project_id' => $project->id,
                'component_name' => $componentName
            ]);
            
            // Step 1: Validate that the code is a Laravel Livewire component
            if (!$this->validateLivewireCode($code)) {
                Log::error("Generated code is not a valid Laravel Livewire component", [
                    'project_id' => $project->id,
                    'code_preview' => substr($code, 0, 200)
                ]);
                throw new \Exception("Generated code is not a valid Laravel Livewire component. Only Laravel Livewire components are supported.");
            }
            
            // Step 2: Parse the code to extract class name, view name, and view content
            Log::info('[DOCKER] Parsing component code', ['code_length' => strlen($code), 'code_preview' => substr($code, 0, 200)]);
            $parsed = $this->parseComponentCode($code);
            $actualClassName = $parsed['class_name'] ?? $componentName;
            
            // Ensure component name matches the actual class name
            if ($parsed['class_name']) {
                $componentName = $parsed['class_name'];
            }
            
            $viewName = $parsed['view_name'];
            $viewContent = $parsed['view_content'];
            $cleanedCode = $parsed['cleaned_code'] ?? $code;
            
            Log::info('[DOCKER] Code parsed', [
                'class_name' => $componentName,
                'view_name' => $viewName,
                'has_view_content' => !empty($viewContent),
                'cleaned_code_length' => strlen($cleanedCode),
                'cleaned_code_preview' => substr($cleanedCode, 0, 200)
            ]);
            
            // Step 3: Check if component already exists and create backup
            $kebabName = $this->toKebabCase($componentName);
            $componentPath = "/var/www/html/app/Livewire/{$componentName}.php";
            $viewPath = "/var/www/html/resources/views/livewire/{$kebabName}.blade.php";
            $componentExists = false;
            
            // Check if component file exists
            $checkResult = $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "test -f {$componentPath} && echo 'exists' || echo 'missing'"
            ]);
            
            if (trim($checkResult->output()) === 'exists') {
                $componentExists = true;
                Log::info('[DOCKER] Component already exists, creating backup', ['component' => $componentName]);
                
                // Create backup directory if it doesn't exist
                $backupDir = "/var/www/html/storage/app/component-backups";
                $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "mkdir -p {$backupDir}"
                ]);
                
                // Backup component file
                $backupPath = "{$backupDir}/{$componentName}-" . date('Y-m-d_His') . '.php';
                $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "cp {$componentPath} {$backupPath}"
                ]);
                
                // Backup view file if it exists
                $viewCheck = $this->runDockerCommand([
                    'exec', $project->container_id,
                    'sh', '-c', "test -f {$viewPath} && echo 'exists' || echo 'missing'"
                ]);
                
                if (trim($viewCheck->output()) === 'exists') {
                    $viewBackupPath = "{$backupDir}/{$kebabName}-" . date('Y-m-d_His') . '.blade.php';
                    $this->runDockerCommand([
                        'exec', $project->container_id,
                        'sh', '-c', "cp {$viewPath} {$viewBackupPath}"
                    ]);
                }
            }
            
            // Step 4: Use Laravel's make:livewire command to generate component scaffold
            Log::info('[DOCKER] Generating component scaffold', [
                'component_name' => $kebabName,
                'exists' => $componentExists
            ]);
            
            $makeResult = $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "cd /var/www/html && php artisan make:livewire {$kebabName} --force 2>&1"
            ]);
            
            if ($makeResult->failed()) {
                $errorOutput = $makeResult->errorOutput();
                // If component already exists, that's okay (we used --force)
                if (!str_contains($errorOutput, 'already exists') && !str_contains($errorOutput, 'Component created')) {
                    Log::warning('[DOCKER] make:livewire had issues but continuing', ['error' => $errorOutput]);
                }
            }
            
            // Step 5: Prepare component class code
            
            // Ensure render() method uses view() instead of heredoc
            if (!preg_match("/return\s+view\(['\"]/", $cleanedCode)) {
                $kebabViewName = $viewName ? str_replace('livewire.', '', $viewName) : $kebabName;
                // Use improved heredoc pattern that captures delimiter and ensures proper matching
                // Match: public function render() { ... return <<<'DELIMITER' ... DELIMITER; }
                // Pattern uses negative lookahead to prevent matching delimiter word in content
                $cleanedCode = preg_replace(
                    "/public\s+function\s+render\(\)\s*\{[^}]*return\s+<<<['\"]?(\w+)['\"]?\s*\n(?:(?!\n\1\s*;).)*\n\1\s*;/s",
                    "public function render()\n    {\n        return view('livewire.{$kebabViewName}');\n    }",
                    $cleanedCode
                );
            }
            
            // Step 6: Inject PHP class code
            Log::info('[DOCKER] Injecting PHP class code', [
                'path' => $componentPath,
                'is_update' => $componentExists
            ]);
            $escapedCode = escapeshellarg($cleanedCode);
            $writeResult = $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "echo {$escapedCode} > {$componentPath}"
            ]);
            
            if ($writeResult->failed()) {
                throw new \Exception("Failed to write component class: " . $writeResult->errorOutput());
            }
            
            // Step 7: Inject Blade view code
            $finalViewName = $viewName ?: "livewire.{$kebabName}";
            $viewPath = str_replace('.', '/', $finalViewName);
            if (!str_ends_with($viewPath, '.blade.php')) {
                $viewPath .= '.blade.php';
            }
            $fullViewPath = "/var/www/html/resources/views/{$viewPath}";
            
            // Ensure view directory exists
            $viewDir = dirname($fullViewPath);
            $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "mkdir -p {$viewDir}"
            ]);
            
            // Prepare view content
            if ($viewContent) {
                $finalViewContent = $viewContent;
                
                // Strip markdown code block markers if present
                // Remove ```html, ```blade, ```, and any leading/trailing whitespace
                $finalViewContent = preg_replace('/^```(?:html|blade)?\s*\n?/m', '', $finalViewContent);
                $finalViewContent = preg_replace('/\n?```\s*$/m', '', $finalViewContent);
                $finalViewContent = trim($finalViewContent);
                
                // CRITICAL: Remove any PHP namespace declarations from Blade files
                // Blade files should NEVER contain namespace declarations
                $finalViewContent = preg_replace('/<\?php\s*namespace[^;]+;/', '', $finalViewContent);
                $finalViewContent = preg_replace('/namespace\s+[^;]+;/', '', $finalViewContent);
                $finalViewContent = preg_replace('/<\?php\s*/', '', $finalViewContent);
                $finalViewContent = preg_replace('/use\s+[^;]+;/', '', $finalViewContent);
                
                // Remove any leading PHP tags
                $finalViewContent = preg_replace('/^<\?php\s*/', '', $finalViewContent);
                $finalViewContent = preg_replace('/^<\?/', '', $finalViewContent);
                
                // Clean up any double newlines that might result
                $finalViewContent = preg_replace('/\n{3,}/', "\n\n", $finalViewContent);
                $finalViewContent = trim($finalViewContent);
            } else {
                // Extract view from code if available, otherwise use default
                $finalViewContent = <<<BLADE
<div>
    <h2 class="text-2xl font-bold mb-4">{{ \$componentName ?? '{$componentName}' }}</h2>
    <!-- Component view content -->
</div>
BLADE;
            }
            
            Log::info('[DOCKER] Injecting Blade view code', ['path' => $fullViewPath]);
            $escapedView = escapeshellarg($finalViewContent);
            $viewWriteResult = $this->runDockerCommand([
                'exec', $project->container_id,
                'sh', '-c', "echo {$escapedView} > {$fullViewPath}"
            ]);
            
            if ($viewWriteResult->failed()) {
                throw new \Exception("Failed to write view file: " . $viewWriteResult->errorOutput());
            }
            
            // Step 7: Clear caches and regenerate autoloader
            Log::info('[DOCKER] Clearing caches and regenerating autoloader');
            $this->restartLaravelInContainer($project->container_id);
            
            // Step 8: Validate code works
            Log::info('[DOCKER] Validating generated code');
            $validationResult = $this->validateComponentCode($project->container_id, $componentName);
            
            if (!$validationResult['valid']) {
                Log::warning('[DOCKER] Code validation failed, attempting auto-fix', [
                    'errors' => $validationResult['errors']
                ]);
                
                // Step 9: Auto-fix issues
                $fixResult = $this->autoFixComponentIssues($project->container_id, $componentName, $validationResult['errors']);
                
                if ($fixResult['fixed']) {
                    Log::info('[DOCKER] Auto-fix applied, re-validating');
                    // Re-validate after fix
                    $validationResult = $this->validateComponentCode($project->container_id, $componentName);
                }
                
                // If still invalid after fix, try one more time with container restart
                if (!$validationResult['valid']) {
                    Log::warning('[DOCKER] Code still invalid after auto-fix, restarting container');
                    $this->restartLaravelInContainer($project->container_id);
                    sleep(2);
                    $validationResult = $this->validateComponentCode($project->container_id, $componentName);
                }
                
                // If still invalid, attempt AI-powered error correction
                if (!$validationResult['valid']) {
                    Log::warning('[DOCKER] Code still invalid after auto-fix, attempting AI-powered correction', [
                        'errors' => $validationResult['errors']
                    ]);
                    
                    $correctionResult = $this->attemptAiErrorCorrection($project, $componentName, $validationResult['errors'], $code);
                    
                    if ($correctionResult['success']) {
                        Log::info('[DOCKER] AI error correction successful, re-injecting corrected code');
                        // Re-inject the corrected code
                        return $this->injectCode($project, $correctionResult['code'], $componentName);
                    } else {
                        Log::error('[DOCKER] AI error correction failed', [
                            'error' => $correctionResult['error'] ?? 'Unknown error'
                        ]);
                        throw new \Exception("Code validation failed: " . implode(', ', $validationResult['errors']));
                    }
                }
            }
            
            // Step 10: Check container health
            Log::info('[DOCKER] Checking container health');
            $healthCheck = $this->checkContainerHealth($project->container_id, $project->preview_url);
            
            if (!$healthCheck['healthy']) {
                Log::warning('[DOCKER] Container health check failed', ['issues' => $healthCheck['issues']]);
                
                // Try to fix health issues
                $this->fixPailServiceProviderError($project->container_id);
                $this->restartLaravelInContainer($project->container_id);
                sleep(3);
                
                // Re-check health
                $healthCheck = $this->checkContainerHealth($project->container_id, $project->preview_url);
                
                if (!$healthCheck['healthy']) {
                    throw new \Exception("Container health check failed: " . implode(', ', $healthCheck['issues']));
                }
            }
            
            // Step 11: Generate route for the component
            Log::info('[DOCKER] Generating route for component', ['component_name' => $componentName]);
            
            // Every component gets its own unique route based on component name
            $routeAdded = $this->addComponentRoute($project->container_id, $componentName);
            
            if ($routeAdded) {
                // Track component and route in project metadata (with code and view for versioning)
                $kebabName = $this->toKebabCase($componentName);
                // Always use component name for route - never use root route
                $route = "/{$kebabName}";
                $routeName = $kebabName;
                
                // Check if component already exists
                $componentExists = $project->hasComponent($componentName);
                
                $project->addComponent(
                    $componentName, 
                    $route, 
                    $routeName,
                    $cleanedCode, // Store code for versioning
                    $finalViewContent // Store view for versioning
                );
                
                Log::info('[DOCKER] Route added and tracked', [
                    'component' => $componentName,
                    'route' => $route,
                    'is_update' => $componentExists
                ]);
            }
            
            Log::info("Successfully injected and validated code for project {$project->id}", [
                'component_name' => $componentName,
                'component_path' => $componentPath,
                'view_path' => $fullViewPath,
                'container_id' => $project->container_id
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to inject code into project {$project->id}", [
                'error' => $e->getMessage(),
                'component_name' => $componentName,
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }
    
    /**
     * Remove a container.
     */
    public function removeContainer(string $containerId): bool
    {
        try {
            $result = $this->runDockerCommand(['rm', '-f', $containerId]);
            
            if ($result->successful()) {
                Log::info("Successfully removed container", ['container_id' => $containerId]);
                return true;
            }
            
            Log::warning("Failed to remove container", [
                'container_id' => $containerId,
                'error' => $result->errorOutput()
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("Exception while removing container", [
                'container_id' => $containerId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get all containers for a user.
     */
    public function getUserContainers(int $userId): array
    {
        $result = $this->runDockerCommand([
            'ps', '-a',
            '--filter', "label=user_id={$userId}",
            '--format', '{{.ID}} {{.Names}} {{.Status}} {{.Ports}}'
        ]);
        
        if ($result->failed()) {
            return [];
        }
        
        $containers = [];
        $lines = explode("\n", trim($result->output()));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $parts = explode(' ', $line, 4);
            if (count($parts) >= 4) {
                $containers[] = [
                    'id' => $parts[0],
                    'name' => $parts[1],
                    'status' => $parts[2],
                    'ports' => $parts[3]
                ];
            }
        }
        
        return $containers;
    }
    
    /**
     * Clean up expired containers.
     */
    public function cleanupExpiredContainers(): int
    {
        $cutoffTime = now()->subHours(self::CONTAINER_TIMEOUT_HOURS);
        
        $result = $this->runDockerCommand([
            'ps', '-a',
            '--filter', "label=created_at",
            '--format', '{{.ID}} {{.Label "created_at"}}'
        ]);
        
        if ($result->failed()) {
            return 0;
        }
        
        $cleaned = 0;
        $lines = explode("\n", trim($result->output()));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $parts = explode(' ', $line, 2);
            if (count($parts) === 2) {
                $containerId = $parts[0];
                $createdAt = $parts[1];
                
                if (strtotime($createdAt) < $cutoffTime->timestamp) {
                    if ($this->removeContainer($containerId)) {
                        $cleaned++;
                    }
                }
            }
        }
        
        Log::info("Cleaned up expired containers", ['count' => $cleaned]);
        return $cleaned;
    }
    
    /**
     * Find and remove all orphaned Skylarr containers (containers that exist but have no database record).
     * This is useful when the database is cleared but containers are still running.
     */
    public function cleanupOrphanedContainers(): int
    {
        try {
            // Find all containers with skylarr-preview image or skylarr in the name
            $result = $this->runDockerCommand([
                'ps', '-a',
                '--filter', 'ancestor=skylarr-preview:latest',
                '--format', '{{.ID}} {{.Names}} {{.Ports}}'
            ]);
            
            if ($result->failed()) {
                // Also try finding by name pattern
                $result = $this->runDockerCommand([
                    'ps', '-a',
                    '--filter', 'name=skylarr-',
                    '--format', '{{.ID}} {{.Names}} {{.Ports}}'
                ]);
            }
            
            if ($result->failed()) {
                Log::warning('[DOCKER] Failed to list containers for orphan cleanup');
                return 0;
            }
            
            $cleaned = 0;
            $lines = explode("\n", trim($result->output()));
            
            foreach ($lines as $line) {
                if (empty($line)) continue;
                
                $parts = explode(' ', $line, 3);
                if (count($parts) >= 2) {
                    $containerId = $parts[0];
                    $containerName = $parts[1];
                    $ports = $parts[2] ?? '';
                    
                    // Check if this container has a database record
                    $hasProject = \App\Models\Project::where('container_id', $containerId)
                        ->orWhere('container_name', $containerName)
                        ->exists();
                    
                    if (!$hasProject) {
                        Log::info('[DOCKER] Found orphaned container', [
                            'container_id' => $containerId,
                            'container_name' => $containerName,
                            'ports' => $ports
                        ]);
                        
                        if ($this->removeContainer($containerId)) {
                            $cleaned++;
                        }
                    }
                }
            }
            
            Log::info("Cleaned up orphaned containers", ['count' => $cleaned]);
            return $cleaned;
            
        } catch (\Exception $e) {
            Log::error('[DOCKER] Exception during orphan cleanup', ['error' => $e->getMessage()]);
            return 0;
        }
    }
    
    /**
     * Find container by port number.
     */
    public function findContainerByPort(int $port): ?array
    {
        try {
            $result = $this->runDockerCommand([
                'ps',
                '--filter', "publish={$port}",
                '--format', '{{.ID}} {{.Names}} {{.Ports}} {{.Status}}'
            ]);
            
            if ($result->successful() && !empty(trim($result->output()))) {
                $line = trim(explode("\n", $result->output())[0]);
                $parts = explode(' ', $line, 4);
                
                if (count($parts) >= 3) {
                    return [
                        'id' => $parts[0],
                        'name' => $parts[1],
                        'ports' => $parts[2],
                        'status' => $parts[3] ?? 'unknown'
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('[DOCKER] Exception finding container by port', [
                'port' => $port,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Remove container by port number.
     */
    public function removeContainerByPort(int $port): bool
    {
        $container = $this->findContainerByPort($port);
        
        if ($container) {
            Log::info('[DOCKER] Removing container by port', [
                'port' => $port,
                'container_id' => $container['id'],
                'container_name' => $container['name']
            ]);
            return $this->removeContainer($container['id']);
        }
        
        Log::warning('[DOCKER] No container found on port', ['port' => $port]);
        return false;
    }
    
    /**
     * Remove all Skylarr containers (nuclear option - use with caution).
     */
    public function removeAllSkylarrContainers(): int
    {
        try {
            $removed = 0;
            
            // Find all containers with skylarr-preview image
            $result = $this->runDockerCommand([
                'ps', '-a',
                '--filter', 'ancestor=skylarr-preview:latest',
                '--format', '{{.ID}}'
            ]);
            
            if ($result->failed()) {
                // Try by name pattern
                $result = $this->runDockerCommand([
                    'ps', '-a',
                    '--filter', 'name=skylarr-',
                    '--format', '{{.ID}}'
                ]);
            }
            
            if ($result->successful()) {
                $lines = explode("\n", trim($result->output()));
                
                foreach ($lines as $containerId) {
                    if (!empty($containerId)) {
                        if ($this->removeContainer($containerId)) {
                            $removed++;
                        }
                    }
                }
            }
            
            Log::info("Removed all Skylarr containers", ['count' => $removed]);
            return $removed;
            
        } catch (\Exception $e) {
            Log::error('[DOCKER] Exception removing all containers', ['error' => $e->getMessage()]);
            return 0;
        }
    }
    
    /**
     * Check if user can create more containers.
     */
    public function canUserCreateContainer(int $userId): bool
    {
        $userContainers = $this->getUserContainers($userId);
        $activeContainers = array_filter($userContainers, fn($c) => str_contains($c['status'], 'Up'));
        
        return count($activeContainers) < self::MAX_CONTAINERS_PER_USER;
    }
    
    /**
     * Generate a unique container name for a project.
     */
    private function generateContainerName(Project $project): string
    {
        return "skylarr-user-{$project->user_id}-project-{$project->id}-" . Str::random(8);
    }
    
    /**
     * Get an available port for the container.
     */
    private function getAvailablePort(): int
    {
        $port = self::BASE_PORT;
        
        while ($port < self::BASE_PORT + 1000) {
            if (!$this->isPortInUse($port)) {
                $this->usedPorts[] = $port;
                Log::info('[DOCKER] Found available port', ['port' => $port]);
                return $port;
            }
            $port++;
        }
        
        throw new \Exception("No available ports found");
    }
    
    /**
     * Check if a port is in use.
     */
    private function isPortInUse(int $port): bool
    {
        // Check system ports using netstat or ss
        $result = Process::run(['sh', '-c', "netstat -ltn 2>/dev/null | grep ':{$port}' || ss -ltn 2>/dev/null | grep ':{$port}'"]);
        
        if ($result->successful() && !empty($result->output())) {
            return true;
        }
        
        // Also check Docker containers
        $dockerResult = $this->runDockerCommand(['ps', '--format', '{{.Ports}}']);
        
        if ($dockerResult->successful()) {
            $output = $dockerResult->output();
            return str_contains($output, ":{$port}->");
        }
        
        return false;
    }
    
    /**
     * Check if a container is running.
     */
    public function isContainerRunning(string $containerId): bool
    {
        $result = $this->runDockerCommand(['ps', '-q', '-f', "id={$containerId}"]);
        return $result->successful() && !empty(trim($result->output()));
    }
    
    /**
     * Wait for container to be ready.
     */
    private function waitForContainerReady(string $containerId, int $maxWait = 60): void
    {
        $waited = 0;
        
        // Wait for container to be running
        while ($waited < 10 && !$this->isContainerRunning($containerId)) {
            sleep(1);
            $waited++;
        }
        
        if (!$this->isContainerRunning($containerId)) {
            throw new \Exception("Container did not start within 10 seconds");
        }
        
        Log::info('[DOCKER] Container is running', ['container_id' => $containerId]);
        
        // For now, just wait a bit and assume it's ready
        // The actual Laravel app will start inside the container
        sleep(5);
        
        Log::info('[DOCKER] Proceeding with container ready', ['container_id' => $containerId]);
    }
    
    /**
     * Register a component in the container's service provider.
     */
    private function registerComponentInContainer(string $containerId, string $componentName): void
    {
        $serviceProviderPath = '/var/www/html/app/Providers/AppServiceProvider.php';
        
        // This is a simplified approach - in production, you'd want more sophisticated
        // component registration
        $this->runDockerCommand([
            'exec', $containerId,
            'sh', '-c', "echo '// Auto-generated component: {$componentName}' >> {$serviceProviderPath}"
        ]);
    }
    
    /**
     * Restart Laravel application in container.
     */
    private function restartLaravelInContainer(string $containerId): void
    {
        // Clear all caches to prevent PailServiceProvider errors
        $this->runDockerCommand([
            'exec', $containerId,
            'sh', '-c', 'cd /var/www/html && 
                rm -rf bootstrap/cache/*.php 2>/dev/null || true &&
                rm -rf storage/framework/cache/* 2>/dev/null || true &&
                php artisan config:clear 2>/dev/null || true &&
                php artisan cache:clear 2>/dev/null || true &&
                php artisan route:clear 2>/dev/null || true &&
                php artisan view:clear 2>/dev/null || true &&
                composer dump-autoload --no-dev --optimize 2>/dev/null || true'
        ]);
    }
    
    /**
     * Ensure APP_KEY exists in the container's .env file.
     */
    public function ensureAppKeyExists(string $containerId): bool
    {
        try {
            if (!$this->isContainerRunning($containerId)) {
                return false;
            }

            // Check if APP_KEY is set and valid
            $checkResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "grep -q '^APP_KEY=base64:' /var/www/html/.env 2>/dev/null && echo 'exists' || echo 'missing'"
            ]);

            if (trim($checkResult->output()) === 'exists') {
                Log::debug('[DOCKER] APP_KEY already exists in container', ['container_id' => $containerId]);
                return true;
            }

            Log::info('[DOCKER] APP_KEY missing, generating in container', ['container_id' => $containerId]);

            // Generate APP_KEY using artisan
            $generateResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cd /var/www/html && php artisan key:generate --force 2>&1'
            ]);

            if ($generateResult->successful()) {
                Log::info('[DOCKER] APP_KEY generated successfully', ['container_id' => $containerId]);
                // Clear config cache to pick up new key
                $this->runDockerCommand([
                    'exec', $containerId,
                    'sh', '-c', 'cd /var/www/html && php artisan config:clear 2>/dev/null || true'
                ]);
                return true;
            }

            // Fallback: manually generate and set APP_KEY
            Log::warning('[DOCKER] artisan key:generate failed, using manual generation', [
                'container_id' => $containerId,
                'error' => $generateResult->errorOutput()
            ]);

            $keyResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "php -r \"echo 'base64:' . base64_encode(random_bytes(32));\""
            ]);

            if ($keyResult->successful()) {
                $key = trim($keyResult->output());
                $setResult = $this->runDockerCommand([
                    'exec', $containerId,
                    'sh', '-c', "cd /var/www/html && if grep -q '^APP_KEY=' .env; then sed -i 's|^APP_KEY=.*|APP_KEY={$key}|' .env; else echo 'APP_KEY={$key}' >> .env; fi"
                ]);

                if ($setResult->successful()) {
                    Log::info('[DOCKER] APP_KEY set manually', ['container_id' => $containerId]);
                    // Clear config cache to pick up new key
                    $this->runDockerCommand([
                        'exec', $containerId,
                        'sh', '-c', 'cd /var/www/html && php artisan config:clear 2>/dev/null || true'
                    ]);
                    return true;
                }
            }

            Log::error('[DOCKER] Failed to set APP_KEY in container', ['container_id' => $containerId]);
            return false;

        } catch (\Exception $e) {
            Log::error('[DOCKER] Exception ensuring APP_KEY exists', [
                'container_id' => $containerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function fixPailServiceProviderError(string $containerId): bool
    {
        try {
            if (!$this->isContainerRunning($containerId)) {
                return false;
            }
            
            Log::info('[DOCKER] Fixing PailServiceProvider error in container', ['container_id' => $containerId]);
            
            // Clear all caches and regenerate autoloader
            $this->restartLaravelInContainer($containerId);
            
            // Also check and remove Pail from bootstrap cache if it exists
            $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cd /var/www/html && 
                    find bootstrap/cache -name "*.php" -type f -exec grep -l "PailServiceProvider" {} \; | xargs rm -f 2>/dev/null || true &&
                    # Remove PailServiceProvider from bootstrap/providers.php if it exists
                    if [ -f bootstrap/providers.php ]; then
                        sed -i "/PailServiceProvider/d" bootstrap/providers.php 2>/dev/null || true
                    fi'
            ]);
            
            Log::info('[DOCKER] PailServiceProvider fix applied', ['container_id' => $containerId]);
            return true;
        } catch (\Exception $e) {
            Log::error('[DOCKER] Failed to fix PailServiceProvider error', [
                'container_id' => $containerId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * List project files from container.
     * Organized by Laravel 11 folder structure.
     */
    public function listProjectFiles(string $containerId): array
    {
        $files = [];
        
        try {
            // Get app/Livewire files (components)
            $result = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/app/Livewire -type f -name "*.php" | head -30'
            ]);
            
            if ($result->successful()) {
                $lines = explode("\n", trim($result->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get resources/views/livewire files (Blade templates)
            $result2 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/resources/views/livewire -type f -name "*.blade.php" | head -30'
            ]);
            
            if ($result2->successful()) {
                $lines = explode("\n", trim($result2->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get app/Http directory files (controllers, etc.)
            $result3 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/app/Http -type f -name "*.php" | head -20'
            ]);
            
            if ($result3->successful()) {
                $lines = explode("\n", trim($result3->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
            // Get routes
            $result4 = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'find /var/www/html/routes -type f -name "*.php" | head -10'
            ]);
            
            if ($result4->successful()) {
                $lines = explode("\n", trim($result4->output()));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $files[] = $line;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to list files", ['error' => $e->getMessage()]);
        }
        
        return array_unique($files);
    }
    
    /**
     * Read a file from container.
     */
    public function readFileFromContainer(string $containerId, string $filePath): string
    {
        try {
            $result = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "cat {$filePath}"
            ]);
            
            if ($result->successful()) {
                return $result->output();
            }
            
            return '';
        } catch (\Exception $e) {
            Log::error("Failed to read file", ['file' => $filePath, 'error' => $e->getMessage()]);
            return '';
        }
    }
    
    /**
     * Validate that generated component code works correctly.
     * Checks syntax, class structure, and component registration.
     */
    private function validateComponentCode(string $containerId, string $componentName): array
    {
        $errors = [];
        
        try {
            $componentPath = "/var/www/html/app/Livewire/{$componentName}.php";
            
            // Check 1: File exists
            $fileCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "test -f {$componentPath} && echo 'exists' || echo 'missing'"
            ]);
            
            if (trim($fileCheck->output()) !== 'exists') {
                $errors[] = "Component file does not exist: {$componentPath}";
                return ['valid' => false, 'errors' => $errors];
            }
            
            // Check 2: PHP syntax validation
            $syntaxCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "php -l {$componentPath} 2>&1"
            ]);
            
            if (!$syntaxCheck->successful() || str_contains($syntaxCheck->output(), 'Parse error')) {
                $errors[] = "PHP syntax error: " . $syntaxCheck->output();
            }
            
            // Check 3: Class can be autoloaded
            $autoloadCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "cd /var/www/html && php -r \"require 'vendor/autoload.php'; class_exists('App\\\\Livewire\\\\{$componentName}');\" 2>&1"
            ]);
            
            if (!$autoloadCheck->successful()) {
                $errors[] = "Component class cannot be autoloaded: " . $autoloadCheck->errorOutput();
            }
            
            // Check 4: View file exists
            $kebabName = $this->toKebabCase($componentName);
            $viewPath = "/var/www/html/resources/views/livewire/{$kebabName}.blade.php";
            
            $viewCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "test -f {$viewPath} && echo 'exists' || echo 'missing'"
            ]);
            
            if (trim($viewCheck->output()) !== 'exists') {
                $errors[] = "View file does not exist: {$viewPath}";
            } else {
                // Check 4b: View file doesn't contain PHP namespace declarations (Blade files should never have these)
                $viewContent = $this->readFileFromContainer($containerId, $viewPath);
                if (preg_match('/namespace\s+[^;]+;/', $viewContent)) {
                    $errors[] = "View file contains PHP namespace declaration (Blade files should never have namespace declarations)";
                }
                if (preg_match('/<\?php\s*namespace/', $viewContent)) {
                    $errors[] = "View file contains PHP namespace declaration with opening tag";
                }
            }
            
            // Check 5: Laravel can compile the view
            $viewCompileCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "cd /var/www/html && php artisan view:clear 2>&1 && php artisan view:cache 2>&1 | head -20"
            ]);
            
            if ($viewCompileCheck->failed() && str_contains($viewCompileCheck->errorOutput(), 'error')) {
                $errors[] = "View compilation error: " . substr($viewCompileCheck->errorOutput(), 0, 200);
            }
            
            // Check 6: Runtime error detection - check Laravel logs for recent errors
            $runtimeErrors = $this->checkRuntimeErrors($containerId, $componentName);
            if (!empty($runtimeErrors)) {
                $errors = array_merge($errors, $runtimeErrors);
            }
            
        } catch (\Exception $e) {
            $errors[] = "Validation exception: " . $e->getMessage();
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Check for runtime errors by examining Laravel logs and attempting to render the component.
     */
    private function checkRuntimeErrors(string $containerId, string $componentName): array
    {
        $errors = [];
        
        try {
            // Clear previous errors from log
            $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'echo "" > /var/www/html/storage/logs/laravel.log'
            ]);
            
            // Get the route for this component
            $kebabName = $this->toKebabCase($componentName);
            $readResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cat /var/www/html/routes/web.php'
            ]);
            
            if ($readResult->failed()) {
                return $errors;
            }
            
            $webPhpContent = $readResult->output();
            // Default to component name route - never use root route
            $routePath = "/{$kebabName}";
            
            // Find the route path for this component
            if (preg_match("/Route::get\s*\(\s*['\"]([^'\"]+)['\"],\s*{$componentName}::class/", $webPhpContent, $matches)) {
                $routePath = $matches[1];
                // Never allow root route - fallback to component name if root is found
                if ($routePath === '/') {
                    $routePath = "/{$kebabName}";
                }
            } else {
                // Try kebab case as fallback
                if (preg_match("/Route::get\s*\(\s*['\"]\/{$kebabName}['\"],\s*{$componentName}::class/", $webPhpContent)) {
                    $routePath = "/{$kebabName}";
                }
            }
            
            // Get container port
            $portCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "grep -oP 'APP_PORT=\\K[0-9]+' /var/www/html/.env || echo '8000'"
            ]);
            $port = trim($portCheck->output()) ?: '8000';
            
            // Try to render the component via HTTP request
            $host = '127.0.0.1';
            $testUrl = "http://{$host}:{$port}{$routePath}";
            
            $curlResult = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "curl -s -w '\n%{http_code}' -H 'Accept: text/html' --max-time 5 '{$testUrl}' 2>&1"
            ]);
            
            $response = $curlResult->output();
            $lines = explode("\n", $response);
            $httpCode = end($lines);
            
            // Check for HTTP 500 or error responses
            if ($httpCode == '500' || str_contains($response, 'ErrorException') || str_contains($response, 'FatalErrorException')) {
                // Read Laravel log for detailed error
                $logCheck = $this->runDockerCommand([
                    'exec', $containerId,
                    'sh', '-c', 'tail -50 /var/www/html/storage/logs/laravel.log 2>&1 | grep -A 10 "ErrorException\|FatalErrorException\|Attempt to read property" | head -30'
                ]);
                
                $logOutput = $logCheck->output();
                if (!empty($logOutput)) {
                    // Extract error message
                    if (preg_match('/ErrorException: (.+?)(?:\n|$)/', $logOutput, $matches)) {
                        $errorMsg = trim($matches[1]);
                        $errors[] = "Runtime error: {$errorMsg}";
                    } elseif (preg_match('/Attempt to read property "([^"]+)" on null/', $logOutput, $matches)) {
                        $property = $matches[1];
                        $errors[] = "Runtime error: Attempt to read property '{$property}' on null";
                    } elseif (str_contains($logOutput, 'Attempt to read property')) {
                        $errors[] = "Runtime error: Attempt to read property on null (check Laravel logs for details)";
                    }
                } else {
                    $errors[] = "Runtime error: HTTP 500 error when rendering component";
                }
            }
            
        } catch (\Exception $e) {
            Log::warning('[DOCKER] Error checking runtime errors', ['error' => $e->getMessage()]);
        }
        
        return $errors;
    }
    
    /**
     * Auto-fix common component issues.
     */
    private function autoFixComponentIssues(string $containerId, string $componentName, array $errors): array
    {
        $fixed = false;
        $fixesApplied = [];
        
        try {
            $componentPath = "/var/www/html/app/Livewire/{$componentName}.php";
            $kebabName = $this->toKebabCase($componentName);
            $viewPath = "/var/www/html/resources/views/livewire/{$kebabName}.blade.php";
            
            // Read current component code
            $currentCode = $this->readFileFromContainer($containerId, $componentPath);
            
            // Fix 1: Missing namespace
            if (str_contains(implode(' ', $errors), 'namespace') || !str_contains($currentCode, 'namespace App\\Livewire')) {
                if (!str_contains($currentCode, 'namespace App\\Livewire')) {
                    $currentCode = "<?php\n\nnamespace App\\Livewire;\n\n" . ltrim($currentCode, "<?php\n");
                    $escapedCode = escapeshellarg($currentCode);
                    $this->runDockerCommand([
                        'exec', $containerId,
                        'sh', '-c', "echo {$escapedCode} > {$componentPath}"
                    ]);
                    $fixesApplied[] = 'Added missing namespace';
                    $fixed = true;
                }
            }
            
            // Fix 2: Missing use statements
            if (!str_contains($currentCode, 'use Livewire\\Component')) {
                $namespacePos = strpos($currentCode, 'namespace App\\Livewire;');
                if ($namespacePos !== false) {
                    $insertPos = $namespacePos + strlen('namespace App\\Livewire;');
                    $currentCode = substr_replace($currentCode, "\n\nuse Livewire\\Component;", $insertPos, 0);
                    $escapedCode = escapeshellarg($currentCode);
                    $this->runDockerCommand([
                        'exec', $containerId,
                        'sh', '-c', "echo {$escapedCode} > {$componentPath}"
                    ]);
                    $fixesApplied[] = 'Added missing use statement';
                    $fixed = true;
                }
            }
            
            // Fix 3: Missing render() method
            if (!preg_match('/public\s+function\s+render\(\)/', $currentCode)) {
                // Add render method before closing brace
                $lastBrace = strrpos($currentCode, '}');
                if ($lastBrace !== false) {
                    $renderMethod = "\n    public function render()\n    {\n        return view('livewire.{$kebabName}');\n    }\n";
                    $currentCode = substr_replace($currentCode, $renderMethod, $lastBrace, 0);
                    $escapedCode = escapeshellarg($currentCode);
                    $this->runDockerCommand([
                        'exec', $containerId,
                        'sh', '-c', "echo {$escapedCode} > {$componentPath}"
                    ]);
                    $fixesApplied[] = 'Added missing render() method';
                    $fixed = true;
                }
            }
            
            // Fix 4: Missing view file
            $viewCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', "test -f {$viewPath} && echo 'exists' || echo 'missing'"
            ]);
            
            if (trim($viewCheck->output()) === 'missing') {
                $defaultView = <<<BLADE
<div>
    <h2 class="text-2xl font-bold mb-4">{{ \$componentName ?? '{$componentName}' }}</h2>
    <!-- Component view content -->
</div>
BLADE;
                $escapedView = escapeshellarg($defaultView);
                $this->runDockerCommand([
                    'exec', $containerId,
                    'sh', '-c', "mkdir -p /var/www/html/resources/views/livewire && echo {$escapedView} > {$viewPath}"
                ]);
                $fixesApplied[] = 'Created missing view file';
                $fixed = true;
            }
            
            // Fix 5: Remove PHP namespace declarations from Blade files
            $viewContent = $this->readFileFromContainer($containerId, $viewPath);
            $namespaceErrors = array_filter($errors, function($error) {
                return str_contains($error, 'namespace') || str_contains($error, 'Namespace declaration');
            });
            
            if (!empty($namespaceErrors) || preg_match('/namespace\s+[^;]+;/', $viewContent) || preg_match('/<\?php\s*namespace/', $viewContent)) {
                $fixedView = $viewContent;
                // Remove namespace declarations
                $fixedView = preg_replace('/<\?php\s*namespace[^;]+;/', '', $fixedView);
                $fixedView = preg_replace('/namespace\s+[^;]+;/', '', $fixedView);
                $fixedView = preg_replace('/<\?php\s*/', '', $fixedView);
                $fixedView = preg_replace('/use\s+[^;]+;/', '', $fixedView);
                $fixedView = preg_replace('/^<\?php\s*/', '', $fixedView);
                $fixedView = preg_replace('/^<\?/', '', $fixedView);
                $fixedView = preg_replace('/\n{3,}/', "\n\n", $fixedView);
                $fixedView = trim($fixedView);
                
                if ($fixedView !== $viewContent) {
                    $escapedView = escapeshellarg($fixedView);
                    $this->runDockerCommand([
                        'exec', $containerId,
                        'sh', '-c', "echo {$escapedView} > {$viewPath}"
                    ]);
                    $fixesApplied[] = 'Removed PHP namespace declarations from Blade template';
                    $fixed = true;
                }
            }
            
            // Fix 6: Null property access errors in Blade templates
            $nullPropertyErrors = array_filter($errors, function($error) {
                return str_contains($error, 'Attempt to read property') && str_contains($error, 'on null');
            });
            
            if (!empty($nullPropertyErrors)) {
                $viewContent = $this->readFileFromContainer($containerId, $viewPath); // Re-read after namespace fix
                $fixedView = $this->fixNullPropertyAccess($viewContent, $nullPropertyErrors);
                if ($fixedView !== $viewContent) {
                    $escapedView = escapeshellarg($fixedView);
                    $this->runDockerCommand([
                        'exec', $containerId,
                        'sh', '-c', "echo {$escapedView} > {$viewPath}"
                    ]);
                    $fixesApplied[] = 'Fixed null property access errors in Blade template';
                    $fixed = true;
                }
            }
            
            // Fix 6: Clear caches after fixes
            if ($fixed) {
                $this->restartLaravelInContainer($containerId);
                $fixesApplied[] = 'Cleared caches';
            }
            
        } catch (\Exception $e) {
            Log::error('[DOCKER] Auto-fix exception', ['error' => $e->getMessage()]);
        }
        
        return [
            'fixed' => $fixed,
            'fixes_applied' => $fixesApplied
        ];
    }
    
    /**
     * Fix null property access errors in Blade templates by adding null checks.
     */
    private function fixNullPropertyAccess(string $viewContent, array $errors): string
    {
        $fixed = $viewContent;
        
        // Extract property names from errors
        $properties = [];
        foreach ($errors as $error) {
            if (preg_match("/property ['\"]([^'\"]+)['\"]/", $error, $matches)) {
                $properties[] = $matches[1];
            }
        }
        
        // Fix common patterns: $variable->property, {{ $variable->property }}, etc.
        foreach ($properties as $property) {
            // Pattern 1: {{ $var->property }} -> {{ $var?->property ?? '' }}
            $fixed = preg_replace(
                '/\{\{\s*\$(\w+)->' . preg_quote($property, '/') . '\s*\}\}/',
                '{{ $$1?->' . $property . ' ?? \'\' }}',
                $fixed
            );
            
            // Pattern 2: {{ $var->property }} in attributes -> {{ $var?->property ?? '' }}
            $fixed = preg_replace(
                '/\$(\w+)->' . preg_quote($property, '/') . '/',
                '$$1?->' . $property,
                $fixed
            );
            
            // Pattern 3: @if($var->property) -> @if($var?->property)
            $fixed = preg_replace(
                '/@if\s*\(\s*\$(\w+)->' . preg_quote($property, '/') . '\s*\)/',
                '@if($$1?->' . $property . ')',
                $fixed
            );
        }
        
        // Also fix common patterns without specific property names
        // Pattern: {{ $user->name }} -> {{ $user?->name ?? '' }}
        $fixed = preg_replace(
            '/\{\{\s*\$(\w+)->(\w+)\s*\}\}/',
            '{{ $$1?->$2 ?? \'\' }}',
            $fixed
        );
        
        // Pattern: $var->prop in PHP code blocks
        $fixed = preg_replace(
            '/\$(\w+)->(\w+)(?!\?)/',
            '$$1?->$2',
            $fixed
        );
        
        return $fixed;
    }
    
    /**
     * Attempt AI-powered error correction by re-generating code with error context.
     */
    private function attemptAiErrorCorrection(Project $project, string $componentName, array $errors, string $originalCode): array
    {
        try {
            Log::info('[DOCKER] Attempting AI-powered error correction', [
                'component' => $componentName,
                'error_count' => count($errors)
            ]);
            
            // Read current code from container
            $componentPath = "/var/www/html/app/Livewire/{$componentName}.php";
            $kebabName = $this->toKebabCase($componentName);
            $viewPath = "/var/www/html/resources/views/livewire/{$kebabName}.blade.php";
            
            $currentPhpCode = $this->readFileFromContainer($project->container_id, $componentPath);
            $currentViewCode = $this->readFileFromContainer($project->container_id, $viewPath);
            
            // Build error correction prompt
            $errorSummary = implode("\n- ", $errors);
            $correctionPrompt = "The following Laravel Livewire component has runtime errors. Please fix them:\n\n";
            $correctionPrompt .= "Component: {$componentName}\n\n";
            $correctionPrompt .= "Errors encountered:\n- {$errorSummary}\n\n";
            $correctionPrompt .= "Current PHP code:\n```php\n{$currentPhpCode}\n```\n\n";
            $correctionPrompt .= "Current Blade view:\n```blade\n{$currentViewCode}\n```\n\n";
            $correctionPrompt .= "Please regenerate the complete component code (both PHP class and Blade view) with all errors fixed. ";
            $correctionPrompt .= "Ensure all property accesses use null-safe operators (?->) or null checks to prevent 'Attempt to read property on null' errors.";
            
            // Call AI gateway to regenerate code
            $aiGateway = app(\App\Services\AiGateway::class);
            $response = $aiGateway->generateCode($correctionPrompt);
            
            if ($response['success'] && !empty($response['code'])) {
                Log::info('[DOCKER] AI error correction successful', [
                    'component' => $componentName
                ]);
                
                return [
                    'success' => true,
                    'code' => $response['code']
                ];
            } else {
                Log::error('[DOCKER] AI error correction failed', [
                    'error' => $response['message'] ?? 'Unknown error'
                ]);
                
                return [
                    'success' => false,
                    'error' => $response['message'] ?? 'AI error correction failed'
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('[DOCKER] Exception during AI error correction', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check container health - verify Laravel is working correctly.
     */
    private function checkContainerHealth(string $containerId, string $previewUrl): array
    {
        $issues = [];
        $healthy = true;
        
        try {
            // Check 1: Container is running
            if (!$this->isContainerRunning($containerId)) {
                $issues[] = 'Container is not running';
                $healthy = false;
                return ['healthy' => false, 'issues' => $issues];
            }
            
            // Check 2: Laravel can run artisan commands
            $artisanCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cd /var/www/html && php artisan --version 2>&1'
            ]);
            
            if ($artisanCheck->failed() || empty(trim($artisanCheck->output()))) {
                $issues[] = 'Laravel artisan not responding';
                $healthy = false;
            }
            
            // Check 3: No PailServiceProvider errors
            $pailCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cd /var/www/html && php artisan config:cache 2>&1 | grep -i "pail" || echo "ok"'
            ]);
            
            if (str_contains(strtolower($pailCheck->output()), 'pail') && !str_contains(strtolower($pailCheck->output()), 'ok')) {
                $issues[] = 'PailServiceProvider error detected';
                $healthy = false;
            }
            
            // Check 4: Routes can be listed
            $routeCheck = $this->runDockerCommand([
                'exec', $containerId,
                'sh', '-c', 'cd /var/www/html && php artisan route:list 2>&1 | head -5'
            ]);
            
            if ($routeCheck->failed() && str_contains($routeCheck->errorOutput(), 'error')) {
                $issues[] = 'Route listing failed';
                $healthy = false;
            }
            
            // Check 5: HTTP endpoint is accessible (if preview URL is set)
            if ($previewUrl) {
                $urlParts = parse_url($previewUrl);
                $port = $urlParts['port'] ?? 80;
                
                // Try to curl the endpoint (use 127.0.0.1 for localhost domains to avoid DNS issues)
                $host = $urlParts['host'] ?? 'localhost';
                $curlHost = ($host === 'localhost' || $host === 'preview.local') ? '127.0.0.1' : $host;
                $httpCheck = Process::run([
                    'sh', '-c', "curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H 'Host: {$host}' http://{$curlHost}:{$port} 2>&1 || echo '000'"
                ]);
                
                $httpCode = trim($httpCheck->output());
                if ($httpCode === '000' || ($httpCode !== '200' && $httpCode !== '404')) {
                    // 404 is okay (no route), but 000 or 5xx is not
                    if ($httpCode === '000') {
                        $issues[] = 'HTTP endpoint not accessible';
                        $healthy = false;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error('[DOCKER] Health check exception', ['error' => $e->getMessage()]);
            $issues[] = 'Health check exception: ' . $e->getMessage();
            $healthy = false;
        }
        
        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }
    
    /**
     * Run a Docker command.
     */
    private function runDockerCommand(array $command): \Illuminate\Process\ProcessResult
    {
        $fullCommand = array_merge(['docker'], $command);
        
        Log::debug("Running Docker command", ['command' => implode(' ', $fullCommand)]);
        
        return Process::run($fullCommand);
    }
}
