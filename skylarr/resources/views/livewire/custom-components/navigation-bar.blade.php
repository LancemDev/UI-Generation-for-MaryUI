<div>
<x-nav sticky full-width class="bg-primary/90 text-base-100 border-b border-secondary/25 backdrop-blur supports-[backdrop-filter]:bg-primary/60">
 
 <x-slot:brand>
     {{-- Drawer toggle for "main-drawer" --}}
    <label for="main-drawer" class="lg:hidden mr-3 text-base-100/90 hover:text-base-100">
        <x-icon name="o-bars-3" class="cursor-pointer" />
     </label>

     {{-- Brand --}}
    <div class="font-semibold tracking-tight">
        SKYLARR
    </div>
 </x-slot:brand>

 {{-- Right side actions --}}
 <x-slot:actions>
    <x-theme-toggle />
    <x-button label="Messages" icon="o-envelope" link="###" class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" responsive />
    <x-button label="Notifications" icon="o-bell" link="###" class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" responsive />
    <x-dropdown class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" icon="o-user" right>
        <x-menu-item title="Logout" icon="o-power" class="text-red-500" wire:click.stop="logout" spinner="logout" />
        <x-menu-item title="Settings" icon="o-cog-6-tooth" class="text-blue-500" wire:click.stop="settings" spinner="settings" />
    </x-dropdown>
 </x-slot:actions>
</x-nav>
</div>