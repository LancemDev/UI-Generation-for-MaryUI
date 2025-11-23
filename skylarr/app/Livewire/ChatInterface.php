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
            'feedback' => $m->feedback,
            'feedback_comment' => $m->feedback_comment,
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

        // Build message array for gateway with feedback context
        $history = $this->buildHistoryWithFeedback();

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
        // Include both initial creation and follow-up modification keywords
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
            'view',
            // Follow-up modification keywords
            'add',
            'update',
            'modify',
            'change',
            'edit',
            'remove',
            'delete',
            'field',
            'fields',
            'input',
            'button',
            'checkbox',
            'textarea',
            'toggle',
            'select'
        ];
        
        $lowerMessage = strtolower($userMessage);
        
        foreach ($codeKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                // Build conversation history for context with feedback
                $history = $this->buildHistoryWithFeedback();
                
                // Extract component name from conversation history for follow-up requests
                $extractedComponentName = $this->selectedComponentName;
                if (!$extractedComponentName && count($history) > 2) {
                    // Look for component names in previous assistant messages
                    foreach (array_reverse($history) as $msg) {
                        if ($msg['role'] === 'assistant' && isset($msg['content'])) {
                            // Try to extract component name from messages like "I've created the `ComponentName` component"
                            if (preg_match("/`([A-Z][a-zA-Z0-9]+)`/", $msg['content'], $matches)) {
                                $extractedComponentName = $matches[1];
                                break;
                            }
                            // Also check for "RegisterForm", "LoginForm", etc. in the content
                            if (preg_match("/([A-Z][a-zA-Z0-9]+Form|[A-Z][a-zA-Z0-9]+Component|[A-Z][a-zA-Z0-9]+Modal)/", $msg['content'], $matches)) {
                                $extractedComponentName = $matches[1];
                                break;
                            }
                        }
                    }
                    
                    // If still not found, check the project's existing components
                    // This helps with follow-up requests where the completion message hasn't been added yet
                    if (!$extractedComponentName && $this->projectId) {
                        $components = $this->getComponentsProperty();
                        if (!empty($components)) {
                            // Use the most recently created component (last in array)
                            $lastComponent = end($components);
                            if (isset($lastComponent['name'])) {
                                $extractedComponentName = $lastComponent['name'];
                            }
                        }
                    }
                }
                
                Log::info('[CHAT] Dispatching generate-code event', [
                    'trigger_keyword' => $keyword,
                    'prompt' => $userMessage,
                    'selected_component' => $this->selectedComponentName,
                    'extracted_component' => $extractedComponentName,
                    'history_count' => count($history)
                ]);
                
                $this->dispatch('generate-code', [
                    'prompt' => $userMessage,
                    'component_name' => $extractedComponentName,
                    'conversation_history' => $history
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
            $componentName = $data['component_name'] ?? null;
        } else {
            $message = 'Code generation completed successfully!';
            $componentName = null;
        }
        
        // If component name is missing, try to extract from project components
        if (!$componentName && $this->projectId) {
            $components = $this->getComponentsProperty();
            if (!empty($components)) {
                $lastComponent = end($components);
                if (isset($lastComponent['name'])) {
                    $componentName = $lastComponent['name'];
                }
            }
        }
        
        // If still no component name, skip adding the message to avoid "component" placeholder
        if (!$componentName) {
            Log::info('[CHAT] Skipping code generation message - no component name available', [
                'thread_id' => $this->threadId,
                'data' => $data
            ]);
            return;
        }

        $fullMessage = "✅ Code generation complete! I've created the `{$componentName}` component. You can view it in the Code tab and see the live preview in the Preview tab.";

        // Check if this exact message was already added recently (within last 30 seconds) to prevent duplicates
        // Also check for similar messages about code generation completion
        $recentMessage = collect($this->messages)
            ->where('role', 'assistant')
            ->filter(function ($msg) use ($fullMessage, $componentName) {
                if (!isset($msg['content'])) {
                    return false;
                }
                
                // Check for exact match
                if ($msg['content'] === $fullMessage) {
                    return true;
                }
                
                // Check for similar messages about code generation completion
                // This catches cases where component name might differ (e.g., "RegisterForm" vs "component")
                if (str_contains($msg['content'], 'Code generation complete') || 
                    str_contains($msg['content'], "I've created the")) {
                    // If both messages are about code generation completion, consider it a duplicate
                    // even if component names differ (one might be a fallback)
                    return true;
                }
                
                return false;
            })
            ->filter(function ($msg) {
                if (!isset($msg['created_at'])) {
                    return false;
                }
                try {
                    $createdAt = is_string($msg['created_at']) 
                        ? \Carbon\Carbon::parse($msg['created_at']) 
                        : $msg['created_at'];
                    return $createdAt->isAfter(now()->subSeconds(30));
                } catch (\Exception $e) {
                    return false;
                }
            })
            ->first();

        if ($recentMessage) {
            Log::info('[CHAT] Duplicate code generation message detected, skipping', [
                'component_name' => $componentName,
                'thread_id' => $this->threadId,
                'recent_message' => substr($recentMessage['content'] ?? '', 0, 50)
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

    /**
     * Build conversation history with feedback context for AI learning
     */
    protected function buildHistoryWithFeedback(): array
    {
        $history = [];
        $feedbackContext = [];
        
        foreach ($this->messages as $msg) {
            $messageData = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
            
            // Add feedback information to assistant messages
            if ($msg['role'] === 'assistant' && isset($msg['feedback'])) {
                $feedbackContext[] = [
                    'message_id' => $msg['id'],
                    'feedback' => $msg['feedback'],
                    'content_preview' => substr($msg['content'], 0, 100),
                ];
            }
            
            $history[] = $messageData;
        }
        
        // Add feedback summary as system context if there's feedback
        if (!empty($feedbackContext)) {
            $positiveCount = count(array_filter($feedbackContext, fn($f) => $f['feedback'] === 'positive'));
            $negativeCount = count(array_filter($feedbackContext, fn($f) => $f['feedback'] === 'negative'));
            
            if ($positiveCount > 0 || $negativeCount > 0) {
                $feedbackSummary = "User feedback context: {$positiveCount} positive, {$negativeCount} negative feedback(s) on previous responses. ";
                $feedbackSummary .= "Learn from positive examples and avoid patterns that received negative feedback. ";
                
                // Add specific feedback insights
                $recentNegative = array_filter($feedbackContext, fn($f) => $f['feedback'] === 'negative');
                if (!empty($recentNegative)) {
                    $feedbackSummary .= "Recent negative feedback indicates areas to improve. ";
                }
                
                // Prepend feedback context to the last user message
                if (!empty($history) && end($history)['role'] === 'user') {
                    $history[count($history) - 1]['content'] = $feedbackSummary . $history[count($history) - 1]['content'];
                }
            }
        }
        
        return $history;
    }

    /**
     * Submit feedback for an assistant message
     */
    public function submitFeedback(int $messageId, string $feedbackType): void
    {
        if (!in_array($feedbackType, ['positive', 'negative'])) {
            return;
        }

        $message = ChatMessage::where('id', $messageId)
            ->where('chat_thread_id', $this->threadId)
            ->where('role', 'assistant')
            ->first();

        if (!$message) {
            Log::warning('[CHAT] Feedback submission failed - message not found', [
                'message_id' => $messageId,
                'thread_id' => $this->threadId
            ]);
            return;
        }

        // Update message with feedback
        $message->update([
            'feedback' => $feedbackType,
        ]);

        // Update local messages array
        foreach ($this->messages as $key => $msg) {
            if ($msg['id'] === $messageId) {
                $this->messages[$key]['feedback'] = $feedbackType;
                break;
            }
        }

        Log::info('[CHAT] Feedback submitted', [
            'message_id' => $messageId,
            'feedback' => $feedbackType,
            'thread_id' => $this->threadId
        ]);

        // Show toast notification
        if ($feedbackType === 'positive') {
            $this->success('Thank you for your feedback! This helps improve responses.');
        } else {
            $this->info('Thank you for your feedback. We\'ll use this to improve future responses.');
        }
    }

    public function render()
    {
        return view('livewire.chat-interface');
    }
}
