<?php

namespace App\Services;

use App\Models\Project;
use InvalidArgumentException;

/**
 * Ready-made starter components (resources/templates) that are inserted
 * instantly and then edited through chat, instead of generated from scratch.
 *
 * Each template directory holds component.php and view.blade.php using the
 * placeholders __CLASS__ and __VIEW__.
 */
class TemplateLibrary
{
    /**
     * @return list<array{id: string, title: string, name: string, icon: string, description: string}>
     */
    public function all(): array
    {
        return json_decode(file_get_contents($this->path('templates.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function find(string $id): array
    {
        foreach ($this->all() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        throw new InvalidArgumentException("Unknown template: {$id}");
    }

    /**
     * The template's default component name, numbered if the project already uses it.
     */
    public function availableName(array $template, Project $project): string
    {
        $taken = array_column($project->getComponents(), 'name');
        $name = $template['name'];

        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = $template['name'] . $i;
        }

        return $name;
    }

    /**
     * @return array{php: string, blade: string}
     */
    public function files(array $template, string $name): array
    {
        DockerPreviewService::assertComponentName($name);
        $directory = $template['id'];

        return [
            'php' => strtr(file_get_contents($this->path("{$directory}/component.php")), [
                '__CLASS__' => $name,
                '__VIEW__' => 'livewire.' . DockerPreviewService::kebab($name),
            ]),
            'blade' => file_get_contents($this->path("{$directory}/view.blade.php")),
        ];
    }

    private function path(string $relative): string
    {
        return resource_path("templates/{$relative}");
    }
}
