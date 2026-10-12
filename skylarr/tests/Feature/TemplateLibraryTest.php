<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\TemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;

class TemplateLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_template_renders_with_maryui(): void
    {
        $library = app(TemplateLibrary::class);
        $directory = storage_path('framework/testing/templates');
        File::ensureDirectoryExists("{$directory}/livewire");
        View::addLocation($directory);

        foreach ($library->all() as $template) {
            // A unique class per template, in its own namespace, rendered by Livewire like the preview does.
            $name = 'Tpl' . str_replace('-', '', ucwords($template['id'], '-'));
            $files = $library->files($template, $name);
            $namespace = 'Tests\\Rendered\\' . $name;
            eval('?>' . str_replace('namespace App\\Livewire;', "namespace {$namespace};", $files['php']));
            File::put("{$directory}/livewire/" . \App\Services\DockerPreviewService::kebab($name) . '.blade.php', $files['blade']);

            Livewire::test("{$namespace}\\{$name}")->assertOk();
        }

        File::deleteDirectory($directory);
    }

    public function test_names_are_numbered_when_taken(): void
    {
        $library = app(TemplateLibrary::class);
        $template = $library->find('crud-table');
        $project = new Project(['metadata' => ['components' => [['name' => 'UsersTable'], ['name' => 'UsersTable2']]]]);

        $this->assertSame('UsersTable3', $library->availableName($template, $project));
        $this->assertStringContainsString("view('livewire.users-table3'", $library->files($template, 'UsersTable3')['php']);
    }
}
