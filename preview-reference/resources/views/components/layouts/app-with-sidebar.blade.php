<div>
    {{-- NAVBAR mobile only --}}
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <div class="ml-5 pt-5">App</div>
        </x-slot:brand>
        <x-slot:actions>
            <label for="main-drawer" class="lg:hidden mr-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    {{-- MAIN --}}
    <x-main full-width>
        {{-- SIDEBAR --}}
        <x-slot:sidebar 
            drawer="main-drawer" 
            collapsible 
            collapse-text="Hide it"
            class="bg-base-100 lg:bg-inherit"
            left
            left-mobile
        >
            {{-- BRAND --}}
            <div class="ml-5 pt-5">App</div>

            {{-- MENU --}}
            <x-menu activate-by-route>
                <x-menu-item title="Home" icon="o-sparkles" link="/" />
                <x-menu-item title="Users" icon="o-users" link="/users" />
                <x-menu-item title="Settings" icon="o-cog-6-tooth" link="/settings" />
                <x-menu-item title="Logout" icon="o-power" link="/logout" />
            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{-- Toast --}}
    <x-toast />
</div>

