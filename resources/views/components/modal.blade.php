@props(['triggerLabel', 'triggerClass' => 'btn btn-primary', 'title' => null, 'name' => null])

<span
    x-data="{ open: false }"
    x-on:close-modals.window="open = false"
    @if ($name) x-on:open-modal.window="if ($event.detail.name === '{{ $name }}') open = true" @endif
>
    <button type="button" class="{{ $triggerClass }}" @click="open = true">{{ $triggerLabel }}</button>

    <template x-teleport="body">
        <div class="modal-overlay" x-show="open" x-cloak @click.self="open = false" @keydown.escape.window="open = false" style="display:none">
            <div class="modal-panel" @click.stop>
                <div class="modal-header">
                    @if ($title)<h2>{{ $title }}</h2>@endif
                    <button type="button" class="modal-close" @click="open = false" aria-label="סגירה">&times;</button>
                </div>
                {{ $slot }}
            </div>
        </div>
    </template>
</span>
