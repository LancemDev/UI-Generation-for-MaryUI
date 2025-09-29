<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Services\AiGateway;
use Illuminate\Support\Facades\Auth;

class ChatInterface extends Component
{
    use Toast;

    public string $message = '';
    public ?int $threadId = null;
    public array $messages = [];
    public bool $isStreaming = false;

    public function mount(?int $threadId = null): void
    {
        $this->threadId = $threadId;
        $this->loadThread();
    }

    protected function loadThread(): void
    {
        $userId = Auth::id();
        $thread = null;

        if ($this->threadId) {
            // Load only if the thread belongs to the current user
            $thread = ChatThread::where('id', $this->threadId)
                ->where('user_id', $userId)
                ->with(['messages' => function ($q) { $q->orderBy('id'); }])
                ->first();
        }

        if (!$thread) {
            // Try latest thread for this user
            $thread = ChatThread::where('user_id', $userId)
                ->orderByDesc('id')
                ->with(['messages' => function ($q) { $q->orderBy('id'); }])
                ->first();
        }

        if (!$thread) {
            // Create a fresh thread for this user
            $thread = ChatThread::create([
                'user_id' => $userId,
                'title' => 'New chat',
            ]);
        }

        $this->threadId = $thread->id;

        $this->messages = $thread->messages->map(fn($m) => [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'status' => $m->status,
            'created_at' => $m->created_at?->toDateTimeString(),
        ])->values()->all();
    }

    public function sendMessage(): void
    {
        $content = trim($this->message);
        if ($content === '') {
            return;
        }

        $this->isStreaming = true;

        // Persist user message
        $userMsg = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'user',
            'content' => $content,
            'status' => 'sent',
        ]);
        $this->messages[] = [
            'id' => $userMsg->id,
            'role' => 'user',
            'content' => $content,
            'status' => 'sent',
            'created_at' => $userMsg->created_at?->toDateTimeString(),
        ];
        $this->message = '';

        // Create placeholder assistant message
        $assistantMsg = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);
        $assistantIndex = count($this->messages);
        $this->messages[] = [
            'id' => $assistantMsg->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
            'created_at' => $assistantMsg->created_at?->toDateTimeString(),
        ];

        // Build message array for gateway
        $history = array_map(fn ($m) => [
            'role' => $m['role'],
            'content' => $m['content'],
        ], $this->messages);

        $gateway = app(AiGateway::class);

        try {
            foreach ($gateway->streamChat($history) as $delta) {
                $this->messages[$assistantIndex]['content'] .= $delta;
                $this->dispatch('chat-scrolled');
            }
            $this->messages[$assistantIndex]['status'] = 'complete';
            $assistantMsg->update([
                'content' => $this->messages[$assistantIndex]['content'],
                'status' => 'complete',
            ]);
        } catch (\Throwable $e) {
            $this->messages[$assistantIndex]['status'] = 'error';
            $assistantMsg->update([
                'status' => 'error',
                'metadata' => ['error' => $e->getMessage()],
            ]);
            $this->error('Streaming failed');
        } finally {
            $this->isStreaming = false;
        }
    }

    public function render()
    {
        return view('livewire.chat-interface');
    }
}
