<div class="h-full flex flex-col" x-data="" @generate-code.window="
    @this.handleGenerateCodeRequest($event.detail);
">
    {{-- Header with Toggle --}}
    <div class="p-4 border-b border-secondary-200">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <h3 class="text-sm font-medium text-gray-900">Code & Preview</h3>
                @if($componentName)
                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                        {{ $componentName }}
                    </span>
                @endif
                @if($isGenerating)
                    <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800 animate-pulse">
                        Generating...
                    </span>
                @endif
            </div>
            
            {{-- Toggle Buttons --}}
            <div class="flex items-center bg-secondary-100 rounded-lg p-1">
                <button 
                    wire:click="$set('activeTab', 'code')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $activeTab === 'code' ? 'shadow-sm' : 'hover:text-secondary-500' }}">
                    <x-icon name="o-code-bracket" class="w-4 h-4 mr-1" />
                    Code
                </button>
                <button 
                    wire:click="$set('activeTab', 'preview')"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $activeTab === 'preview' ? 'bg-white text-secondary-900 shadow-sm' : 'text-secondary-600 hover:text-secondary-900' }}">
                    <x-icon name="o-eye" class="w-4 h-4 mr-1" />
                    Preview
                </button>
            </div>
        </div>
    </div>

    {{-- Content Area --}}
    <div class="flex-1 flex" style="min-height: 0; height: 100%;">
        {{-- Code Tab --}}
        @if($activeTab === 'code')
            <div class="flex-1 flex p-4 gap-4" style="min-height: 0;">
                <div class="w-1/3 border-r border-gray-200 pr-4 overflow-y-auto">
                    <h4 class="font-semibold text-sm mb-2">Project Files</h4>
                    @if(count($projectFilesTree) > 0)
                        <div class="space-y-0.5">
                            @foreach($projectFilesTree as $key => $item)
                                @include('livewire.partials.file-tree-item', [
                                    'item' => $item,
                                    'key' => $key,
                                    'level' => 0,
                                    'selectedFilePath' => $selectedFilePath
                                ])
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-gray-400">No files found</p>
                    @endif
                </div>
                
                <div class="flex-1 overflow-auto">
                    @if($generatedCode)
                        <div class="h-full bg-gray-900 rounded-lg p-4 overflow-auto">
                            <div class="mb-2 text-xs text-gray-400">
                                @if($selectedFilePath)
                                    <span>File: {{ basename($selectedFilePath) }}</span>
                                @elseif($componentName)
                                    <span>Generated: {{ $componentName }}</span>
                                @endif
                            </div>
                            <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap break-words">{{ $generatedCode }}</pre>
                        </div>
                    @else
                        <div class="h-full flex items-center justify-center text-gray-400">
                            <div class="text-center">
                                <x-icon name="o-code-bracket" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                                <p class="text-sm">Generated code will appear here</p>
                                <p class="text-xs mt-2 opacity-75">Start a conversation to generate Livewire components</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Preview Tab --}}
        @if($activeTab === 'preview')
            @if($previewReady && $previewUrl)
                <div class="flex-1 flex flex-col" style="min-height: 0; height: 100%;">
                    {{-- Preview Controls Bar --}}
                    <div class="bg-gray-50 border-b border-gray-200 px-4 py-2 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-600">Preview URL:</span>
                            <code class="text-xs bg-white px-2 py-1 rounded border">{{ $previewUrl }}</code>
                        </div>
                        <a 
                            href="{{ $previewUrl }}" 
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="btn btn-sm btn-primary flex items-center gap-2">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open in New Window
                        </a>
                    </div>
                    
                    {{-- Iframe Error Banner (shown if blocked) --}}
                    <div id="iframe-error-banner" class="hidden bg-yellow-50 border-b border-yellow-200 px-4 py-3">
                        <div class="flex items-start gap-3">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" />
                            <div class="flex-1">
                                <p class="text-sm font-medium text-yellow-800 mb-1">Preview blocked by browser security settings</p>
                                <p class="text-xs text-yellow-700 mb-2">
                                    Zen browser is blocking localhost iframes. To fix this:
                                </p>
                                <ol class="text-xs text-yellow-700 list-decimal list-inside space-y-1 mb-2">
                                    <li>Open Zen browser settings: <code class="bg-yellow-100 px-1 rounded">zen://settings/security</code></li>
                                    <li>Find "X-Frame-Options" or "Frame Options" settings</li>
                                    <li>Add <code class="bg-yellow-100 px-1 rounded">localhost</code> to allowed origins, or disable blocking for localhost</li>
                                </ol>
                                <p class="text-xs text-yellow-700">
                                    Alternatively, click "Open in New Window" above to view the preview.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex-1 bg-white overflow-hidden relative" style="min-height: 0; height: 100%;">
                        <iframe 
                            id="preview-iframe"
                            src="{{ $previewUrl }}" 
                            class="w-full h-full border-0"
                            title="Live Preview"
                            style="width: 100%; height: 100%; min-height: 0;"
                            sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox">
                        </iframe>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const iframe = document.getElementById('preview-iframe');
                        const errorBanner = document.getElementById('iframe-error-banner');
                        
                        if (iframe && errorBanner) {
                            // Check if iframe loads successfully
                            iframe.onload = function() {
                                try {
                                    // Try to access iframe content (will fail if blocked)
                                    iframe.contentWindow.document;
                                    errorBanner.classList.add('hidden');
                                } catch (e) {
                                    // Iframe is blocked by browser
                                    errorBanner.classList.remove('hidden');
                                }
                            };
                            
                            // Fallback: show error banner after timeout if iframe doesn't load
                            setTimeout(function() {
                                try {
                                    iframe.contentWindow.document;
                                } catch (e) {
                                    errorBanner.classList.remove('hidden');
                                }
                            }, 2000);
                        }
                    });
                </script>
            @elseif($isGenerating)
                <div class="flex-1 flex items-center justify-center" style="min-height: 0;">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-4"></div>
                        <p class="text-sm text-gray-600">Generating code...</p>
                    </div>
                </div>
            @else
                <div class="flex-1 flex items-center justify-center text-gray-400" style="min-height: 0;">
                    <div class="text-center">
                        <x-icon name="o-eye" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                        <p class="text-sm">Live preview will appear here</p>
                        <p class="text-xs mt-2 opacity-75">Generate code to see the preview</p>
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- Status Bar --}}
    <div class="px-4 py-2 border-t border-gray-200 bg-gray-50 text-xs text-gray-600">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                @if($currentProject)
                    <span>Project: {{ $currentProject->name }}</span>
                    @if($currentProject->port)
                        <span>Port: {{ $currentProject->port }}</span>
                    @endif
                @endif
            </div>
            
            <div class="flex items-center gap-2">
                @if($previewReady)
                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                        <span>Preview Active</span>
                    </div>
                    <x-button 
                        wire:click="updatePreview" 
                        icon="o-arrow-path" 
                        class="btn-xs btn-ghost"
                        spinner="updatePreview">
                        Refresh
                    </x-button>
                    <x-button 
                        wire:click="stopPreview" 
                        icon="o-stop" 
                        class="btn-xs btn-ghost text-red-600"
                        spinner="stopPreview">
                        Stop
                    </x-button>
                @else
                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                        <span>No Preview</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
    
    <style>
        [x-cloak] { display: none !important; }
    </style>
</div>
