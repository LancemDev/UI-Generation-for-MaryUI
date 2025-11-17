<div
    
>
    <x-main full-width>
        <x-slot:content>
        <div 
            class="text-white overflow-x-hidden"
            style="background-color: rgb(239, 150, 81);"
            >

            <!-- Login Modal -->
            <x-modal wire:model="loginModal" class="backdrop-blur">
                <x-header title="Login to SKYLARR" />
                <x-form wire:submit="loginUser">
                    <x-input wire:model="email" label="Continue with email" inline icon="o-envelope" />
                    <x-input wire:model="password" type="password" label="Password" inline icon="o-lock-closed" />
                    <a href="{{ route('password.request') }}" class="flex items-right text-sm text-gray-400 hover:text-indigo-500">Forgot password?</a>
                    <div class="mt-3 flex flex-col gap-2">
                        <a 
                            href="{{ route('oauth.redirect', 'google') }}" 
                            class="w-full"
                            x-data="{ loading: false }"
                            @click="loading = true"
                        >
                            <x-button 
                                class="w-full btn-ghost border-secondary text-secondary hover:bg-secondary/10"
                                x-bind:disabled="loading"
                            >
                                <span class="inline-flex items-center gap-2" x-show="!loading">
                                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 48 48" class="shrink-0">
                                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.72 1.22 9.22 3.6l6.9-6.9C35.9 2.2 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l8.04 6.24C12.46 13.58 17.73 9.5 24 9.5z"/>
                                        <path fill="#4285F4" d="M46.5 24c0-1.64-.15-3.2-.44-4.7H24v9.1h12.7c-.55 2.96-2.24 5.47-4.76 7.16l7.28 5.65C43.7 36.92 46.5 30.92 46.5 24z"/>
                                        <path fill="#FBBC05" d="M10.6 28.46A14.5 14.5 0 0 1 9.5 24c0-1.54.26-3.02.72-4.4l-8.04-6.24A24 24 0 0 0 0 24c0 3.84.92 7.46 2.56 10.66l8.04-6.2z"/>
                                        <path fill="#34A853" d="M24 48c6.48 0 11.94-2.14 15.92-5.84l-7.28-5.65c-2.01 1.35-4.6 2.15-8.64 2.15-6.27 0-11.54-4.08-13.4-9.46l-8.04 6.2C6.51 42.62 14.62 48 24 48z"/>
                                        <path fill="none" d="M0 0h48v48H0z"/>
                                    </svg>
                                    <span>Continue with Google</span>
                                </span>
                                <span class="inline-flex items-center gap-2" x-show="loading" x-cloak>
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Connecting...</span>
                                </span>
                            </x-button>
                        </a>
                        <a 
                            href="{{ route('oauth.redirect', 'github') }}" 
                            class="w-full"
                            x-data="{ loading: false }"
                            @click="loading = true"
                        >
                            <x-button 
                                class="w-full btn-ghost border-secondary text-secondary hover:bg-secondary/10"
                                x-bind:disabled="loading"
                            >
                                <span class="inline-flex items-center gap-2" x-show="!loading">
                                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" class="shrink-0">
                                        <path d="M12 .5C5.73.5.9 5.33.9 11.6c0 4.87 3.16 9 7.55 10.45.55.1.75-.25.75-.55v-2c-3.07.67-3.72-1.32-3.72-1.32-.5-1.22-1.23-1.55-1.23-1.55-1-.67.08-.66.08-.66 1.1.08 1.67 1.15 1.67 1.15.98 1.66 2.58 1.18 3.22.9.1-.72.38-1.2.7-1.47-2.45-.28-5.02-1.22-5.02-5.45 0-1.2.43-2.17 1.14-2.94-.12-.28-.5-1.43.1-2.98 0 0 .95-.3 3.1 1.12.9-.25 1.86-.37 2.82-.38.96 0 1.92.13 2.82.38 2.15-1.42 3.1-1.12 3.1-1.12.6 1.55.22 2.7.1 2.98.72.77 1.14 1.74 1.14 2.94 0 4.24-2.58 5.17-5.04 5.44.4.35.76 1.05.76 2.13v3.16c0 .3.2.66.76.55 4.38-1.45 7.54-5.58 7.54-10.45C23.1 5.33 18.27.5 12 .5Z"/>
                                    </svg>
                                    <span>Continue with GitHub</span>
                                </span>
                                <span class="inline-flex items-center gap-2" x-show="loading" x-cloak>
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Connecting...</span>
                                </span>
                            </x-button>
                        </a>
                    </div>
                    <x-slot:actions>
                        <x-button type="submit" label="Login" class="rounded-md bg-green-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-green-700 focus:shadow-none active:bg-green-700 hover:bg-green-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" spinner="loginUser" />
                    </x-slot:actions>
                </x-form>
            </x-modal>

            <!-- Register -->
            <x-modal wire:model="registerModal" class="backdrop-blur">
                <x-header title="Create a new account" />
                <x-form wire:submit="register">
                    <x-input wire:model="name" label="Name" inline icon="o-user" />
                    <x-input wire:model="email" label="Email" inline icon="o-envelope" />
                    <x-input wire:model="password" type="password" label="Password" inline icon="o-lock-closed" />
                    <div class="mt-3 flex flex-col gap-2">
                        <a 
                            href="{{ route('oauth.redirect', 'google') }}" 
                            class="w-full"
                            x-data="{ loading: false }"
                            @click="loading = true"
                        >
                            <x-button 
                                class="w-full btn-ghost border-secondary text-secondary hover:bg-secondary/10"
                                x-bind:disabled="loading"
                            >
                                <span class="inline-flex items-center gap-2" x-show="!loading">
                                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 48 48" class="shrink-0">
                                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.72 1.22 9.22 3.6l6.9-6.9C35.9 2.2 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l8.04 6.24C12.46 13.58 17.73 9.5 24 9.5z"/>
                                        <path fill="#4285F4" d="M46.5 24c0-1.64-.15-3.2-.44-4.7H24v9.1h12.7c-.55 2.96-2.24 5.47-4.76 7.16l7.28 5.65C43.7 36.92 46.5 30.92 46.5 24z"/>
                                        <path fill="#FBBC05" d="M10.6 28.46A14.5 14.5 0 0 1 9.5 24c0-1.54.26-3.02.72-4.4l-8.04-6.24A24 24 0 0 0 0 24c0 3.84.92 7.46 2.56 10.66l8.04-6.2z"/>
                                        <path fill="#34A853" d="M24 48c6.48 0 11.94-2.14 15.92-5.84l-7.28-5.65c-2.01 1.35-4.6 2.15-8.64 2.15-6.27 0-11.54-4.08-13.4-9.46l-8.04 6.2C6.51 42.62 14.62 48 24 48z"/>
                                        <path fill="none" d="M0 0h48v48H0z"/>
                                    </svg>
                                    <span>Continue with Google</span>
                                </span>
                                <span class="inline-flex items-center gap-2" x-show="loading" x-cloak>
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Connecting...</span>
                                </span>
                            </x-button>
                        </a>
                        <a 
                            href="{{ route('oauth.redirect', 'github') }}" 
                            class="w-full"
                            x-data="{ loading: false }"
                            @click="loading = true"
                        >
                            <x-button 
                                class="w-full btn-ghost border-secondary text-secondary hover:bg-secondary/10"
                                x-bind:disabled="loading"
                            >
                                <span class="inline-flex items-center gap-2" x-show="!loading">
                                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" class="shrink-0">
                                        <path d="M12 .5C5.73.5.9 5.33.9 11.6c0 4.87 3.16 9 7.55 10.45.55.1.75-.25.75-.55v-2c-3.07.67-3.72-1.32-3.72-1.32-.5-1.22-1.23-1.55-1.23-1.55-1-.67.08-.66.08-.66 1.1.08 1.67 1.15 1.67 1.15.98 1.66 2.58 1.18 3.22.9.1-.72.38-1.2.7-1.47-2.45-.28-5.02-1.22-5.02-5.45 0-1.2.43-2.17 1.14-2.94-.12-.28-.5-1.43.1-2.98 0 0 .95-.3 3.1 1.12.9-.25 1.86-.37 2.82-.38.96 0 1.92.13 2.82.38 2.15-1.42 3.1-1.12 3.1-1.12.6 1.55.22 2.7.1 2.98.72.77 1.14 1.74 1.14 2.94 0 4.24-2.58 5.17-5.04 5.44.4.35.76 1.05.76 2.13v3.16c0 .3.2.66.76.55 4.38-1.45 7.54-5.58 7.54-10.45C23.1 5.33 18.27.5 12 .5Z"/>
                                    </svg>
                                    <span>Continue with GitHub</span>
                                </span>
                                <span class="inline-flex items-center gap-2" x-show="loading" x-cloak>
                                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Connecting...</span>
                                </span>
                            </x-button>
                        </a>
                    </div>
                    <x-slot:actions>
                        <x-button type="submit" label="Register" class="rounded-md bg-green-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-green-700 focus:shadow-none active:bg-green-700 hover:bg-green-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" spinner="register" />
                    </x-slot:actions>
                </x-form>
            </x-modal>

            <x-modal wire:model="waitListModal" class="backdrop-blur" >
                <x-header title="Join the waitlist" />
                <x-form wire:submit="joinWaitList">
                    <x-input wire:model="waitListEmail" label="Enter your email" inline icon="o-envelope" />
                </x-form>

                <x-slot:actions>
                    <x-button type="submit" label="Join Waitlist" class="rounded-md bg-slate-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-slate-700 focus:shadow-none active:bg-slate-700 hover:bg-slate-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" />
                </x-slot:actions>
            </x-modal>

            <!-- Hero Section -->
            <section class="relative min-h-screen flex items-center justify-center overflow-hidden">
                <!-- Animated Background -->
                <div class="absolute inset-0">
                    <div class="absolute top-20 left-10 w-72 h-72" 
                         style="background-color: rgb(63, 125, 88); mix-blend-mode: multiply; filter: blur(40px); opacity: 0.2;" 
                         class="rounded-full floating"></div>
                    <div class="absolute top-40 right-10 w-72 h-72" 
                         style="background-color: rgb(239, 150, 81); mix-blend-mode: multiply; filter: blur(40px); opacity: 0.2;" 
                         class="rounded-full floating-delayed"></div>
                    <div class="absolute -bottom-8 left-20 w-72 h-72" 
                         style="background-color: rgb(236, 82, 40); mix-blend-mode: multiply; filter: blur(40px); opacity: 0.2;" 
                         class="rounded-full floating"></div>
                </div>
            
                <div class="relative z-10 max-w-7xl mx-auto px-6 pt-20">
                    <div class="text-center">
                        <h1 class="text-6xl md:text-8xl font-bold mb-8 leading-tight">
                            Build 
                            <span 
                                id="dynamic-text"
                                class="gradient-text typing-animation"
                                style="color: rgb(63, 125, 88);"
                            ></span>
                        </h1>
                        
                        <p class="text-xl md:text-2xl text-white mb-12 max-w-3xl mx-auto leading-relaxed">
                            Transform your ideas into beautiful Livewire components with the power of AI. 
                            Just describe what you want, and watch 
                            <a class="font-bold" style="color: rgb(63, 125, 88);" >SKYLARR</a> 
                            build it for you.
                        </p>
                        
                        <div class="flex flex-col sm:flex-row items-center justify-center gap-6 mb-16">
                            @auth
                                <button wire:click="openDashboard" style="background-color:rgb(63, 125, 88);" class="glass-effect px-8 py-4 rounded-xl font-semibold text-lg hover:bg-[rgb(239,239,239)] hover:bg-opacity-20 transition-all transform hover:scale-105 pulse-glow" responsive spinner="openDashboard">
                                    Dashboard
                                </button>
                            @else
                                <button wire:click="openLoginModal" style="background-color: rgb(236, 82, 40);" class="glass-effect px-8 py-4 rounded-xl border-red-50 font-semibold text-lg hover:shadow-2xl transition-all transform hover:scale-105 pulse-glow" responsive spinner="openLoginModal">
                                    Login
                                </button>
                                <button wire:click="openRegisterModal" style="background-color:rgb(63, 125, 88);" class="glass-effect px-8 py-4 rounded-xl font-semibold text-lg hover:bg-[rgb(239,239,239)] hover:bg-opacity-20 transition-all transform hover:scale-105 pulse-glow" responsive spinner="openRegisterModal">
                                    Register
                                </button>
                            @endauth
                        </div>
                    </div>
                </div>
            </section>
            <iframe
                width="1500"
                height="600"
                src="https://www.youtube.com/embed/31Voz1H40zI?start=75&end=90"
                title="YouTube video player"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen>
            </iframe>
        </div>
        
        <!-- Footer -->
        <footer 
            class="footer sm:footer-horizontal bg-neutral text-neutral-content items-center p-4 mt-10"
            style="background-color: rgb(63, 125, 88);">
            <aside class="grid-flow-col items-center">
                <svg
                width="36"
                height="36"
                viewBox="0 0 24 24"
                xmlns="http://www.w3.org/2000/svg"
                fill-rule="evenodd"
                clip-rule="evenodd"
                class="fill-current">
                <path
                    d="M22.672 15.226l-2.432.811.841 2.515c.33 1.019-.209 2.127-1.23 2.456-1.15.325-2.148-.321-2.463-1.226l-.84-2.518-5.013 1.677.84 2.517c.391 1.203-.434 2.542-1.831 2.542-.88 0-1.601-.564-1.86-1.314l-.842-2.516-2.431.809c-1.135.328-2.145-.317-2.463-1.229-.329-1.018.211-2.127 1.231-2.456l2.432-.809-1.621-4.823-2.432.808c-1.355.384-2.558-.59-2.558-1.839 0-.817.509-1.582 1.327-1.846l2.433-.809-.842-2.515c-.33-1.02.211-2.129 1.232-2.458 1.02-.329 2.13.209 2.461 1.229l.842 2.515 5.011-1.677-.839-2.517c-.403-1.238.484-2.553 1.843-2.553.819 0 1.585.509 1.85 1.326l.841 2.517 2.431-.81c1.02-.33 2.131.211 2.461 1.229.332 1.018-.21 2.126-1.23 2.456l-2.433.809 1.622 4.823 2.433-.809c1.242-.401 2.557.484 2.557 1.838 0 .819-.51 1.583-1.328 1.847m-8.992-6.428l-5.01 1.675 1.619 4.828 5.011-1.674-1.62-4.829z"></path>
                </svg>
                <p>SKYLARR - All right reserved</p>
            </aside>
            <nav class="grid-flow-col gap-4 md:place-self-center md:justify-self-end">
                <a>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    class="fill-current">
                    <path
                    d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616-.054 2.281 1.581 4.415 3.949 4.89-.693.188-1.452.232-2.224.084.626 1.956 2.444 3.379 4.6 3.419-2.07 1.623-4.678 2.348-7.29 2.04 2.179 1.397 4.768 2.212 7.548 2.212 9.142 0 14.307-7.721 13.995-14.646.962-.695 1.797-1.562 2.457-2.549z"></path>
                </svg>
                </a>
                <a>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    class="fill-current">
                    <path
                    d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"></path>
                </svg>
                </a>
                <a>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    class="fill-current">
                    <path
                    d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"></path>
                </svg>
                </a>
            </nav>
        </footer>
        </x-slot:content>
    </x-main>
</div>