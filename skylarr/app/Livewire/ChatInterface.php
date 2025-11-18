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
    public ?string $selectedComponentName = null;
    public bool $showComponentSelect = false;

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
            // Try to find the thread with the most recent message for this user and project
            $thread = ChatThread::where('user_id', $userId)
                ->when($this->projectId, function ($query) {
                    return $query->where('project_id', $this->projectId);
                })
                ->whereHas('messages') // Only threads that have messages
                ->with(['messages' => function ($q) { 
                    $q->orderBy('id'); // Order messages oldest to newest for display
                }])
                ->withMax('messages', 'created_at') // Get the latest message timestamp
                ->orderByDesc('messages_max_created_at') // Order by most recent message
                ->first();
            
            // If no thread with messages found, try by thread updated_at or created_at
            if (!$thread) {
                $thread = ChatThread::where('user_id', $userId)
                    ->when($this->projectId, function ($query) {
                        return $query->where('project_id', $this->projectId);
                    })
                    ->orderByDesc('updated_at')
                    ->orderByDesc('created_at')
                    ->with(['messages' => function ($q) { 
                        $q->orderBy('id'); 
                    }])
                    ->first();
            }
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

        // Ensure messages are ordered correctly (oldest to newest) for display
        // The relationship might have default ordering, so we sort the collection
        $messages = $thread->messages->sortBy('id')->values();
        
        $this->messages = $messages->map(fn($m) => [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'status' => $m->status,
            'created_at' => $m->created_at?->toDateTimeString(),
        ])->values()->all();
        
        Log::info('[CHAT] Thread loaded', [
            'thread_id' => $this->threadId,
            'message_count' => count($this->messages),
            'last_message' => count($this->messages) > 0 ? substr($this->messages[count($this->messages) - 1]['content'], 0, 50) : 'none'
        ]);
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

        // Clear message field immediately for better UX
        $this->message = '';
        
        // Add user message to UI immediately (optimistic update)
        $tempUserMsgId = 'temp-' . time();
        $this->messages[] = [
            'id' => $tempUserMsgId,
            'role' => 'user',
            'content' => $content,
            'status' => 'sent',
            'created_at' => now()->toDateTimeString(),
        ];
        
        // Force immediate UI update
        $this->dispatch('chat-scrolled');
        
        // Set streaming flag
        $this->isStreaming = true;

        // Persist user message to database (non-blocking for UI)
        $userMsg = ChatMessage::create([
            'chat_thread_id' => $this->threadId,
            'role' => 'user',
            'content' => $content,
            'status' => 'sent',
        ]);
        
        // Update the temporary message with real ID
        foreach ($this->messages as $key => $msg) {
            if ($msg['id'] === $tempUserMsgId) {
                $this->messages[$key]['id'] = $userMsg->id;
                $this->messages[$key]['created_at'] = $userMsg->created_at?->toDateTimeString();
                break;
            }
        }

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
        
        // Force another UI update to show the placeholder
        $this->dispatch('chat-scrolled');

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
                    'prompt' => $userMessage,
                    'selected_component' => $this->selectedComponentName
                ]);
                
                $this->dispatch('generate-code', [
                    'prompt' => $userMessage,
                    'component_name' => $this->selectedComponentName
                ]);
                return;
            }
        }
        
        Log::info('[CHAT] No code generation trigger found');
    }
    
    public function getComponentsProperty(): array
    {
        if (!$this->projectId) {
            return [];
        }
        
        $project = \App\Models\Project::where('user_id', Auth::id())
            ->find($this->projectId);
            
        if (!$project) {
            return [];
        }
        
        return $project->getComponents();
    }
    
    public function toggleComponentSelect(): void
    {
        $this->showComponentSelect = !$this->showComponentSelect;
    }
    
    public function selectComponent(?string $componentName): void
    {
        $this->selectedComponentName = $componentName;
        $this->showComponentSelect = false;
    }

    // Removed Livewire listeners - we use browser events via Alpine.js to avoid duplicates
    // protected $listeners = [
    //     'code-generation-complete' => 'addCodeGenerationMessage',
    //     'code-generation-failed' => 'addCodeGenerationErrorMessage',
    // ];

    public function addCodeGenerationMessage($data = null)
    {
        if (!$this->threadId || !$this->projectId) {
            return;
        }

        // Handle both array and object data, or default values
        if (is_array($data)) {
            $message = $data['message'] ?? 'Code generation completed successfully!';
            $componentName = $data['component_name'] ?? 'component';
        } else {
            $message = 'Code generation completed successfully!';
            $componentName = 'component';
        }

        $fullMessage = "✅ Code generation complete! I've created the `{$componentName}` component. You can view it in the Code tab and see the live preview in the Preview tab.";

        // Check if this exact message was already added recently (within last 10 seconds) to prevent duplicates
        $recentMessage = collect($this->messages)
            ->where('role', 'assistant')
            ->where('content', $fullMessage)
            ->filter(function ($msg) {
                if (!isset($msg['created_at'])) {
                    return false;
                }
                try {
                    $createdAt = is_string($msg['created_at']) 
                        ? \Carbon\Carbon::parse($msg['created_at']) 
                        : $msg['created_at'];
                    return $createdAt->isAfter(now()->subSeconds(10));
                } catch (\Exception $e) {
                    return false;
                }
            })
            ->first();

        if ($recentMessage) {
            Log::info('[CHAT] Duplicate code generation message detected, skipping', [
                'component_name' => $componentName,
                'thread_id' => $this->threadId
            ]);
            return;
        }

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

    public function addCodeGenerationErrorMessage($data = null)
    {
        if (!$this->threadId || !$this->projectId) {
            return;
        }

        // Handle both array and object data, or default values
        if (is_array($data)) {
            $errorMessage = $data['message'] ?? 'Code generation failed. Please try again.';
        } else {
            $errorMessage = 'Code generation failed. Please try again.';
        }

        $fullMessage = "❌ Sorry, I encountered an error while generating the code: {$errorMessage}";

        // Check if this exact error message was already added recently (within last 10 seconds) to prevent duplicates
        $recentMessage = collect($this->messages)
            ->where('role', 'assistant')
            ->where('content', $fullMessage)
            ->filter(function ($msg) {
                if (!isset($msg['created_at'])) {
                    return false;
                }
                try {
                    $createdAt = is_string($msg['created_at']) 
                        ? \Carbon\Carbon::parse($msg['created_at']) 
                        : $msg['created_at'];
                    return $createdAt->isAfter(now()->subSeconds(10));
                } catch (\Exception $e) {
                    return false;
                }
            })
            ->first();

        if ($recentMessage) {
            Log::info('[CHAT] Duplicate code generation error message detected, skipping', [
                'thread_id' => $this->threadId
            ]);
            return;
        }

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
