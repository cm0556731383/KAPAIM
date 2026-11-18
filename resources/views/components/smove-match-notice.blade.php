{{-- Contacts already in Smove, awaiting approval to match (App\Services\SmoveContactMatch::find()). --}}
@props(['matches'])

<div style="background:var(--color-warning-bg); border:1px solid var(--color-warning); border-radius:var(--radius-control); padding:var(--sp-md); margin-bottom:var(--sp-md)">
    <h3 style="margin:0 0 8px; color:var(--color-warning)">{{ count($matches) > 1 ? 'אנשי קשר כבר קיימים ב-Smove' : 'איש קשר כבר קיים ב-Smove' }}</h3>
    @foreach ($matches as $match)
        <div style="margin-bottom:8px">
            איש הקשר <strong>{{ $match['contact_name'] }}</strong>
            (<span class="ltr-num" dir="ltr">{{ $match['email'] }}</span>)
            כבר קיים ב-Smove בשם <strong>{{ $match['smove_name'] !== '' ? $match['smove_name'] : '(ללא שם)' }}</strong>.
            @if (! empty($match['shared_with']))
                <div style="font-size:var(--fs-caption)">כתובת זו כבר משמשת איש קשר של: {{ implode(', ', $match['shared_with']) }}. ב-Smove זה איש קשר אחד לכולן.</div>
            @endif
            @unless ($match['can_receive'])
                <div style="font-size:var(--fs-caption); color:var(--color-text-secondary)">ב-Smove הוא מסומן כמי שאינו מקבל דיוור.</div>
            @endunless
        </div>
    @endforeach
    <div>{{ count($matches) > 1 ? 'להתאים ביניהם' : 'להתאים בין השניים' }}?</div>
</div>
