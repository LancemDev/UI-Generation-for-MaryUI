<div>
<x-nav sticky full-width>
 
 <x-slot:brand>
     {{-- Drawer toggle for "main-drawer" --}}
     <label for="main-drawer" class="lg:hidden mr-3">
         <x-icon name="o-bars-3" class="cursor-pointer" />
     </label>

     {{-- Brand --}}
     <div>SKYLARR</div>
 </x-slot:brand>

 {{-- Right side actions --}}
 <x-slot:actions>
     <x-button label="Messages" icon="o-envelope" link="###" class="btn-ghost btn-sm" responsive />
     <x-button label="Notifications" icon="o-bell" link="###" class="btn-ghost btn-sm" responsive />
 </x-slot:actions>
</x-nav>
</div>