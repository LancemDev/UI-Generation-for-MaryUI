@php
    $isSelected = isset($item['path']) && $item['path'] === $selectedFilePath;
    $isFolder = $item['type'] === 'folder';
    $hasChildren = $isFolder && isset($item['children']) && count($item['children']) > 0;
    $defaultOpen = $isFolder && $level < 2;
@endphp

<div class="file-tree-item" x-data="{ open: @js($defaultOpen) }">
    @if($isFolder)
        <button 
            @click="open = !open"
            class="w-full text-left px-2 py-1 text-xs hover:bg-gray-100 rounded flex items-center gap-1"
            style="padding-left: {{ ($level * 12) + 8 }}px;"
        >
            <x-icon name="o-chevron-right" class="w-3 h-3 transition-transform" x-bind:class="open ? 'rotate-90' : ''" />
            <x-icon name="o-folder" class="w-3 h-3 text-yellow-500" />
            <span class="font-medium text-gray-700">{{ $key }}</span>
        </button>
        @if($hasChildren)
            <div x-show="open" x-cloak class="ml-2">
                @foreach($item['children'] as $childKey => $childItem)
                    @include('livewire.partials.file-tree-item', [
                        'item' => $childItem,
                        'key' => $childKey,
                        'level' => $level + 1,
                        'selectedFilePath' => $selectedFilePath
                    ])
                @endforeach
            </div>
        @endif
    @else
        <button 
            wire:click="selectFile('{{ $item['path'] }}')" 
            class="w-full text-left px-2 py-1 text-xs hover:bg-gray-100 rounded flex items-center gap-1 {{ $isSelected ? 'bg-blue-100 text-blue-800' : 'text-gray-600' }}"
            style="padding-left: {{ ($level * 12) + 8 }}px;"
            title="{{ $item['path'] }}"
        >
            <x-icon name="o-document" class="w-3 h-3 {{ $isSelected ? 'text-blue-600' : 'text-gray-400' }}" />
            <span class="truncate">{{ $key }}</span>
        </button>
    @endif
</div>

