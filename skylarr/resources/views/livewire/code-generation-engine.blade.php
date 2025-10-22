<div class="h-full flex flex-col bg-white">
    {{-- Code Editor Panel --}}
    <div class="flex-1 flex flex-col">
        <div class="p-4 border-b border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-gray-900">Generated Code</h3>
                @if($componentName)
                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                        {{ $componentName }}
                    </span>
                @endif
            </div>
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
    <div class="flex-1 flex flex-col border-t border-gray-200">
        <div class="p-4 border-b border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-gray-900">Live Preview</h3>
                <div class="flex items-center gap-2">
                    @if($previewReady)
                        <div class="flex items-center gap-1">
                            <div class="w-2 h-2 bg-green-400 rounded-full"></div>
                            <span class="text-xs text-gray-600">Active</span>
                        </div>
                        <x-button 
                            wire:click="updatePreview" 
                            icon="o-arrow-path" 
                            class="btn-sm btn-ghost"
                            spinner="updatePreview">
                            Refresh
                        </x-button>
                        <x-button 
                            wire:click="stopPreview" 
                            icon="o-stop" 
                            class="btn-sm btn-ghost text-red-600"
                            spinner="stopPreview">
                            Stop
                        </x-button>
                    @else
                        <div class="flex items-center gap-1">
                            <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
                            <span class="text-xs text-gray-600">Inactive</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        
        <div class="flex-1 p-4">
            @if($previewReady && $previewUrl)
                <div class="h-full bg-white rounded-lg overflow-hidden shadow-lg border">
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
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto mb-4"></div>
                        <p class="text-sm text-gray-600">Generating code...</p>
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
