<?php

/**
 * Extract a machine-readable reference of MaryUI components and Heroicons
 * from an installed Laravel app's vendor directory.
 *
 * Usage:
 *   php scripts/knowledge/extract_maryui.php preview-reference > scripts/knowledge/maryui_reference.json
 *
 * The output is consumed by scripts/services/knowledge.py to ground generation
 * in the props that actually exist in the MaryUI version the preview runs.
 */

$appPath = rtrim($argv[1] ?? 'preview-reference', '/');
$autoload = "{$appPath}/vendor/autoload.php";

if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found under {$appPath}. Run composer install there first.\n");
    exit(1);
}

require $autoload;

$maryDir = "{$appPath}/vendor/robsontenorio/mary";
$provider = file_get_contents("{$maryDir}/src/MaryServiceProvider.php");

preg_match_all("/\\\$prefix\s*\.\s*'([a-z0-9-]+)'\s*,\s*([A-Za-z]+)::class/", $provider, $matches, PREG_SET_ORDER);

$installed = json_decode(file_get_contents("{$appPath}/vendor/composer/installed.json"), true);
$packages = $installed['packages'] ?? $installed;
$maryVersion = null;
foreach ($packages as $package) {
    if ($package['name'] === 'robsontenorio/mary') {
        $maryVersion = $package['version'];
    }
}

function kebab(string $name): string
{
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
}

function exportDefault(ReflectionParameter $param): mixed
{
    if (!$param->isDefaultValueAvailable()) {
        return null;
    }
    $value = $param->getDefaultValue();

    return is_scalar($value) || $value === null ? $value : json_encode($value);
}

$components = [];

foreach ($matches as [, $tag, $class]) {
    $fqcn = "Mary\\View\\Components\\{$class}";
    if (!class_exists($fqcn)) {
        continue;
    }

    $reflection = new ReflectionClass($fqcn);
    $constructor = $reflection->getConstructor();
    $source = file_get_contents($reflection->getFileName());

    $props = [];
    $slots = [];
    foreach ($constructor?->getParameters() ?? [] as $param) {
        $type = (string) ($param->getType() ?? 'mixed');
        $name = $param->getName();
        // Mary declares named slots as untyped/mixed constructor params.
        if ($type === 'mixed') {
            $slots[] = $name;
            continue;
        }
        $props[] = [
            'name' => kebab($name),
            'type' => ltrim($type, '?'),
            'default' => exportDefault($param),
        ];
    }

    $components[] = [
        'tag' => "x-{$tag}",
        'class' => $class,
        'props' => $props,
        'slots' => $slots,
        'uses_default_slot' => str_contains($source, '{{ $slot }}'),
    ];
}

$iconDir = "{$appPath}/vendor/blade-ui-kit/blade-heroicons/resources/svg";
$icons = [];
foreach (glob("{$iconDir}/*.svg") as $file) {
    $icons[] = basename($file, '.svg');
}
sort($icons);

echo json_encode([
    'mary_version' => $maryVersion,
    'components' => $components,
    'heroicons' => $icons,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
