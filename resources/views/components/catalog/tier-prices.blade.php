{{-- A catalog item's price per classes-per-grade tier (an empty tier price = the 1–2 price). --}}
<div style="display:flex; flex-direction:column; gap:2px; font-size:var(--fs-caption); white-space:nowrap">
    @foreach (\App\Models\Program::CLASS_TIERS as $tierKey => $tierLabel)
        <div><span class="text-text-secondary">{{ $tierLabel }}:</span> <span class="ltr-num">₪{{ number_format($item->priceForClassTier($tierKey), 0) }}</span></div>
    @endforeach
</div>
