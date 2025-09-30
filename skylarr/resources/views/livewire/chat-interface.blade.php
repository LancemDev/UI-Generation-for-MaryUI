<div class="mt-10">
    <div class="flex flex-col h-full">
        <div id="chat-scroll" class="flex-1 overflow-y-auto space-y-4 pr-2">
            @foreach ($messages as $m)
                <div class="max-w-prose {{ $m['role']==='user' ? 'ml-auto' : '' }}">
                    <div class="px-3 py-2 rounded-lg shadow-sm border {{ $m['role']==='user' ? 'bg-secondary text-white border-secondary' : 'bg-white text-gray-900 border-secondary/40' }}">
                        <div class="text-xs mb-1 opacity-80">
                            {{ ucfirst($m['role']) }}
                            <span class="mx-2">•</span>
                            <span class="inline-flex items-center gap-1">
                                @if ($m['status']==='streaming')
                                    <span class="size-2 rounded-full bg-secondary animate-pulse"></span>
                                    <span>streaming</span>
                                @elseif ($m['status']==='complete')
                                    <span class="size-2 rounded-full bg-primary"></span>
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
                        <div class="whitespace-pre-wrap leading-relaxed">{!! nl2br(e($m['content'])) !!}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3">
            <x-form wire:submit="sendMessage">
                <x-textarea wire:model.defer="message" label="" placeholder="Type a message..." />
                <x-slot:actions>
                    <x-button type="submit" label="Send" spinner="sendMessage" icon="o-paper-airplane" class="bg-secondary text-white hover:bg-secondary/80" />
                </x-slot:actions>
            </x-form>
        </div>
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
