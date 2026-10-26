{{-- The "תצוגה מקדימה" window (App\Concerns\PreviewsEmails) — placed once per screen. --}}
@php($preview = $this->emailPreview)
<x-modal name="email-preview" :title="$preview ? 'תצוגה מקדימה של המייל' : null" width="760px">
    @if ($preview)
        <div style="display:grid; gap:4px; margin-bottom:var(--sp-sm); font-size:var(--fs-small)">
            @if ($preview['to'])<div><span class="text-text-secondary">אל:</span> {{ $preview['to'] }}</div>@endif
            <div><span class="text-text-secondary">נושא:</span> <strong>{{ $preview['subject'] }}</strong></div>
            @if ($preview['attachments'])
                <div><span class="text-text-secondary">קבצים מצורפים:</span> {{ collect($preview['attachments'])->pluck('name')->implode(', ') }}</div>
            @endif
        </div>
        @if ($preview['note'])
            <p class="note" style="margin:0 0 var(--sp-sm)">{{ $preview['note'] }}</p>
        @endif
        {{-- sandbox: an uploaded HTML file's scripts never run inside the app. --}}
        <iframe sandbox srcdoc="{{ $preview['html'] }}" title="תצוגה מקדימה של המייל" style="width:100%; height:60vh; border:1px solid var(--color-border); border-radius:var(--radius-control); background:#FFFFFF"></iframe>
        <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-top:var(--sp-md)">
            <button type="button" class="btn btn-secondary" wire:click="sendEmailPreviewToMe" wire:loading.attr="disabled" wire:target="sendEmailPreviewToMe">
                <span wire:loading.remove wire:target="sendEmailPreviewToMe">שליחת בדיקה אליי ({{ auth()->user()->email }})</span>
                <span wire:loading wire:target="sendEmailPreviewToMe">שולח...</span>
            </button>
            <button type="button" class="btn btn-ghost" x-on:click="$dispatch('close-modals')">סגירה</button>
        </div>
    @endif
</x-modal>
