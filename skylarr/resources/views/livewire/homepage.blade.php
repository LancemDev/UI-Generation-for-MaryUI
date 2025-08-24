<div>
    <x-main full-width>
        <x-slot:content>
        <div 
            class="text-white overflow-x-hidden"
            style="background-color: rgb(239, 150, 81);"
            >

            <!-- Login Modal -->
            <x-modal wire:model="loginModal" class="backdrop-blur" style="color: rgb(236, 82, 40)">
                <x-header title="Login to SKYLARR" />
                <x-form wire:submit="loginUser">
                    <x-input wire:model="email" label="Continue with email" inline icon="o-envelope" />
                    <x-input wire:model="password" type="password" label="Password" inline icon="o-lock-closed" />

                    <x-slot:actions>
                        <x-button type="submit" label="Login" class="rounded-md bg-slate-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-slate-700 focus:shadow-none active:bg-slate-700 hover:bg-slate-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" />
                    </x-slot:actions>
                </x-form>
            </x-modal>

            <!-- Register -->
            <x-modal wire:model="registerModal" style="color: rgb(236, 82, 40)" class="backdrop-blur">
                <x-header title="Create a new account" />
                <x-form wire:submit="register">
                    <x-input wire:model="name" label="Name" inline icon="o-user" />
                    <x-input wire:model="email" label="Email" inline icon="o-envelope" />
                    <x-input wire:model="password" type="password" label="Password" inline icon="o-lock-closed" />
                    <x-slot:actions>
                        <x-button type="submit" label="Register" class="rounded-md bg-slate-800 py-2 px-4 border border-transparent text-center text-sm text-white transition-all shadow-md hover:shadow-lg focus:bg-slate-700 focus:shadow-none active:bg-slate-700 hover:bg-slate-700 active:shadow-none disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none ml-2" />
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
                            <button wire:click="openLoginModal" style="background-color: rgb(236, 82, 40);" class="glass-effect px-8 py-4 rounded-xl border-red-50 font-semibold text-lg hover:shadow-2xl transition-all transform hover:scale-105 pulse-glow" responsive spinner="openLoginModal">
                                Login
                            </button>
                            <button wire:click="openRegisterModal" style="background-color:rgb(63, 125, 88);" class="glass-effect px-8 py-4 rounded-xl font-semibold text-lg hover:bg-[rgb(239,239,239)] hover:bg-opacity-20 transition-all">
                                Register
                            </button>
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