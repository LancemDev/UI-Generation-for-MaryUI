<div 
    class="h-full flex flex-col bg-white" 
    style="height: 100%; display: flex; flex-direction: column;"
    x-data=""
    @code-generation-complete.window="$wire.addCodeGenerationMessage($event.detail)"
    @code-generation-failed.window="$wire.addCodeGenerationErrorMessage($event.detail)"
>
    {{-- Chat Messages Area - Scrollable --}}
    <div id="chat-scroll" class="flex-1 overflow-y-auto p-4 space-y-4" style="min-height: 0; flex: 1 1 auto;">
        @foreach ($messages as $m)
            <div class="flex {{ $m['role']==='user' ? 'justify-end' : 'justify-start' }} animate-fade-in">
                <div class="max-w-[85%] {{ $m['role']==='user' ? 'order-2' : 'order-1' }}">
                    <div class="px-4 py-3 rounded-2xl shadow-sm transition-all {{ $m['role']==='user' ? 'bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-br-md' : 'bg-gray-50 text-gray-900 rounded-bl-md border border-gray-200' }}">
                        <div class="text-xs mb-2 {{ $m['role']==='user' ? 'opacity-80' : 'opacity-60' }}">
                            <span class="font-medium">{{ $m['role']==='user' ? 'You' : 'Skylarr' }}</span>
                            <span class="mx-2">•</span>
                            <span class="inline-flex items-center gap-1">
                                @if ($m['status']==='streaming')
                                    <span class="size-2 rounded-full bg-blue-400 animate-pulse"></span>
                                    <span>typing...</span>
                                @elseif ($m['status']==='complete')
                                    <span class="size-2 rounded-full bg-green-400"></span>
                                    <span>sent</span>
                                @elseif ($m['status']==='error')
                                    <span class="size-2 rounded-full bg-red-500"></span>
                                    <span>error</span>
                                @else
                                    <span class="size-2 rounded-full bg-gray-400"></span>
                                    <span>{{ $m['status'] }}</span>
                                @endif
                            </span>
                        </div>
                        <div class="whitespace-pre-wrap leading-relaxed text-sm {{ $m['role']==='user' ? 'text-white' : 'text-gray-800' }}">{!! nl2br(e($m['content'])) !!}</div>
                        @if($m['role'] === 'assistant' && $m['status'] === 'complete' && str_contains($m['content'], 'Code generation complete'))
                            <div class="mt-2 flex items-center gap-2 pt-2 border-t border-gray-200">
                                <span class="text-xs text-gray-500">Was this helpful?</span>
                                <div class="flex gap-1">
                                    <button
                                        type="button"
                                        wire:click="submitFeedback({{ $m['id'] }}, 'positive')"
                                        class="p-1.5 rounded transition-colors {{ isset($m['feedback']) && $m['feedback'] === 'positive' ? 'bg-green-100 text-green-600' : 'hover:bg-gray-100 text-gray-600' }}"
                                        title="Helpful"
                                    >
                                        <x-icon name="o-hand-thumb-up" class="w-4 h-4" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="submitFeedback({{ $m['id'] }}, 'negative')"
                                        class="p-1.5 rounded transition-colors {{ isset($m['feedback']) && $m['feedback'] === 'negative' ? 'bg-red-100 text-red-600' : 'hover:bg-gray-100 text-gray-600' }}"
                                        title="Not helpful"
                                    >
                                        <x-icon name="o-hand-thumb-down" class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
        
        @if(empty($messages))
            <div class="flex items-center justify-center h-full text-gray-400">
                <div class="text-center">
                    <x-icon name="o-chat-bubble-left-right" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                    <p class="text-sm font-medium">Start a conversation</p>
                    <p class="text-xs mt-2 opacity-75">Ask me to create components, forms, or any UI elements</p>
                </div>
            </div>
        @endif
    </div>

    {{-- Message Input Area - Always Visible at Bottom --}}
    <div class="border-t border-gray-200 bg-white" style="flex-shrink: 0;">
        <div class="p-4">
            {{-- Component Selection --}}
            @if(count($this->components) > 0)
                <div class="mb-2 flex items-center gap-2">
                    <button 
                        type="button"
                        wire:click="toggleComponentSelect"
                        class="text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50 flex items-center gap-1"
                    >
                        <x-icon name="o-code-bracket" class="w-3 h-3" />
                        @if($selectedComponentName)
                            <span>Modify: {{ $selectedComponentName }}</span>
                        @else
                            <span>Create New Component</span>
                        @endif
                        <x-icon name="o-chevron-down" class="w-3 h-3" />
                    </button>
                    @if($selectedComponentName)
                        <button 
                            type="button"
                            wire:click="selectComponent(null)"
                            class="text-xs px-2 py-1 rounded text-red-600 hover:bg-red-50"
                        >
                            <x-icon name="o-x-mark" class="w-3 h-3" />
                        </button>
                    @endif
                </div>
                @if($showComponentSelect)
                    <div class="mb-2 p-2 bg-gray-50 rounded border border-gray-200 max-h-32 overflow-y-auto">
                        <div class="space-y-1">
                            <button 
                                type="button"
                                wire:click="selectComponent(null)"
                                class="w-full text-left px-2 py-1 text-xs rounded hover:bg-white {{ !$selectedComponentName ? 'bg-blue-100 font-medium' : '' }}"
                            >
                                + Create New Component
                            </button>
                            @foreach($this->components as $component)
                                <button 
                                    type="button"
                                    wire:click="selectComponent('{{ $component['name'] }}')"
                                    class="w-full text-left px-2 py-1 text-xs rounded hover:bg-white {{ $selectedComponentName === $component['name'] ? 'bg-blue-100 font-medium' : '' }}"
                                >
                                    <x-icon name="o-pencil" class="w-3 h-3 inline mr-1" />
                                    {{ $component['name'] }} ({{ $component['route'] }})
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
            
            <div class="flex gap-2 items-end">
                <div class="flex-1">
                    <textarea 
                        wire:model="message" 
                        placeholder="Type your message here... (Press Enter to send, Shift+Enter for new line)" 
                        rows="4" 
                        class="w-full px-4 py-3 border-2 border-gray-300 rounded-xl resize-none text-base text-gray-900 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all shadow-sm hover:border-gray-400"
                        style="min-height: 80px; max-height: 200px;"
                        @if($isStreaming) disabled @endif
                        x-on:keydown.enter.prevent="if (!event.shiftKey && $wire.message.trim()) { $wire.sendMessage(); }"
                    ></textarea>
                </div>
                <button 
                    type="button"
                    wire:click="sendMessage"
                    wire:loading.attr="disabled"
                    wire:target="sendMessage"
                    class="chat-submit-btn btn btn-primary px-4 py-2 h-auto flex items-center gap-2 whitespace-nowrap"
                    style="visibility: visible !important; opacity: 1 !important; display: inline-flex !important;"
                    :disabled="$isStreaming"
                >
                    <span wire:loading.remove wire:target="sendMessage">
                        <x-icon name="o-paper-airplane" class="w-4 h-4" />
                    </span>
                    <span wire:loading wire:target="sendMessage" class="animate-spin">
                        <x-icon name="o-arrow-path" class="w-4 h-4" />
                    </span>
                    <span class="hidden sm:inline">
                        <span wire:loading.remove wire:target="sendMessage">Send</span>
                        <span wire:loading wire:target="sendMessage">Sending...</span>
                    </span>
                </button>
            </div>
        </div>
    </div>

    <style>
        #chat-scroll {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }
        
        #chat-scroll::-webkit-scrollbar {
            width: 6px;
        }
        
        #chat-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        #chat-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        #chat-scroll::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        @keyframes fade-in {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fade-in 0.3s ease-out;
        }
        
        /* Ensure submit button is always visible */
        .chat-submit-btn {
            visibility: visible !important;
            opacity: 1 !important;
            display: inline-flex !important;
        }
        
        /* Ensure textarea text is visible */
        textarea {
            color: #111827 !important;
            background-color: #ffffff !important;
        }
        
        textarea::placeholder {
            color: #9ca3af !important;
        }
    </style>

    <script>
        document.addEventListener('livewire:init', () => {
            const scrollEl = document.getElementById('chat-scroll');
            
            function scrollToBottom() {
                if (!scrollEl) return;
                requestAnimationFrame(() => {
                    scrollEl.scrollTop = scrollEl.scrollHeight;
                });
            }
            
            // Initial scroll
            scrollToBottom();
            
            // Listen for chat scroll events
            window.addEventListener('chat-scrolled', scrollToBottom);
            
            // Auto-scroll on new messages
            Livewire.hook('morph.updated', () => {
                setTimeout(scrollToBottom, 100);
            });
        });
    </script>
</div>
