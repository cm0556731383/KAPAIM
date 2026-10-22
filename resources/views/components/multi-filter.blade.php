{{--
    A list-page filter that takes several values at once: a select-looking
    button opening a panel of checkboxes, bound (wire:model.live) to an array
    property of the page's component. Nothing ticked means "all".
    $options: value => label, or $groups: group label => (value => label).
--}}
@props(['model', 'label', 'allLabel', 'selected' => [], 'options' => [], 'groups' => []])

@php
    $groups = $groups ?: ['' => $options];
    // Not collapse(): it renumbers numeric keys, and most values here are ids.
    $labels = collect($groups)->reduce(fn ($all, $group) => $all->union($group), collect());
    $picked = $labels->only(array_map('strval', $selected))->values();
    $summary = match (true) {
        $picked->isEmpty() => $allLabel,
        $picked->count() <= 2 => $picked->implode(', '),
        default => $picked->count().' נבחרו',
    };
@endphp

<div class="multi-filter" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <label id="{{ $model }}-label">{{ $label }}</label>
    <button type="button" class="multi-filter-btn {{ $picked->isNotEmpty() ? 'is-set' : '' }}" x-on:click="open = ! open" :aria-expanded="open" aria-labelledby="{{ $model }}-label">
        <span class="multi-filter-value">{{ $summary }}</span>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
    </button>
    <div class="multi-filter-panel" x-show="open" x-cloak x-transition.opacity.duration.100ms>
        @if ($picked->isNotEmpty())
            <button type="button" class="multi-filter-clear" wire:click="$set('{{ $model }}', [])">ניקוי — {{ $allLabel }}</button>
        @endif
        @forelse ($groups as $groupLabel => $groupOptions)
            @if ($groupLabel !== '' && count($groupOptions))<div class="multi-filter-group">{{ $groupLabel }}</div>@endif
            @foreach ($groupOptions as $value => $optionLabel)
                <label class="multi-filter-item" wire:key="{{ $model }}-{{ $value }}">
                    <input type="checkbox" value="{{ $value }}" wire:model.live="{{ $model }}">{{ $optionLabel }}
                </label>
            @endforeach
        @empty
        @endforelse
        @if ($labels->isEmpty())<div class="multi-filter-empty">אין אפשרויות לבחירה</div>@endif
    </div>
</div>
