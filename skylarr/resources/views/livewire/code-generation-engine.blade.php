<div class="h-full flex flex-col">
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
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $activeTab === 'code' ? 'bg-white text-secondary-900 shadow-sm' : 'text-secondary-600 hover:text-secondary-900' }}">
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
            <div class="flex-1 flex p-4 gap-4" style="min-height: 0; overflow: hidden;">
                <div class="w-1/3 border-r border-gray-200 pr-4 overflow-y-auto" style="min-height: 0;">
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
                
                <div class="flex-1 flex flex-col code-viewer-container">
                    @if($generatedCode)
                        <div class="code-viewer-scrollable bg-gray-900 rounded-lg p-4">
                            <div class="mb-2 text-xs text-gray-400 sticky top-0 bg-gray-900 pb-2 z-10">
                                @if($selectedFilePath)
                                    <span>File: {{ basename($selectedFilePath) }}</span>
                                @elseif($componentName)
                                    <span>Generated: {{ $componentName }}</span>
                                @endif
                            </div>
                            <pre class="text-sm text-green-400 font-mono">{{ $generatedCode }}</pre>
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
                        <div class="flex items-center gap-3 flex-1">
                            @php
                                $routes = $currentProject ? $currentProject->getRoutes() : [];
                                $baseUrl = parse_url($previewUrl, PHP_URL_SCHEME) . '://' . parse_url($previewUrl, PHP_URL_HOST) . ':' . parse_url($previewUrl, PHP_URL_PORT);
                            @endphp
                            
                            @if(count($routes) > 0)
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-600">Route:</span>
                                    <select 
                                        wire:model.live="selectedRoute"
                                        x-data="{ baseUrl: '{{ $baseUrl }}' }"
                                        x-on:change="
                                            const newUrl = baseUrl + $event.target.value;
                                            $wire.set('previewUrl', newUrl);
                                            document.getElementById('preview-iframe').src = newUrl;
                                        "
                                        class="text-xs bg-white px-2 py-1 rounded border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        @foreach($routes as $route)
                                            <option value="{{ $route['url'] }}" {{ $selectedRoute === $route['url'] ? 'selected' : '' }}>
                                                @if($route['url'] === '/')
                                                    Home (/) - {{ $route['component'] }}
                                                @else
                                                    {{ $route['url'] }} - {{ $route['component'] }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-600">Preview URL:</span>
                                    <code class="text-xs bg-white px-2 py-1 rounded border">{{ $previewUrl }}</code>
                                </div>
                            @endif
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
        
        /* Ensure code viewer is scrollable */
        .code-viewer-container {
            display: flex;
            flex-direction: column;
            min-height: 0;
            height: 100%;
            overflow: hidden;
        }
        
        .code-viewer-scrollable {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .code-viewer-scrollable pre {
            margin: 0;
            padding: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
    </style>
    
    {{-- Overwrite Confirmation Modal --}}
    <x-modal wire:model="showOverwriteConfirmModal" wire:key="overwrite-modal-{{ $pendingComponentName }}" title="Overwrite Existing Component?">
        <div class="space-y-4">
            <p class="text-gray-700">
                The component <strong>{{ $pendingComponentName }}</strong> already exists. 
                Overwriting will replace the current code and create a backup version.
            </p>
            <p class="text-sm text-gray-500">
                Previous versions will be saved in the component's version history (up to 10 versions).
            </p>
            <div class="flex justify-end gap-2 pt-4">
                <x-button wire:click="cancelOverwrite" class="btn-ghost" wire:loading.attr="disabled">Cancel</x-button>
                <x-button wire:click="confirmOverwrite" class="btn-warning" wire:loading.attr="disabled" spinner="confirmOverwrite">
                    <x-icon name="o-exclamation-triangle" class="w-4 h-4 mr-2" />
                    Overwrite Component
                </x-button>
            </div>
        </div>
    </x-modal>
    
</div>
