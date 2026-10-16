<div class="p-6">
    <x-header title="Dashboard" subtitle="Your business at a glance" separator>
        <x-slot:actions>
            <x-button icon="o-arrow-path" class="btn-ghost" wire:click="refresh" spinner />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($stats as $stat)
            <x-stat :title="$stat['title']" :value="$stat['value']" :icon="$stat['icon']" :description="$stat['description']" class="shadow" />
        @endforeach
    </div>

    <div class="grid gap-6 mt-6 lg:grid-cols-3">
        <x-card title="Recent orders" separator shadow class="lg:col-span-2">
            <x-table :headers="$headers" :rows="$orders">
                @scope('cell_status', $order)
                    <x-badge :value="$order['status']" :class="match ($order['status']) { 'Paid' => 'badge-success', 'Pending' => 'badge-warning', default => 'badge-ghost' }" />
                @endscope
            </x-table>
        </x-card>

        <x-card title="Activity" separator shadow>
            <div class="space-y-4">
                @foreach ($activity as $item)
                    <div class="flex items-start gap-3">
                        <x-avatar :placeholder="substr($item['name'], 0, 1)" class="!w-9" />
                        <div class="text-sm">
                            <span class="font-semibold">{{ $item['name'] }}</span> {{ $item['action'] }}
                            <div class="opacity-60">{{ $item['time'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>
</div>
