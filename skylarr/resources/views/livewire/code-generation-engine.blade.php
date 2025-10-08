<div class="h-full flex flex-col">
    {{-- Header with project info and controls --}}
    <div class="flex items-center justify-between p-4 border-b border-secondary/25 bg-white/5">
        <div class="flex items-center gap-4">
            <h2 class="text-lg font-semibold text-white">
                {{ $currentProject?->name ?? 'No Project' }}
            </h2>
            @if($currentProject)
                <span class="px-2 py-1 text-xs rounded-full 
                    {{ $currentProject->status === 'active' ? 'bg-green-500/20 text-green-300' : 'bg-gray-500/20 text-gray-300' }}">
                    {{ ucfirst($currentProject->status) }}
                </span>
            @endif
        </div>
        
        <div class="flex items-center gap-2">
            @if($previewReady)
                <x-button 
                    wire:click="updatePreview" 
                    icon="o-arrow-path" 
                    class="btn-sm btn-ghost text-white hover:bg-secondary/20"
                    spinner="updatePreview">
                    Refresh
                </x-button>
                <x-button 
                    wire:click="stopPreview" 
                    icon="o-stop" 
                    class="btn-sm btn-ghost text-red-300 hover:bg-red-500/20"
                    spinner="stopPreview">
                    Stop
                </x-button>
            @endif
        </div>
    </div>

    {{-- Main content area --}}
    <div class="flex-1 flex overflow-hidden">
        {{-- Code Editor Panel --}}
        <div class="w-1/2 border-r border-secondary/25 flex flex-col">
            <div class="p-4 border-b border-secondary/25 bg-white/5">
                <h3 class="text-sm font-medium text-white">Generated Code</h3>
                <p class="text-xs text-gray-300 mt-1">
                    @if($componentName)
                        Component: {{ $componentName }}
                    @else
                        No code generated yet
                    @endif
                </p>
            </div>
            
            <div class="flex-1 p-4">
                @if($generatedCode)
                    <div class="h-full bg-gray-900 rounded-lg p-4 overflow-auto">
                        <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap">{{ $generatedCode }}</pre>
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

        {{-- Preview Panel --}}
        <div class="w-1/2 flex flex-col">
            <div class="p-4 border-b border-secondary/25 bg-white/5">
                <h3 class="text-sm font-medium text-white">Live Preview</h3>
                <p class="text-xs text-gray-300 mt-1">
                    @if($previewReady)
                        Preview URL: {{ $previewUrl }}
                    @else
                        Preview will appear here
                    @endif
                </p>
            </div>
            
            <div class="flex-1 p-4">
                @if($previewReady && $previewUrl)
                    <div class="h-full bg-white rounded-lg overflow-hidden shadow-lg">
                        <iframe 
                            src="{{ $previewUrl }}" 
                            class="w-full h-full border-0"
                            title="Live Preview"
                            sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox">
                        </iframe>
                    </div>
                @elseif($isGenerating)
                    <div class="h-full flex items-center justify-center">
                        <div class="text-center">
                            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-secondary mx-auto mb-4"></div>
                            <p class="text-sm text-white">Generating code...</p>
                        </div>
                    </div>
                @else
                    <div class="h-full flex items-center justify-center text-gray-400">
                        <div class="text-center">
                            <x-icon name="o-eye" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                            <p class="text-sm">Live preview will appear here</p>
                            <p class="text-xs mt-2 opacity-75">Generate code to see the preview</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Status Bar --}}
    <div class="p-2 border-t border-secondary/25 bg-white/5 text-xs text-gray-300">
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
                @else
                    <div class="flex items-center gap-1">
                        <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                        <span>No Preview</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
