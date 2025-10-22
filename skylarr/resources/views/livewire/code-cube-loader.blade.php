<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-sm">
    <div class="w-full h-full flex flex-col items-center justify-center gap-10 p-8">
        {{-- Input Section --}}
        <div class="flex gap-4 p-6 bg-gray-800/80 rounded-lg border border-gray-600 backdrop-blur-sm shadow-2xl">
            <input 
                type="text" 
                placeholder="e.g. 'Build me a portfolio website'"
                wire:model="prompt"
                class="px-4 py-3 text-base bg-gray-700 border border-gray-500 rounded text-white placeholder-gray-300 w-80 font-mono focus:outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/50 focus:bg-gray-600"
                disabled="{{ $isGenerating }}"
            />
            <button 
                wire:click="startGeneration"
                class="px-6 py-3 text-base font-mono rounded border transition-all duration-300 font-semibold
                    {{ $isGenerating 
                        ? 'bg-gray-600 border-gray-500 text-gray-300 cursor-not-allowed' 
                        : 'bg-cyan-600 border-cyan-400 text-white hover:bg-cyan-500 hover:shadow-lg hover:shadow-cyan-400/50 hover:border-cyan-300' 
                    }}"
                disabled="{{ $isGenerating }}"
            >
                {{ $isGenerating ? 'Generating...' : 'Generate Code' }}
            </button>
        </div>

        {{-- Code Cube --}}
        <div class="relative w-48 h-48" style="transform-style: preserve-3d; animation: rotate 10s infinite linear;">
            {{-- Front Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border-2 font-mono shadow-lg cube-face"
                 style="transform: translateZ(100px); color: #00ffff; background: rgba(0, 0, 0, 0.8); border-color: #00ffff;">
                <div class="text-xs font-bold mb-1 text-white border-b border-gray-600 pb-1 bg-black/50 px-1 rounded cube-text">
                    index.html
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0 text-cyan-300 cube-text">
                    {{ substr($faceTexts[0] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Right Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border-2 font-mono shadow-lg cube-face"
                 style="transform: rotateY(90deg) translateZ(100px); color: #ff00ff; background: rgba(0, 0, 0, 0.8); border-color: #ff00ff;">
                <div class="text-xs font-bold mb-1 text-white border-b border-gray-600 pb-1 bg-black/50 px-1 rounded cube-text">
                    styles.css
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0 text-pink-300 cube-text">
                    {{ substr($faceTexts[1] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Top Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border-2 font-mono shadow-lg cube-face"
                 style="transform: rotateX(90deg) translateZ(100px); color: #ffff00; background: rgba(0, 0, 0, 0.8); border-color: #ffff00;">
                <div class="text-xs font-bold mb-1 text-white border-b border-gray-600 pb-1 bg-black/50 px-1 rounded cube-text">
                    main.js
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0 text-yellow-300 cube-text">
                    {{ substr($faceTexts[2] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Back Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border-2 font-mono shadow-lg cube-face"
                 style="transform: rotateY(180deg) translateZ(100px); color: #00ff00; background: rgba(0, 0, 0, 0.8); border-color: #00ff00;">
                <div class="text-xs font-bold mb-1 text-white border-b border-gray-600 pb-1 bg-black/50 px-1 rounded cube-text">
                    index.html
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0 text-green-300 cube-text">
                    {{ substr($faceTexts[3] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Left Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border-2 font-mono shadow-lg cube-face"
                 style="transform: rotateY(-90deg) translateZ(100px); color: #ff8800; background: rgba(0, 0, 0, 0.8); border-color: #ff8800;">
                <div class="text-xs font-bold mb-1 text-white border-b border-gray-600 pb-1 bg-black/50 px-1 rounded cube-text">
                    styles.css
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0 text-orange-300 cube-text">
                    {{ substr($faceTexts[4] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Bottom Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border-2 font-mono shadow-lg cube-face"
                 style="transform: rotateX(-90deg) translateZ(100px); color: #8888ff; background: rgba(0, 0, 0, 0.8); border-color: #8888ff;">
                <div class="text-xs font-bold mb-1 text-white border-b border-gray-600 pb-1 bg-black/50 px-1 rounded cube-text">
                    main.js
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0 text-blue-300 cube-text">
                    {{ substr($faceTexts[5] ?? '', 0, 140) }}
                </div>
            </div>
        </div>

        {{-- Status Text --}}
        @if($isGenerating)
            <div class="text-center bg-gray-800/80 rounded-lg p-6 border border-gray-600 shadow-2xl">
                <div class="text-cyan-300 font-mono text-xl mb-4 font-semibold">Generating Code...</div>
                <div class="flex items-center justify-center gap-3">
                    <div class="w-3 h-3 bg-cyan-400 rounded-full animate-pulse shadow-lg shadow-cyan-400/50"></div>
                    <div class="w-3 h-3 bg-cyan-400 rounded-full animate-pulse shadow-lg shadow-cyan-400/50" style="animation-delay: 0.2s;"></div>
                    <div class="w-3 h-3 bg-cyan-400 rounded-full animate-pulse shadow-lg shadow-cyan-400/50" style="animation-delay: 0.4s;"></div>
                </div>
                <div class="text-gray-300 text-sm mt-3 font-mono">AI is crafting your components...</div>
            </div>
        @endif
    </div>

    {{-- CSS Animations --}}
    <style>
        @keyframes rotate {
            from {
                transform: rotateX(-20deg) rotateY(30deg);
            }
            to {
                transform: rotateX(-20deg) rotateY(390deg);
            }
        }

        input::placeholder {
            color: rgba(209, 213, 219, 0.8);
        }

        button:hover:not(:disabled) {
            background: #06b6d4 !important;
            box-shadow: 0 0 25px rgba(6, 182, 212, 0.6);
            transform: translateY(-1px);
        }

        /* Enhanced cube visibility */
        .cube-face {
            backdrop-filter: blur(2px);
            box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.5);
        }

        /* Better text contrast */
        .cube-text {
            text-shadow: 0 0 3px rgba(0, 0, 0, 0.8);
            font-weight: 500;
        }
    </style>

    {{-- JavaScript for Real-time Streaming --}}
    <script>
        document.addEventListener('livewire:init', () => {
            let streamingIntervals = [];
            const loaderElement = document.querySelector('.fixed.inset-0.z-50');

            // Hide loader when project is selected
            Livewire.on('projectSelected', () => {
                if (loaderElement) {
                    loaderElement.style.display = 'none';
                }
                // Clear any running intervals
                streamingIntervals.forEach(clearInterval);
                streamingIntervals = [];
            });

            Livewire.on('startGeneration', () => {
                // Clear any existing intervals
                streamingIntervals.forEach(clearInterval);
                streamingIntervals = [];

                // Load code files from separate files
                Promise.all([
                    fetch('/code-samples/index.html').then(r => r.text()),
                    fetch('/code-samples/styles.css').then(r => r.text()),
                    fetch('/code-samples/main.js').then(r => r.text())
                ]).then(([htmlFile, cssFile, jsFile]) => {
                    const codeFiles = [htmlFile, cssFile, jsFile, htmlFile, cssFile, jsFile];

                    // Stream each file character by character
                    codeFiles.forEach((file, faceIndex) => {
                        let charIndex = 0;
                        const maxChars = 140;
                        
                        const interval = setInterval(() => {
                            if (charIndex < file.length) {
                                const textToShow = file.substring(0, charIndex + 1).slice(-maxChars);
                                Livewire.dispatch('updateFaceText', { faceIndex, text: textToShow });
                                charIndex++;
                            } else {
                                clearInterval(interval);
                                streamingIntervals[faceIndex] = null;
                                
                                // Check if all faces are done streaming
                                if (streamingIntervals.every(int => int === null)) {
                                    setTimeout(() => {
                                        Livewire.dispatch('stopGeneration');
                                    }, 1000);
                                }
                            }
                        }, 20); // Fast streaming for code effect
                        
                        streamingIntervals[faceIndex] = interval;
                    });
                }).catch(error => {
                    console.error('Error loading code files:', error);
                });
            });

            Livewire.on('stopGeneration', () => {
                streamingIntervals.forEach(clearInterval);
                streamingIntervals = [];
            });
        });
    </script>
</div>