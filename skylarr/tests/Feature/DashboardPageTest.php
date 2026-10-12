<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_chat_and_preview_panels(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Demo', 'status' => 'creating', 'metadata' => ['components' => []]]);
        $thread = ChatThread::create(['user_id' => $user->id, 'project_id' => $project->id]);
        ChatMessage::create(['chat_thread_id' => $thread->id, 'role' => 'user', 'content' => 'build a form', 'status' => 'sent']);
        ChatMessage::create([
            'chat_thread_id' => $thread->id,
            'role' => 'assistant',
            'content' => 'Built SignupForm.',
            'status' => 'complete',
            'metadata' => ['components' => [['op' => 'create', 'component' => 'SignupForm', 'summary' => 'A form', 'route' => '/signup-form']]],
        ]);
        ChatMessage::create(['chat_thread_id' => $thread->id, 'role' => 'assistant', 'content' => '', 'status' => 'building']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Built SignupForm.')
            ->assertSee('+ SignupForm')
            ->assertSee('building preview...')
            ->assertSee('wire:poll.1500ms="refreshPending"', false);
    }
}
