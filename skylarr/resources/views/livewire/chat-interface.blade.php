<div class="h-full flex flex-col">
    {{-- Chat Messages Area --}}
    <div id="chat-scroll" class="flex-1 overflow-y-auto p-4 space-y-4">
        @foreach ($messages as $m)
            <div class="flex {{ $m['role']==='user' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%] {{ $m['role']==='user' ? 'order-2' : 'order-1' }}">
                    <div class="px-4 py-3 rounded-2xl shadow-sm {{ $m['role']==='user' ? 'bg-blue-500 text-white rounded-br-md' : 'bg-gray-100 text-gray-900 rounded-bl-md' }}">
                        <div class="text-xs mb-2 opacity-70">
                            {{ ucfirst($m['role']) }}
                            <span class="mx-2">•</span>
                            <span class="inline-flex items-center gap-1">
                                @if ($m['status']==='streaming')
                                    <span class="size-2 rounded-full bg-blue-400 animate-pulse"></span>
                                    <span>streaming</span>
                                @elseif ($m['status']==='complete')
                                    <span class="size-2 rounded-full bg-green-400"></span>
                                    <span>complete</span>
                                @elseif ($m['status']==='error')
                                    <span class="size-2 rounded-full bg-red-500"></span>
                                    <span>error</span>
                                @else
                                    <span class="size-2 rounded-full bg-gray-400"></span>
                                    <span>{{ $m['status'] }}</span>
                                @endif
                            </span>
                        </div>
                        <div class="whitespace-pre-wrap leading-relaxed text-sm">{!! nl2br(e($m['content'])) !!}</div>
                    </div>
                </div>
            </div>
        @endforeach
        
        @if(empty($messages))
            <div class="flex items-center justify-center h-full text-gray-400">
                <div class="text-center">
                    <x-icon name="o-chat-bubble-left-right" class="w-12 h-12 mx-auto mb-4 opacity-50" />
                    <p class="text-sm">Start a conversation to generate code</p>
                    <p class="text-xs mt-2 opacity-75">Ask me to create components, forms, or any UI elements</p>
                </div>
            </div>
        @endif
    </div>

    {{-- Message Input Area --}}
    <div class="border-t border-gray-200 p-4">
        <x-form wire:submit="sendMessage">   
            <x-textarea wire:model.defer="message" placeholder="Type your message here..." rows="2" class="resize-none" :disabled="$isStreaming" />

            <x-slot:actions>
                <x-button type="submit" icon="o-paper-airplane" class="btn-primary h-10 w-10 p-0" :disabled="$isStreaming || empty($message)" spinner="sendMessage" />
            </x-slot:actions>  
        </x-form>
    </div>
</div>

<script>
    document.addEventListener('livewire:init', () => {
        const scrollEl = document.getElementById('chat-scroll');
        function scrollToBottom() {
            if (!scrollEl) return;
            scrollEl.scrollTop = scrollEl.scrollHeight;
        }
        scrollToBottom();
        window.addEventListener('chat-scrolled', scrollToBottom);
    });
</script>
