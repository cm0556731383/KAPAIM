{{--
    Build-plan 16 — shared banner for FR-7.25 "business-error" messages
    (and the FR-7.24-adjacent "possible duplicate" warnings that use the
    same visual pattern). This is a pure visual-consistency extraction:
    every ⚡*.blade.php component still owns its own `?string $xxxError`
    property and the logic that sets/clears it — only the markup that used
    to be duplicated inline in each component moves here.

    A business-error BLOCKS and explains (FR-7.25) — it stays on screen
    until the underlying condition is resolved, unlike the toasts in
    layouts/app.blade.php which auto-dismiss. Never convert this into a
    toast.

    Usage: <x-business-error-banner :message="$catalogError" />
           <x-business-error-banner :message="$duplicateWarning" type="warning" />
--}}
@props(['message', 'type' => 'error', 'style' => ''])

@php
    $palette = [
        'error' => ['var(--color-error-bg)', 'var(--color-error)'],
        'warning' => ['var(--color-warning-bg)', 'var(--color-warning)'],
        'info' => ['var(--color-info-bg)', 'var(--color-info)'],
    ];
    [$bg, $fg] = $palette[$type] ?? $palette['error'];
@endphp

@if ($message)
    <div class="mb-8" style="background: {{ $bg }}; color: {{ $fg }}; border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;{{ $style ? ' '.$style : '' }}">{{ $message }}</div>
@endif
