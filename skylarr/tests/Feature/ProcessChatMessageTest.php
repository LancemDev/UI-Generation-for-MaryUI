<?php

namespace Tests\Feature;

use App\Exceptions\AiServiceException;
use App\Jobs\ProcessChatMessage;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\GenerationPipeline;
use App\Services\GenerationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessChatMessageTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private ChatThread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::create(['user_id' => $user->id, 'name' => 'Demo', 'status' => 'creating', 'metadata' => ['components' => []]]);
        $this->thread = ChatThread::create(['user_id' => $user->id, 'project_id' => $this->project->id]);
    }

    private function message(string $role, string $content, string $status = 'complete', ?string $feedback = null): ChatMessage
    {
        return ChatMessage::create(['chat_thread_id' => $this->thread->id, 'role' => $role, 'content' => $content, 'status' => $status, 'feedback' => $feedback]);
    }

    public function test_reply_and_components_are_saved_and_history_includes_feedback(): void
    {
        $this->message('user', 'make a form', 'sent');
        $this->message('assistant', 'Built a form.', 'complete', 'negative');
        $this->message('user', 'try again with tabs', 'sent');
        $assistant = $this->message('assistant', '', 'queued');

        $result = new GenerationResult('Rebuilt it with tabs.', 'test-model');
        $result->recordAction(['op' => 'update', 'component' => 'SignupForm', 'summary' => 'Tabs']);
        $result->previewUrl = 'http://127.0.0.1:8101';

        $this->mock(GenerationPipeline::class)->shouldReceive('run')->once()
            ->withArgs(function ($project, $message, $history, $focus) {
                return $message === 'try again with tabs'
                    && $history[1] === ['role' => 'assistant', 'content' => 'Built a form.', 'feedback' => 'negative']
                    && count($history) === 2
                    && $focus === 'SignupForm';
            })
            ->andReturn($result);

        (new ProcessChatMessage($this->project->id, $assistant->id, 'SignupForm'))->handle(app(GenerationPipeline::class));

        $assistant->refresh();
        $this->assertSame('complete', $assistant->status);
        $this->assertSame('Rebuilt it with tabs.', $assistant->content);
        $this->assertSame('SignupForm', $assistant->metadata['components'][0]['component']);

        $state = $this->project->fresh()->getGenerationState();
        $this->assertFalse($state['is_generating']);
        $this->assertSame('ready', $state['stage']);
        $this->assertSame('SignupForm', $state['component_name']);
        $this->assertSame(1, Notification::where('user_id', $this->project->user_id)->count());
    }

    public function test_remaining_errors_are_reported_in_the_reply(): void
    {
        $this->message('user', 'make a table', 'sent');
        $assistant = $this->message('assistant', '', 'queued');

        $result = new GenerationResult('Built it.');
        $result->recordAction(['op' => 'create', 'component' => 'UserTable', 'summary' => 'Table']);
        $result->errors = ['UserTable: rendering /user-table failed (HTTP 500): Undefined variable $users'];
        $result->repairAttempts = 2;
        $this->mock(GenerationPipeline::class)->shouldReceive('run')->andReturn($result);

        (new ProcessChatMessage($this->project->id, $assistant->id))->handle(app(GenerationPipeline::class));

        $this->assertStringContainsString('Undefined variable $users', $assistant->fresh()->content);
        $this->assertSame('has_errors', $this->project->fresh()->getGenerationState()['stage']);
    }

    public function test_failures_are_shown_to_the_user(): void
    {
        $this->message('user', 'make a table', 'sent');
        $assistant = $this->message('assistant', '', 'thinking');
        $this->project->setGenerationState(['is_generating' => true]);

        (new ProcessChatMessage($this->project->id, $assistant->id))
            ->failed(new AiServiceException('The AI service is not reachable. Please try again in a moment.'));

        $assistant->refresh();
        $this->assertSame('error', $assistant->status);
        $this->assertStringContainsString('not reachable', $assistant->content);
        $this->assertFalse($this->project->fresh()->getGenerationState()['is_generating']);
    }

    public function test_redelivered_job_does_not_regenerate_a_finished_reply(): void
    {
        $this->message('user', 'make a table', 'sent');
        $assistant = $this->message('assistant', 'Built it.', 'complete');
        $this->mock(GenerationPipeline::class)->shouldNotReceive('run');

        (new ProcessChatMessage($this->project->id, $assistant->id))->handle(app(GenerationPipeline::class));

        $this->assertSame('Built it.', $assistant->fresh()->content);
    }
}
