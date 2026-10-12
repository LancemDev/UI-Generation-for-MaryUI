<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\AiGateway;
use App\Services\DockerPreviewService;
use App\Services\GenerationPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GenerationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private MockInterface $ai;
    private MockInterface $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::create([
            'user_id' => $user->id,
            'name' => 'Demo',
            'status' => 'active',
            'container_id' => 'abc123',
            'metadata' => ['components' => []],
        ]);
        $this->ai = $this->mock(AiGateway::class);
        $this->preview = $this->mock(DockerPreviewService::class);
        $this->preview->shouldReceive('isContainerRunning')->andReturn(true)->byDefault();
        $this->preview->shouldReceive('readComponent')->andReturn(['php' => '<?php // php', 'blade' => '<div></div>'])->byDefault();
    }

    private function action(string $name, string $op = 'create'): array
    {
        return ['op' => $op, 'component' => $name, 'summary' => "{$name} summary", 'php' => "<?php class {$name} {}", 'blade' => '<div></div>'];
    }

    private function response(array $actions, string $reply = 'Done.'): array
    {
        return ['reply' => $reply, 'actions' => $actions, 'warnings' => [], 'model' => 'test-model', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    public function test_questions_do_not_touch_the_preview(): void
    {
        $this->ai->shouldReceive('generate')->once()->andReturn($this->response([], 'It binds a property.'));
        $this->preview->shouldNotReceive('getOrCreateProjectContainer', 'writeComponent');

        $result = app(GenerationPipeline::class)->run($this->project, 'what is wire:model?');

        $this->assertSame('It binds a property.', $result->reply);
        $this->assertFalse($result->hasChanges());
    }

    public function test_existing_component_source_is_sent_with_the_request(): void
    {
        $this->project->addComponent('SignupForm', '/signup-form', 'signup-form', '<?php old', '<div>old</div>');
        $this->preview->shouldReceive('readComponent')->with(Mockery::any(), 'SignupForm')
            ->andReturn(['php' => '<?php current', 'blade' => '<div>current</div>']);

        $this->ai->shouldReceive('generate')->once()->withArgs(function ($payload) {
            return $payload['components'] === [['name' => 'SignupForm', 'route' => '/signup-form', 'php' => '<?php current', 'blade' => '<div>current</div>']]
                && $payload['focus_component'] === 'SignupForm';
        })->andReturn($this->response([]));

        app(GenerationPipeline::class)->run($this->project, 'add a phone field', [], 'SignupForm');
    }

    public function test_components_are_written_validated_and_versioned(): void
    {
        $this->ai->shouldReceive('generate')->once()->andReturn($this->response([$this->action('SignupForm')]));
        $this->preview->shouldReceive('getOrCreateProjectContainer')->once()->andReturn('http://127.0.0.1:8101');
        $this->preview->shouldReceive('writeComponent')->once()->with(Mockery::any(), 'SignupForm', Mockery::any(), '<div></div>');
        $this->preview->shouldReceive('validateComponent')->once()->andReturn([]);

        $result = app(GenerationPipeline::class)->run($this->project, 'build a signup form');

        $this->assertSame(['SignupForm'], array_keys($result->components));
        $this->assertSame('/signup-form', $result->components['SignupForm']['route']);
        $this->assertSame([], $result->errors);
        $this->assertSame('http://127.0.0.1:8101', $result->previewUrl);
        $this->assertNotNull($this->project->fresh()->getComponent('SignupForm'));
    }

    public function test_render_errors_are_sent_back_for_repair(): void
    {
        config(['skylarr.ai.max_repair_attempts' => 2]);
        $this->preview->shouldReceive('getOrCreateProjectContainer')->andReturn('http://127.0.0.1:8101');
        $this->preview->shouldReceive('writeComponent')->twice();
        $this->preview->shouldReceive('validateComponent')->twice()->andReturn(['SignupForm: Undefined variable $roles'], []);

        $this->ai->shouldReceive('generate')->once()->ordered()
            ->withArgs(fn ($payload) => !isset($payload['errors']))
            ->andReturn($this->response([$this->action('SignupForm')]));
        $this->ai->shouldReceive('generate')->once()->ordered()
            ->withArgs(fn ($payload) => $payload['errors'] === ['SignupForm: Undefined variable $roles'])
            ->andReturn($this->response([$this->action('SignupForm', 'update')], 'Fixed it.'));

        $result = app(GenerationPipeline::class)->run($this->project, 'build a signup form');

        $this->assertSame(1, $result->repairAttempts);
        $this->assertSame([], $result->errors);
        $this->assertSame('create', $result->components['SignupForm']['op']);
        $this->assertSame(['input_tokens' => 20, 'output_tokens' => 10], $result->usage);
    }

    public function test_repairs_stop_at_the_configured_limit(): void
    {
        config(['skylarr.ai.max_repair_attempts' => 1]);
        $this->preview->shouldReceive('getOrCreateProjectContainer')->andReturn('http://127.0.0.1:8101');
        $this->preview->shouldReceive('writeComponent');
        $this->preview->shouldReceive('validateComponent')->andReturn(['SignupForm: still broken']);
        $this->ai->shouldReceive('generate')->twice()->andReturn($this->response([$this->action('SignupForm')]));

        $result = app(GenerationPipeline::class)->run($this->project, 'build a signup form');

        $this->assertSame(1, $result->repairAttempts);
        $this->assertSame(['SignupForm: still broken'], $result->errors);
    }

    public function test_invalid_component_names_become_warnings(): void
    {
        $this->ai->shouldReceive('generate')->once()->andReturn($this->response([$this->action('bad name')]));
        $this->preview->shouldReceive('getOrCreateProjectContainer')->andReturn('http://127.0.0.1:8101');
        $this->preview->shouldReceive('writeComponent')->andThrow(new \InvalidArgumentException('Invalid component name: bad name'));
        $this->preview->shouldNotReceive('validateComponent');

        $result = app(GenerationPipeline::class)->run($this->project, 'x');

        $this->assertFalse($result->hasChanges());
        $this->assertContains('Invalid component name: bad name', $result->warnings);
    }
}
