<div>
    {{-- To attain knowledge, add things every day; To attain wisdom, subtract things every day. --}}
    {{-- This file should be the root of 2 other files. Basically a layout file --}}
    {{-- So chat and engine--}}

    <livewire:custom-components.navigation-bar />

    <div id="gg-ui-root" class="flex h-screen overflow-hidden relative bg-primary">
        <button id="sidebar-toggle"
                class="absolute top-3 left-3 z-20 inline-flex items-center gap-2 rounded-md border border-secondary text-secondary bg-white px-3 py-1.5 text-sm font-medium shadow-sm focus:outline-none hover:bg-secondary/10"
                type="button"
                aria-expanded="true"
                aria-controls="gg-sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                <path fill-rule="evenodd" d="M3.75 5.25a.75.75 0 0 1 .75-.75h15a.75.75 0 0 1 0 1.5h-15a.75.75 0 0 1-.75-.75Zm0 6a.75.75 0 0 1 .75-.75h15a.75.75 0 0 1 0 1.5h-15a.75.75 0 0 1-.75-.75Zm0 6a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-8.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
            </svg>
            <span class="hidden sm:inline"></span>
        </button>

        <div id="gg-sidebar" class="relative h-full border-r border-secondary/25 overflow-y-auto transition-[width] duration-200 ease-in-out w-[28rem] min-w-[14rem] max-w-[60vw]">
            <div id="gg-sidebar-content" class="h-full p-4">
                <livewire:chat-interface class="w-full h-full" />
            </div>
            <div id="gg-drag-handle" class="absolute top-0 right-0 h-full w-1.5 cursor-col-resize bg-transparent hover:bg-secondary/35"></div>
        </div>

        <div id="gg-main" class="flex-1 h-full overflow-y-auto relative">
            <div class="h-full p-4">
                <livewire:code-generation-engine class="w-full h-full" />
            </div>
        </div>
    </div>

    <style>
        /* Collapsed state rules */
        #gg-sidebar.is-collapsed {
            width: 0 !important;
            min-width: 0 !important;
            border-right-width: 0;
        }
        #gg-sidebar.is-collapsed #gg-sidebar-content { display: none; }
        /* Improve hit area for the toggle on top of content */
        #sidebar-toggle { pointer-events: auto; }
        /* hover states handled by Tailwind classes above */
    </style>

    <script>
        (function () {
            const sidebar = document.getElementById('gg-sidebar');
            const toggleBtn = document.getElementById('sidebar-toggle');
            const dragHandle = document.getElementById('gg-drag-handle');
            const STORAGE_KEY_WIDTH = 'gg.sidebar.width';
            const STORAGE_KEY_COLLAPSED = 'gg.sidebar.collapsed';

            // Restore persisted state
            try {
                const savedWidth = localStorage.getItem(STORAGE_KEY_WIDTH);
                if (savedWidth) {
                    const width = parseInt(savedWidth, 10);
                    if (!Number.isNaN(width)) {
                        sidebar.style.width = width + 'px';
                    }
                }
                const savedCollapsed = localStorage.getItem(STORAGE_KEY_COLLAPSED);
                if (savedCollapsed === 'true') {
                    sidebar.classList.add('is-collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            } catch (_) {}

            // Toggle collapse
            toggleBtn.addEventListener('click', function () {
                const collapsed = sidebar.classList.toggle('is-collapsed');
                toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                try { localStorage.setItem(STORAGE_KEY_COLLAPSED, String(collapsed)); } catch (_) {}
            });

            // Drag to resize (enabled primarily for md+ but works if space allows)
            let isDragging = false;
            let startX = 0;
            let startWidth = 0;

            const minWidth = 224; // 14rem
            const maxWidthRatio = 0.6; // 60% of viewport width

            function onMouseMove(e) {
                if (!isDragging) return;
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
                const maxWidth = Math.floor(viewportWidth * maxWidthRatio);
                const dx = e.clientX - startX;
                let newWidth = startWidth + dx;
                if (newWidth < minWidth) newWidth = minWidth;
                if (newWidth > maxWidth) newWidth = maxWidth;
                sidebar.style.width = newWidth + 'px';
            }

            function onMouseUp() {
                if (!isDragging) return;
                isDragging = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
                try {
                    const numeric = parseInt(sidebar.style.width, 10);
                    if (!Number.isNaN(numeric)) {
                        localStorage.setItem(STORAGE_KEY_WIDTH, String(numeric));
                    }
                } catch (_) {}
            }

            dragHandle.addEventListener('mousedown', function (e) {
                // If collapsed, expand first
                if (sidebar.classList.contains('is-collapsed')) {
                    sidebar.classList.remove('is-collapsed');
                    toggleBtn.setAttribute('aria-expanded', 'true');
                    try { localStorage.setItem(STORAGE_KEY_COLLAPSED, 'false'); } catch (_) {}
                }
                isDragging = true;
                startX = e.clientX;
                startWidth = sidebar.getBoundingClientRect().width;
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        })();
    </script>
</div>
