<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DockerPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_users_cannot_remove_all_containers(): void
    {
        $this->mock(DockerPreviewService::class)->shouldNotReceive('removeAllSkylarrContainers');

        $this->actingAs(User::factory()->create())
            ->deleteJson('/api/preview/remove-all')
            ->assertForbidden();
    }

    public function test_regular_users_cannot_remove_containers_by_port_or_cleanup(): void
    {
        $this->mock(DockerPreviewService::class)->shouldNotReceive('removeContainerByPort', 'cleanupOrphanedContainers');
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/api/preview/port/8101')->assertForbidden();
        $this->actingAs($user)->postJson('/api/preview/cleanup-orphaned')->assertForbidden();
    }

    public function test_admins_can_remove_all_containers(): void
    {
        config(['skylarr.admin_emails' => ['ops@example.com']]);
        $this->mock(DockerPreviewService::class)->shouldReceive('removeAllSkylarrContainers')->once()->andReturn(3);

        $this->actingAs(User::factory()->create(['email' => 'Ops@Example.com']))
            ->deleteJson('/api/preview/remove-all')
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
