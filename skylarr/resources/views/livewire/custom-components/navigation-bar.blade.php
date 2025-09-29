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
    <x-button label="Profile" icon="o-user" link="##" class="btn-ghost btn-sm text-base-100/90 hover:text-base-100 hover:bg-secondary" responsive />
 </x-slot:actions>
</x-nav>
</div>