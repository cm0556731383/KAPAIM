{{--
    One supplier-invoice email editor on the document-templates page — subject,
    body, insert-a-field buttons, the block token (upload button / expense
    list) and a live preview filled with example values. Variables: $title,
    $intro, $subjectModel, $contentModel, $fields (token => label),
    $blockToken, $blockLabel, $blockHint, $blockPreviewHtml, $preview
    (['subject', 'content']), $saveAction.
--}}
<section class="settings-section">
    <div class="section-head"><h2>{{ $title }}</h2></div>
    <div class="card" x-data="{ target: null, insert(token) {
            const el = this.target ?? $refs.body;
            const start = el.selectionStart ?? el.value.length, end = el.selectionEnd ?? start;
            el.value = el.value.slice(0, start) + token + el.value.slice(end);
            el.focus();
            el.setSelectionRange(start + token.length, start + token.length);
            el.dispatchEvent(new Event('input'));
        } }">
        <p class="text-text-secondary" style="margin:0 0 var(--sp-md); font-size:var(--fs-small)">{{ $intro }}</p>
        <form wire:submit="{{ $saveAction }}" class="form-grid">
            <div class="full">
                <label for="{{ $subjectModel }}">נושא המייל</label>
                <input type="text" id="{{ $subjectModel }}" wire:model.live.debounce.400ms="{{ $subjectModel }}" x-on:focus="target = $el">
                @error($subjectModel) <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div class="full">
                <label for="{{ $contentModel }}">גוף המייל</label>
                <textarea id="{{ $contentModel }}" x-ref="body" wire:model.live.debounce.400ms="{{ $contentModel }}" x-on:focus="target = $el" rows="10"></textarea>
                @error($contentModel) <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div class="full">
                <label>הוספת שדה (נכנס במקום הסמן)</label>
                <div style="display:flex; flex-wrap:wrap; gap:6px">
                    @foreach ($fields as $token => $label)
                        <button type="button" class="btn btn-ghost btn-sm" onmousedown="event.preventDefault()" x-on:click="insert('{{ str_repeat('{', 2).$token.str_repeat('}', 2) }}')">+ {{ $label }}</button>
                    @endforeach
                    <button type="button" class="btn btn-secondary btn-sm" onmousedown="event.preventDefault()" x-on:click="insert('{{ $blockToken }}')">+ {{ $blockLabel }}</button>
                </div>
                <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:6px">{{ $blockHint }}</div>
            </div>
            <div class="full">
                <label>תצוגה מקדימה (עם פרטים לדוגמה)</label>
                <div style="border:1px solid var(--color-border); border-radius:var(--radius-control); padding:var(--sp-md); background:var(--color-background)">
                    <div style="font-weight:600; margin-bottom:var(--sp-sm)">{{ $preview['subject'] }}</div>
                    @php($parts = explode($blockToken, $preview['content']))
                    @foreach ($parts as $part)
                        <div style="white-space:pre-wrap">{{ trim($part, "\n") }}</div>
                        @if (! $loop->last || count($parts) === 1)
                            <div style="margin:var(--sp-sm) 0">{!! $blockPreviewHtml !!}</div>
                        @endif
                    @endforeach
                </div>
            </div>
            <div class="full"><button type="submit" class="btn btn-primary">שמירת נוסח המייל</button></div>
        </form>
    </div>
</section>
