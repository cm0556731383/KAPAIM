{{--
    The programs/bundles list of a deal or quote window (App\Support\DealLines).
    $lines: the lines; $prop: their Livewire property; $picker: the add-picker property;
    $remove: the remove method; $programs / $bundles: what can be added; $tier: price tier for the picker's prices.
--}}
@php $listed = collect($lines)->pluck('item'); @endphp
<div class="full deal-lines">
    <label>תוכניות ומארזים</label>
    @if ($lines)
        <table>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr wire:key="{{ $prop }}-{{ $line['item'] }}">
                        <td class="name">{{ $line['name'] }}</td>
                        <td class="amount">
                            <input type="text" wire:model.live.debounce.400ms="{{ $prop }}.{{ $index }}.amount" class="ltr-num" dir="ltr" placeholder="₪" aria-label="סכום — {{ $line['name'] }}">
                            @error("{$prop}.{$index}.amount") <div class="err">{{ $message }}</div> @enderror
                        </td>
                        <td class="remove"><button type="button" class="btn btn-ghost btn-sm" wire:click="{{ $remove }}({{ $index }})" title="הסרה">×</button></td>
                    </tr>
                @endforeach
            </tbody>
            @if (count($lines) > 1)
                <tfoot><tr><td>סה״כ</td><td class="ltr-num">₪{{ number_format(\App\Support\DealLines::total($lines), 0) }}</td><td></td></tr></tfoot>
            @endif
        </table>
    @endif
    <select wire:model.live="{{ $picker }}" aria-label="הוספת תוכנית או מארז">
        <option value="">{{ $lines ? '+ הוספת תוכנית או מארז' : 'בחרו תוכנית או מארז' }}</option>
        @if ($programs->isNotEmpty())
            <optgroup label="תוכניות">
                @foreach ($programs as $program)
                    @continue($listed->contains("program:{$program->id}"))
                    <option value="program:{{ $program->id }}">{{ $program->name }} (₪{{ number_format($program->priceForClassTier($tier), 0) }})</option>
                @endforeach
            </optgroup>
        @endif
        @if ($bundles->isNotEmpty())
            <optgroup label="מארזים">
                @foreach ($bundles as $bundle)
                    @continue($listed->contains("bundle:{$bundle->id}"))
                    <option value="bundle:{{ $bundle->id }}">{{ $bundle->name }} (₪{{ number_format($bundle->priceForClassTier($tier), 0) }})</option>
                @endforeach
            </optgroup>
        @endif
    </select>
    @error($prop) <div class="err">{{ $message }}</div> @enderror
</div>
