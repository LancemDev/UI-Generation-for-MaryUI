<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatMessage;
use App\Livewire\CodeGenerationEngine;
use App\Models\Project;
use App\Models\User;
use App\Services\DockerPreviewService;
use App\Services\GenerationPipeline;
use App\Services\GenerationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class CodeGenerationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_restoring_a_version_rewrites_it_without_the_ai(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Demo', 'status' => 'active', 'metadata' => ['components' => []]]);
        $project->addComponent('SignupForm', '/signup-form', 'signup-form', '<?php v1', '<div>v1</div>');
        $project->addComponent('SignupForm', '/signup-form', 'signup-form', '<?php v2', '<div>v2</div>');

        $preview = $this->mock(DockerPreviewService::class);
        $preview->shouldReceive('isContainerRunning')->andReturn(false);
        $this->mock(GenerationPipeline::class)->shouldReceive('insertComponents')->once()
            ->withArgs(fn ($p, $components) => $components['SignupForm']['php'] === '<?php v1' && $components['SignupForm']['blade'] === '<div>v1</div>')
            ->andReturn(new GenerationResult('Restored SignupForm.'));

        $component = Livewire::actingAs($user)
            ->test(CodeGenerationEngine::class, ['projectId' => $project->id])
            ->set('componentName', 'SignupForm');

        $this->assertSame([0], array_column($component->instance()->getVersionsProperty(), 'index'));
        $component->call('restoreVersion', 0);
    }

    public function test_selected_code_is_sent_to_the_chat(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Demo', 'status' => 'active', 'metadata' => ['components' => []]]);
        $project->addComponent('SignupForm', '/signup-form', 'signup-form', '<?php', '<div></div>');
        $this->mock(DockerPreviewService::class)->shouldReceive('isContainerRunning')->andReturn(false);

        Livewire::actingAs($user)
            ->test(CodeGenerationEngine::class, ['projectId' => $project->id])
            ->set('selectedFilePath', '/var/www/html/resources/views/livewire/signup-form.blade.php')
            ->call('askAboutSelection', '<x-button label="Save" />')
            ->assertDispatched('element-selected', snippet: '<x-button label="Save" />', component: 'SignupForm');
    }

    public function test_restore_waits_for_a_running_generation(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Demo', 'status' => 'active', 'metadata' => ['components' => []]]);
        $project->addComponent('SignupForm', '/signup-form', 'signup-form', '<?php v1', '<div>v1</div>');
        $project->addComponent('SignupForm', '/signup-form', 'signup-form', '<?php v2', '<div>v2</div>');
        $this->mock(DockerPreviewService::class)->shouldReceive('isContainerRunning')->andReturn(false);
        $this->mock(GenerationPipeline::class)->shouldNotReceive('insertComponents');

        // The queued job holds the project lock while it generates.
        $lock = Cache::lock(ProcessChatMessage::projectLock($project->id)->getLockKey(null), 60);
        $this->assertTrue($lock->get());

        Livewire::actingAs($user)
            ->test(CodeGenerationEngine::class, ['projectId' => $project->id])
            ->set('componentName', 'SignupForm')
            ->call('restoreVersion', 0);

        $lock->release();
        $this->assertSame('<?php v2', $project->fresh()->getComponent('SignupForm')['code']);
    }
}
