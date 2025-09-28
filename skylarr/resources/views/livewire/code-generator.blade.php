<div>
    {{-- To attain knowledge, add things every day; To attain wisdom, subtract things every day. --}}
    {{-- This file should be the root of 2 other files. Basically a layout file --}}
    {{-- So chat and engine--}}


    <div class="flex flex-col md:flex-row h-screen">
        <livewire:chat-interface class="w-full md:w-1/2 p-4 bg-gray-100 overflow-y-auto" />
        <livewire:code-generation-engine class="w-full md:w-1/2 p-4 bg-white overflow-y-auto relative" />
    </div>
</div>
