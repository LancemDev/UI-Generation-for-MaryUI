<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatMessage;
use App\Livewire\ChatInterface;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ChatInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return Project::create(['user_id' => $user->id, 'name' => 'Demo', 'status' => 'creating', 'metadata' => ['components' => []]]);
    }

    public function test_sending_a_message_queues_generation_without_blocking(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->project($user);

        Livewire::actingAs($user)
            ->test(ChatInterface::class, ['projectId' => $project->id])
            ->set('message', 'Explain wire:model')
            ->call('sendMessage')
            ->assertSet('message', '')
            ->assertCount('messages', 2);

        $assistant = ChatMessage::where('role', 'assistant')->sole();
        $this->assertSame('queued', $assistant->status);

        Queue::assertPushed(ProcessChatMessage::class, fn ($job) => $job->assistantMessageId === $assistant->id
            && $job->projectId === $project->id
            && $job->focusComponent === null);
    }

    public function test_every_message_goes_to_the_model_not_only_keyword_matches(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatInterface::class, ['projectId' => $this->project($user)->id])
            ->set('message', 'can you explain how this page works?')
            ->call('sendMessage');

        Queue::assertPushed(ProcessChatMessage::class, 1);
    }

    public function test_selected_component_is_passed_as_focus(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatInterface::class, ['projectId' => $this->project($user)->id])
            ->call('selectComponent', 'SignupForm')
            ->set('message', 'make the button bigger')
            ->call('sendMessage');

        Queue::assertPushed(ProcessChatMessage::class, fn ($job) => $job->focusComponent === 'SignupForm');
    }

    public function test_cannot_send_while_a_reply_is_pending(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ChatInterface::class, ['projectId' => $this->project($user)->id])
            ->set('message', 'first')
            ->call('sendMessage')
            ->set('message', 'second')
            ->call('sendMessage');

        Queue::assertPushed(ProcessChatMessage::class, 1);
    }

    public function test_refresh_pending_picks_up_finished_replies(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(ChatInterface::class, ['projectId' => $this->project($user)->id])
            ->set('message', 'build a form')
            ->call('sendMessage');

        ChatMessage::where('role', 'assistant')->update([
            'content' => 'Built SignupForm.',
            'status' => 'complete',
            'metadata' => ['components' => [['op' => 'create', 'component' => 'SignupForm', 'summary' => 'Form', 'route' => '/signup-form']]],
        ]);

        $component->call('refreshPending')
            ->assertSet('messages.1.status', 'complete')
            ->assertSet('messages.1.content', 'Built SignupForm.')
            ->assertSet('messages.1.components.0.component', 'SignupForm')
            ->assertDispatched('generation-finished');
    }

    public function test_users_cannot_load_someone_elses_project_thread(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $this->project($owner);

        Livewire::actingAs(User::factory()->create())
            ->test(ChatInterface::class, ['projectId' => $project->id])
            ->set('message', 'hello')
            ->call('sendMessage');

        Queue::assertNothingPushed();
    }

    public function test_thread_and_project_cannot_be_changed_from_the_browser(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($user)
            ->test(ChatInterface::class, ['projectId' => $project->id])
            ->set('threadId', 999);
    }

    public function test_feedback_on_another_users_thread_is_ignored(): void
    {
        $owner = User::factory()->create();
        $thread = ChatThread::create(['user_id' => $owner->id, 'project_id' => $this->project($owner)->id]);
        $message = ChatMessage::create(['chat_thread_id' => $thread->id, 'role' => 'assistant', 'content' => 'Hi', 'status' => 'complete']);

        $intruder = User::factory()->create();
        $component = Livewire::actingAs($intruder)->test(ChatInterface::class, ['projectId' => $this->project($intruder)->id]);
        // Simulate a tampered snapshot pointing at the other user's thread.
        $instance = $component->instance();
        $instance->threadId = $thread->id;
        $instance->submitFeedback($message->id, 'negative');

        $this->assertNull($message->fresh()->feedback);
    }
}
