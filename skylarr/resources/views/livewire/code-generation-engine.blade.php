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
    <div class="flex-1 flex p-4 gap-4" style="min-height: 0;">
        {{-- Code Tab --}}
        @if($activeTab === 'code')
            <div class="w-1/3 border-r border-gray-200 pr-4 overflow-y-auto">
                <h4 class="font-semibold text-sm mb-2">Project Files</h4>
                @if(count($projectFiles) > 0)
                    <div class="space-y-1">
                        @foreach($projectFiles as $file)
                            <button 
                                wire:click="selectFile('{{ $file }}')" 
                                class="w-full text-left px-2 py-1 text-xs hover:bg-gray-100 rounded {{ $selectedFilePath === $file ? 'bg-blue-100 text-blue-800' : '' }}"
                                title="{{ $file }}">
                                {{ basename($file) }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-gray-400">No files found</p>
                @endif
            </div>
            
            <div class="flex-1 overflow-auto">
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
        @endif

        {{-- Preview Tab --}}
        @if($activeTab === 'preview')
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
</div>
