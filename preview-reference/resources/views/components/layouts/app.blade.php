<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    {{-- Ensure Alpine.js x-cloak works --}}
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>

    {{-- MAIN --}}
    <x-main full-width with-nav>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{--  TOAST area --}}
    <x-toast />

    {{-- Skylarr element picker: when the editor asks, the next click selects an element
         and sends its HTML to the editor instead of activating it. --}}
    <script>
        (() => {
            if (window.parent === window) return;
            let picking = false, hovered = null;
            const outline = (el, on) => { if (el) el.style.outline = on ? '2px solid #3b82f6' : ''; };
            window.addEventListener('message', (event) => {
                if (event.source !== window.parent || event.data?.type !== 'skylarr:pick') return;
                picking = !!event.data.on;
                document.body.style.cursor = picking ? 'crosshair' : '';
                if (!picking) { outline(hovered, false); hovered = null; }
            });
            document.addEventListener('mouseover', (event) => {
                if (!picking) return;
                outline(hovered, false);
                hovered = event.target;
                outline(hovered, true);
            }, true);
            document.addEventListener('click', (event) => {
                if (!picking) return;
                event.preventDefault();
                event.stopPropagation();
                picking = false;
                document.body.style.cursor = '';
                outline(hovered, false);
                const html = event.target.outerHTML.replace(/ style=""/g, '').slice(0, 3000);
                window.parent.postMessage({ type: 'skylarr:picked', html, path: location.pathname }, '*');
            }, true);
        })();
    </script>
</body>
</html>