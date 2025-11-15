<div class="h-full flex flex-col bg-white" style="height: 100%; display: flex; flex-direction: column;">
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
            <div class="flex gap-2 items-end">
                <div class="flex-1">
                    <textarea 
                        wire:model="message" 
                        placeholder="Type your message here..." 
                        rows="2" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg resize-none text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        @if($isStreaming) disabled @endif
                        x-on:keydown.enter.prevent="if (!event.shiftKey && $wire.message.trim()) { $wire.sendMessage(); }"
                    ></textarea>
                </div>
                <button 
                    type="button"
                    wire:click="sendMessage"
                    class="btn btn-primary px-4 py-2 h-auto flex items-center gap-2 whitespace-nowrap"
                    @if(!trim($message) || $isStreaming) disabled @endif
                >
                    <x-icon name="o-paper-airplane" class="w-4 h-4" />
                    <span class="hidden sm:inline">Send</span>
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
