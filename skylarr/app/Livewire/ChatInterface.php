<?php

namespace App\Livewire;

use Livewire\Component;
use Mary\Traits\Toast;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Services\AiGateway;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ChatInterface extends Component
{
    use Toast;

    public string $message = '';
    public ?int $threadId = null;
    public ?int $projectId = null;
    public array $messages = [];
    public bool $isStreaming = false;

    public function mount(?int $threadId = null, ?int $projectId = null): void
    {
        $this->threadId = $threadId;
        $this->projectId = $projectId;
        
        // Only load thread if we have a valid projectId
        if ($this->projectId) {
            $this->loadThread();
        }
    }

    protected function loadThread(): void
    {
        $userId = Auth::id();
        $thread = null;

        if ($this->threadId) {
            // Load only if the thread belongs to the current user and project
            $thread = ChatThread::where('id', $this->threadId)
                ->where('user_id', $userId)
                ->when($this->projectId, function ($query) {
                    return $query->where('project_id', $this->projectId);
                })
                ->with(['messages' => function ($q) { $q->orderBy('id'); }])
                ->first();
        }

        if (!$thread) {
            // Try latest thread for this user and project
            $thread = ChatThread::where('user_id', $userId)
                ->when($this->projectId, function ($query) {
                    return $query->where('project_id', $this->projectId);
                })
                ->orderByDesc('id')
                ->with(['messages' => function ($q) { $q->orderBy('id'); }])
                ->first();
        }

        if (!$thread) {
            // Create a fresh thread for this user and project
            $thread = ChatThread::create([
                'user_id' => $userId,
                'project_id' => $this->projectId,
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

        Log::info('[CHAT] User message received', [
            'message' => $content,
            'project_id' => $this->projectId,
            'thread_id' => $this->threadId
        ]);

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
            Log::info('[CHAT] Starting AI stream', ['history_count' => count($history)]);
            
            // Check user message for code generation trigger BEFORE streaming
            Log::info('[CHAT] Checking user message for code generation trigger');
            $this->checkForCodeGeneration($content);
            
            foreach ($gateway->streamChat($history) as $delta) {
                $this->messages[$assistantIndex]['content'] .= $delta;
                $this->dispatch('chat-scrolled');
            }
            
            $finalContent = $this->messages[$assistantIndex]['content'];
            Log::info('[CHAT] AI stream complete', [
                'response_length' => strlen($finalContent),
                'message_id' => $assistantMsg->id
            ]);
            
            $this->messages[$assistantIndex]['status'] = 'complete';
            $assistantMsg->update([
                'content' => $finalContent,
                'status' => 'complete',
            ]);
            
        } catch (\Throwable $e) {
            Log::error('[CHAT] Stream failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->messages[$assistantIndex]['status'] = 'error';
            $assistantMsg->update([
                'status' => 'error',
                'metadata' => ['error' => $e->getMessage()],
            ]);
            $this->error('Streaming failed');
        } finally {
            $this->isStreaming = false;
            Log::info('[CHAT] Stream process finished');
        }
    }
    
    /**
     * Check if the user message contains a code generation request
     * and trigger code generation if needed.
     */
    private function checkForCodeGeneration(string $userMessage): void
    {
        // Simple heuristic to detect code generation requests
        $codeKeywords = [
            'create',
            'build',
            'generate',
            'make',
            'component',
            'livewire',
            'form',
            'modal',
            'table',
            'dashboard',
            'page',
            'view'
        ];
        
        $lowerMessage = strtolower($userMessage);
        
        foreach ($codeKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                Log::info('[CHAT] Dispatching generate-code event', [
                    'trigger_keyword' => $keyword,
                    'prompt' => $userMessage
                ]);
                
                $this->dispatch('generate-code', [
                    'prompt' => $userMessage
                ]);
                return;
            }
        }
        
        Log::info('[CHAT] No code generation trigger found');
    }

    protected $listeners = [
        'code-generation-complete' => 'addCodeGenerationMessage',
        'code-generation-failed' => 'addCodeGenerationErrorMessage',
    ];

    public function addCodeGenerationMessage($data)
    {
        if (!$this->threadId || !$this->projectId) {
            return;
        }

        $message = $data['message'] ?? 'Code generation completed successfully!';
        $componentName = $data['component_name'] ?? 'component';

        $fullMessage = "✅ Code generation complete! I've created the `{$componentName}` component. You can view it in the Code tab and see the live preview in the Preview tab.";

        // Create assistant message
        $assistantMsg = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'assistant',
            'content' => $fullMessage,
            'status' => 'complete',
        ]);

        $this->messages[] = [
            'id' => $assistantMsg->id,
            'role' => 'assistant',
            'content' => $fullMessage,
            'status' => 'complete',
            'created_at' => $assistantMsg->created_at?->toDateTimeString(),
        ];

        $this->dispatch('chat-scrolled');
    }

    public function addCodeGenerationErrorMessage($data)
    {
        if (!$this->threadId || !$this->projectId) {
            return;
        }

        $errorMessage = $data['message'] ?? 'Code generation failed. Please try again.';

        $fullMessage = "❌ Sorry, I encountered an error while generating the code: {$errorMessage}";

        // Create assistant message
        $assistantMsg = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'assistant',
            'content' => $fullMessage,
            'status' => 'error',
        ]);

        $this->messages[] = [
            'id' => $assistantMsg->id,
            'role' => 'assistant',
            'content' => $fullMessage,
            'status' => 'error',
            'created_at' => $assistantMsg->created_at?->toDateTimeString(),
        ];

        $this->dispatch('chat-scrolled');
    }

    public function render()
    {
        return view('livewire.chat-interface');
    }
}
