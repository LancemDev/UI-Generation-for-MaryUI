<div class="h-full flex flex-col" wire:poll.2s="checkGenerationStatus">
    {{-- Header with Toggle and Controls --}}
    <div class="px-4 py-2 border-b border-secondary-200">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4 flex-1">
                {{-- Toggle Buttons --}}
                <div class="flex items-center bg-secondary-100 rounded-lg p-1">
                    <button 
                        wire:click="$set('activeTab', 'code')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $activeTab === 'code' ? 'bg-white text-secondary-900 shadow-sm' : 'text-secondary-600 hover:text-secondary-900' }}">
                        <x-icon name="o-code-bracket" class="w-4 h-4" />
                    </button>
                    <button 
                        wire:click="$set('activeTab', 'preview')"
                        class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $activeTab === 'preview' ? 'bg-white text-secondary-900 shadow-sm' : 'text-secondary-600 hover:text-secondary-900' }}">
                        <x-icon name="o-eye" class="w-4 h-4" />
                    </button>
                </div>
                
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
                
                {{-- Preview Controls (only show when preview is active) --}}
                @if($activeTab === 'preview' && $previewReady && $previewUrl)
                    @php
                        $routes = $currentProject ? $currentProject->getRoutes() : [];
                        // Format routes for MaryUI select component
                        $formattedRoutesArray = [];
                        if (is_array($routes) && count($routes) > 0) {
                            foreach ($routes as $route) {
                                if (isset($route['url']) && isset($route['component'])) {
                                    $label = $route['url'] === '/' 
                                        ? "Home (/) - {$route['component']}"
                                        : "{$route['url']} - {$route['component']}";
                                    $formattedRoutesArray[] = [
                                        'id' => $route['url'],
                                        'name' => $label,
                                    ];
                                }
                            }
                        }
                    @endphp
                    
                    @if(count($formattedRoutesArray) > 0)
                        <x-select 
                            wire:model.live="selectedRoute" 
                            :options="$formattedRoutesArray"
                            icon="o-map-pin"
                            inline
                            class="text-xs"
                        />
                    @endif
                    
                    {{-- Theme Selector Dropdown --}}
                    <div class="relative inline-block">
                        <x-dropdown 
                            :label="collect($availableThemes)->firstWhere('id', $selectedTheme)['name'] ?? ucfirst($selectedTheme ?? 'light')"
                            icon="o-paint-brush"
                            class="btn-xs btn-ghost theme-dropdown"
                            scroll
                            no-x-anchor
                            wire:key="theme-dropdown-{{ $selectedTheme }}"
                        >
                        @foreach($availableThemes as $theme)
                            @php
                                $isSelected = $selectedTheme === $theme['id'];
                                $isSelecting = $selectingTheme === $theme['id'];
                            @endphp
                            @if($isSelecting)
                                <x-menu-item 
                                    :title="$theme['name']"
                                    icon="o-check-circle"
                                    :class="$isSelected ? 'text-primary font-semibold' : ''"
                                    wire:click.stop="selectTheme('{{ $theme['id'] }}')"
                                    spinner="selectTheme"
                                />
                            @elseif($isSelected)
                                <x-menu-item 
                                    :title="$theme['name']"
                                    icon="o-check-circle"
                                    class="text-primary font-semibold"
                                    wire:click.stop="selectTheme('{{ $theme['id'] }}')"
                                />
                            @else
                                <x-menu-item 
                                    :title="$theme['name']"
                                    wire:click.stop="selectTheme('{{ $theme['id'] }}')"
                                />
                            @endif
                        @endforeach
                        </x-dropdown>
                    </div>
                    
                    {{-- Open in New Window --}}
                    <a 
                        href="{{ $previewUrl }}" 
                        target="_blank" 
                        rel="noopener noreferrer"
                        class="btn btn-xs btn-primary flex items-center gap-1">
                        <x-icon name="o-arrow-top-right-on-square" class="w-3 h-3" />
                        <span class="hidden sm:inline">Open</span>
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Content Area --}}
    <div class="flex-1 flex" style="min-height: 0; height: 100%;" wire:key="content-area-{{ $componentName }}-{{ $isGenerating }}">
        {{-- Code Tab --}}
        @if($activeTab === 'code')
            <div class="flex-1 flex p-4 gap-4" style="min-height: 0; overflow: hidden;" wire:key="code-tab-{{ $selectedFilePath }}-{{ $componentName }}">
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
                
                <div class="flex-1 flex flex-col code-viewer-container" style="min-height: 0; height: 100%; overflow: hidden;" wire:key="code-viewer-{{ $componentName }}-{{ $selectedFilePath }}-{{ $isGenerating }}">
                    @if($isGenerating)
                        {{-- Code Generation Loader --}}
                        <div class="h-full flex items-center justify-center bg-gray-900 rounded-lg relative overflow-hidden">
                            {{-- Animated background pattern --}}
                            <div class="absolute inset-0 opacity-10">
                                <div class="absolute inset-0" style="background-image: repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,0.05) 10px, rgba(255,255,255,0.05) 20px);"></div>
                            </div>
                            
                            <div class="relative z-10 text-center">
                                {{-- Animated code icon --}}
                                <div class="mb-6 flex justify-center">
                                    <div class="relative">
                                        <x-icon name="o-code-bracket" class="w-16 h-16 text-green-400 animate-pulse" />
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <x-icon name="o-arrow-path" class="w-8 h-8 text-green-500 animate-spin" />
                                        </div>
                                    </div>
                                </div>
                                
                                {{-- Loading text with animation --}}
                                <h3 class="text-xl font-semibold text-green-400 mb-2">
                                    @if($generationStatus === 'generating')
                                        Generating Code...
                                    @elseif($generationStatus === 'validating')
                                        Validating & Injecting Code...
                                    @elseif($generationStatus === 'debugging')
                                        Debugging Issues...
                                    @elseif($generationStatus === 'fixing')
                                        Fixing Bugs Automatically...
                                    @else
                                        Processing...
                                    @endif
                                </h3>
                                <p class="text-sm text-gray-400 mb-4">
                                    @if($generationStatus === 'generating')
                                        Creating your Livewire component with AI
                                    @elseif($generationStatus === 'validating')
                                        Checking code and setting up preview
                                    @elseif($generationStatus === 'debugging')
                                        Analyzing errors and preparing fixes
                                    @elseif($generationStatus === 'fixing')
                                        Auto-fixing detected issues
                                    @else
                                        Working on your component
                                    @endif
                                </p>
                                
                                {{-- Animated dots --}}
                                <div class="flex justify-center gap-1">
                                    <div class="w-2 h-2 bg-green-400 rounded-full animate-bounce" style="animation-delay: 0s;"></div>
                                    <div class="w-2 h-2 bg-green-400 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
                                    <div class="w-2 h-2 bg-green-400 rounded-full animate-bounce" style="animation-delay: 0.4s;"></div>
                                </div>
                                
                                {{-- Progress indicator --}}
                                <div class="mt-6 w-64 mx-auto">
                                    <div class="h-1 bg-gray-700 rounded-full overflow-hidden">
                                        <div class="h-full bg-green-400 rounded-full animate-pulse" style="width: 60%; animation: progress 2s ease-in-out infinite;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @elseif(empty($generatedCode) && !$previewReady)
                        {{-- Initial State - Encourage Prompt --}}
                        <div class="h-full flex items-center justify-center bg-gray-900 rounded-lg">
                            <div class="text-center max-w-md px-6">
                                <div class="mb-6 flex justify-center">
                                    <x-icon name="o-sparkles" class="w-20 h-20 text-green-400 opacity-70" />
                                </div>
                                <h3 class="text-2xl font-semibold text-green-400 mb-3">Ready to Build!</h3>
                                <p class="text-base text-gray-300 mb-2">Give us a prompt and we'll cook something amazing for you.</p>
                                <p class="text-sm text-gray-400 mb-6">Describe what you want to build, and we'll generate beautiful Livewire components with MaryUI.</p>
                                <div class="flex flex-col gap-2 text-left text-xs text-gray-500">
                                    <p class="flex items-center gap-2">
                                        <x-icon name="o-check-circle" class="w-4 h-4 text-green-400" />
                                        <span>Dashboard with sidebar navigation</span>
                                    </p>
                                    <p class="flex items-center gap-2">
                                        <x-icon name="o-check-circle" class="w-4 h-4 text-green-400" />
                                        <span>Forms with validation</span>
                                    </p>
                                    <p class="flex items-center gap-2">
                                        <x-icon name="o-check-circle" class="w-4 h-4 text-green-400" />
                                        <span>Data tables and lists</span>
                                    </p>
                                    <p class="flex items-center gap-2">
                                        <x-icon name="o-check-circle" class="w-4 h-4 text-green-400" />
                                        <span>Multi-page applications</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    @elseif($generatedCode)
                        <div class="code-viewer-scrollable bg-gray-900 rounded-lg p-4 relative" style="height: 100%; overflow-y: auto; overflow-x: auto;">
                            <div class="mb-2 text-xs text-gray-400 sticky top-0 bg-gray-900 pb-2 z-10 flex items-center justify-between">
                                <div>
                                    @if($selectedFilePath)
                                        <span>File: {{ basename($selectedFilePath) }}</span>
                                    @elseif($componentName)
                                        <span>Generated: {{ $componentName }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="saveEditedCode"
                                        wire:loading.attr="disabled"
                                        class="px-2 py-1 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded border border-blue-500 flex items-center gap-1 transition-colors"
                                        title="Save changes"
                                    >
                                        <span wire:loading.remove wire:target="saveEditedCode" class="flex items-center gap-1">
                                            <x-icon name="o-check" class="w-3 h-3" />
                                            Save
                                        </span>
                                        <span wire:loading wire:target="saveEditedCode" class="flex items-center gap-1">
                                            <x-icon name="o-arrow-path" class="w-3 h-3 animate-spin" />
                                            Saving...
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        x-data="{ copied: false }"
                                        x-on:click="
                                            const codeId = 'code-content-{{ md5($selectedFilePath ?: $componentName) }}';
                                            const codeContent = document.getElementById(codeId)?.value || '';
                                            navigator.clipboard.writeText(codeContent).then(() => {
                                                copied = true;
                                                setTimeout(() => copied = false, 2000);
                                            });
                                        "
                                        class="px-2 py-1 text-xs bg-gray-800 hover:bg-gray-700 text-gray-300 rounded border border-gray-600 flex items-center gap-1 transition-colors"
                                        title="Copy code"
                                    >
                                        <span x-show="!copied" class="flex items-center gap-1">
                                            <x-icon name="o-clipboard" class="w-3 h-3" />
                                            Copy
                                        </span>
                                        <span x-show="copied" class="flex items-center gap-1 text-green-400">
                                            <x-icon name="o-check" class="w-3 h-3" />
                                            Copied!
                                        </span>
                                    </button>
                                </div>
                            </div>
                            <textarea 
                                id="code-content-{{ md5($selectedFilePath ?: $componentName) }}"
                                wire:model.defer="generatedCode"
                                class="w-full h-full bg-transparent text-sm text-green-400 font-mono border-0 outline-none resize-none p-0 m-0"
                                style="min-height: calc(100% - 3rem); font-family: 'Courier New', monospace; white-space: pre; overflow-wrap: normal; tab-size: 4;"
                                spellcheck="false"
                            >{{ $generatedCode }}</textarea>
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
            @if($isGenerating)
                {{-- Preview Generation Loader --}}
                <div class="flex-1 flex items-center justify-center bg-gray-50 relative overflow-hidden">
                    {{-- Animated background pattern --}}
                    <div class="absolute inset-0 opacity-5">
                        <div class="absolute inset-0" style="background-image: repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(0,0,0,0.1) 10px, rgba(0,0,0,0.1) 20px);"></div>
                    </div>
                    
                    <div class="relative z-10 text-center">
                        {{-- Animated preview icon --}}
                        <div class="mb-6 flex justify-center">
                            <div class="relative">
                                <x-icon name="o-eye" class="w-16 h-16 text-blue-500 animate-pulse" />
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <x-icon name="o-arrow-path" class="w-8 h-8 text-blue-600 animate-spin" />
                                </div>
                            </div>
                        </div>
                        
                        {{-- Loading text --}}
                        <h3 class="text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2">Preparing Preview...</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Setting up your component preview</p>
                        
                        {{-- Animated dots --}}
                        <div class="flex justify-center gap-1">
                            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0s;"></div>
                            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
                            <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0.4s;"></div>
                        </div>
                    </div>
                </div>
            @elseif(!$previewReady && empty($previewUrl))
                {{-- Initial State - Encourage Prompt --}}
                <div class="flex-1 flex items-center justify-center bg-gray-50">
                    <div class="text-center max-w-md px-6">
                        <div class="mb-6 flex justify-center">
                            <x-icon name="o-eye" class="w-20 h-20 text-blue-500 opacity-70" />
                        </div>
                        <h3 class="text-2xl font-semibold text-gray-800 dark:text-gray-200 mb-3">Ready to Preview!</h3>
                        <p class="text-base text-gray-600 dark:text-gray-400 mb-2">Give us a prompt and we'll build something amazing for you.</p>
                        <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">Once you generate code, the live preview will appear here.</p>
                        <div class="flex flex-col gap-2 text-left text-xs text-gray-500 dark:text-gray-400">
                            <p class="flex items-center gap-2">
                                <x-icon name="o-check-circle" class="w-4 h-4 text-blue-500" />
                                <span>See your components in action</span>
                            </p>
                            <p class="flex items-center gap-2">
                                <x-icon name="o-check-circle" class="w-4 h-4 text-blue-500" />
                                <span>Test different themes</span>
                            </p>
                            <p class="flex items-center gap-2">
                                <x-icon name="o-check-circle" class="w-4 h-4 text-blue-500" />
                                <span>Navigate between routes</span>
                            </p>
                        </div>
                    </div>
                </div>
            @elseif($previewReady && $previewUrl)
                <div class="flex-1 flex flex-col" style="min-height: 0; height: 100%;" wire:key="preview-container-{{ $previewUrl }}-{{ $previewReady }}">
                    <div class="flex-1 bg-white overflow-hidden relative" style="min-height: 0; height: 100%;">
                        <iframe 
                            id="preview-iframe"
                            src="{{ $previewUrl }}" 
                            class="w-full h-full border-0"
                            title="Live Preview"
                            style="width: 100%; height: 100%; min-height: 0;"
                            sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"
                            wire:key="preview-iframe-{{ $previewUrl }}-{{ $selectedRoute }}">
                        </iframe>
                    </div>
                </div>
            @elseif($isGenerating)
                <div class="flex-1 flex items-center justify-center bg-gray-50 rounded-lg" style="min-height: 0;">
                    <div class="text-center">
                        <div class="relative mb-4">
                            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500 mx-auto"></div>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <x-icon name="o-code-bracket" class="w-6 h-6 text-blue-500" />
                            </div>
                        </div>
                        <p class="text-sm font-medium text-gray-700">
                            @if($generationStatus === 'generating')
                                Generating code...
                            @elseif($generationStatus === 'validating')
                                Validating & injecting code...
                            @elseif($generationStatus === 'debugging')
                                Debugging issues...
                            @elseif($generationStatus === 'fixing')
                                Fixing bugs automatically...
                            @else
                                Processing...
                            @endif
                        </p>
                        <p class="text-xs text-gray-500 mt-1">This may take a moment</p>
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
            height: 100%;
            overflow-y: auto !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            display: flex;
            flex-direction: column;
        }
        
        .code-viewer-scrollable textarea {
            flex: 1;
            min-height: 0;
            overflow-y: auto !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }
        
        .code-viewer-scrollable pre {
            margin: 0;
            padding: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        /* Theme dropdown max height and scrollable */
        .theme-dropdown [role="menu"],
        .theme-dropdown .dropdown-content,
        .theme-dropdown ul[role="menu"],
        .theme-dropdown > div[role="menu"],
        .theme-dropdown .menu,
        .theme-dropdown ul.menu {
            max-height: 6rem !important; /* 24 * 0.25rem = 6rem - very minimal height */
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        
        /* Ensure smooth scrolling for theme dropdown */
        .theme-dropdown [role="menu"]::-webkit-scrollbar,
        .theme-dropdown .dropdown-content::-webkit-scrollbar,
        .theme-dropdown ul[role="menu"]::-webkit-scrollbar {
            width: 6px;
        }
        
        .theme-dropdown [role="menu"]::-webkit-scrollbar-track,
        .theme-dropdown .dropdown-content::-webkit-scrollbar-track,
        .theme-dropdown ul[role="menu"]::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .theme-dropdown [role="menu"]::-webkit-scrollbar-thumb,
        .theme-dropdown .dropdown-content::-webkit-scrollbar-thumb,
        .theme-dropdown ul[role="menu"]::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 3px;
        }
        
        .theme-dropdown [role="menu"]::-webkit-scrollbar-thumb:hover,
        .theme-dropdown .dropdown-content::-webkit-scrollbar-thumb:hover,
        .theme-dropdown ul[role="menu"]::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }
        
        /* Code generation loader animations */
        @keyframes progress {
            0% {
                width: 0%;
                transform: translateX(0);
            }
            50% {
                width: 70%;
                transform: translateX(0);
            }
            100% {
                width: 100%;
                transform: translateX(100%);
            }
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
