<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 14 (FR-7.14/FR-7.15, US-018): a single cross-entity search box
 * over LEAD and CUSTOMER (reached through SCHOOL/CONTACT).
 *
 * Page-level gate: auth only, no new "search" permission — this mirrors the
 * simplest option build-plan 14 allows, since every bit of real access
 * control already happens per-entity inside results() below by re-running
 * the exact same record-level scoping as the list screens themselves:
 *   - leads half: identical to ⚡leads.blade.php's leads() computed
 *     (build-plan 13, FR-7.2-7.4) — a leads.manage holder finds every lead;
 *     a leads.view-only holder (future "עובדת מכירות") only finds leads
 *     assigned to them that have not yet converted.
 *   - customers half: gated on customers.manage, mirroring
 *     ⚡customers.blade.php's page-level gate — a user without it never sees
 *     a customer here, the customer half of the search is skipped entirely
 *     rather than filtered per-row.
 */
new
#[Layout('layouts.app', ['title' => 'חיפוש גלובלי — כפיים'])]
class extends Component
{
    public string $query = '';

    private const MIN_LENGTH = 2;

    private const MAX_RESULTS = 20;

    #[Computed]
    public function results()
    {
        $term = trim($this->query);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return collect();
        }

        $user = auth()->user();
        $normalizedTerm = Lead::normalizeName($term);
        $phoneDigits = Lead::normalizePhone($term);
        // A single stray digit inside an otherwise-textual query (e.g. "כיתה 5")
        // shouldn't turn into a phone match against every record with a "5" —
        // require a few digits before treating the query as a phone search.
        $phoneDigits = $phoneDigits && strlen($phoneDigits) >= 3 ? $phoneDigits : null;

        $results = collect();

        // ===== Leads =====
        if ($user->can('leads.manage') || $user->can('leads.view')) {
            Lead::query()
                ->with(['school.contacts'])
                ->when(! $user->can('leads.manage'), fn ($q) => $q->where('assigned_user_id', $user->id)->whereNull('converted_at'))
                ->get()
                ->each(function (Lead $lead) use (&$results, $normalizedTerm, $phoneDigits) {
                    if (! $this->leadMatches($lead, $normalizedTerm, $phoneDigits)) {
                        return;
                    }

                    $primaryContact = $lead->school?->contacts->firstWhere('is_primary', true);

                    $results->push([
                        'type' => 'lead',
                        'icon' => 'icon-leads',
                        'tone' => 'tone-secondary',
                        'badgeClass' => 'badge-warning',
                        'badgeLabel' => 'ליד',
                        'title' => $lead->school?->name ?? '— (ללא בית ספר) —',
                        'subtitle' => $this->subtitleFor($primaryContact, $lead->phone),
                        'url' => route('lead-detail', $lead),
                    ]);
                });
        }

        // ===== Customers =====
        if ($user->can('customers.manage')) {
            Customer::query()
                ->with(['school', 'contacts'])
                ->get()
                ->each(function (Customer $customer) use (&$results, $normalizedTerm, $phoneDigits) {
                    if (! $this->customerMatches($customer, $normalizedTerm, $phoneDigits)) {
                        return;
                    }

                    $primaryContact = $customer->contacts->firstWhere('is_primary', true);

                    $results->push([
                        'type' => 'customer',
                        'icon' => 'icon-customers',
                        'tone' => 'tone-primary',
                        'badgeClass' => 'badge-success',
                        'badgeLabel' => 'לקוחה',
                        'title' => $customer->school?->name ?? '—',
                        'subtitle' => $this->subtitleFor($primaryContact, $customer->school?->phone),
                        'url' => route('customer-detail', $customer),
                    ]);
                });
        }

        return $results->take(self::MAX_RESULTS)->values();
    }

    /**
     * FR-7.14: school name/phone, the school's contacts (name/phone/email —
     * still keyed by school_id per build-plan 04, even for an
     * already-converted lead a leads.manage holder can still see), and the
     * lead's own phone/email.
     */
    private function leadMatches(Lead $lead, string $term, ?string $phoneDigits): bool
    {
        if ($this->textMatches($lead->school?->name, $term) || $this->textMatches($lead->email, $term)) {
            return true;
        }

        if ($this->phoneMatches($lead->school?->phone, $phoneDigits) || $this->phoneMatches($lead->phone, $phoneDigits)) {
            return true;
        }

        foreach ($lead->school?->contacts ?? [] as $contact) {
            if ($this->contactMatches($contact, $term, $phoneDigits)) {
                return true;
            }
        }

        return false;
    }

    private function customerMatches(Customer $customer, string $term, ?string $phoneDigits): bool
    {
        if ($this->textMatches($customer->school?->name, $term)) {
            return true;
        }

        if ($this->phoneMatches($customer->school?->phone, $phoneDigits)) {
            return true;
        }

        foreach ($customer->contacts as $contact) {
            if ($this->contactMatches($contact, $term, $phoneDigits)) {
                return true;
            }
        }

        return false;
    }

    private function contactMatches(Contact $contact, string $term, ?string $phoneDigits): bool
    {
        return $this->textMatches($contact->name, $term)
            || $this->textMatches($contact->email, $term)
            || $this->phoneMatches($contact->phone, $phoneDigits)
            || $this->phoneMatches($contact->phone_secondary, $phoneDigits);
    }

    /** Case-insensitive substring match — Lead::normalizeName() (build-plan 04). */
    private function textMatches(?string $haystack, string $term): bool
    {
        return $haystack !== null && str_contains(Lead::normalizeName($haystack), $term);
    }

    /** Formatting-agnostic substring match — Lead::normalizePhone() (build-plan 04). */
    private function phoneMatches(?string $haystack, ?string $phoneDigits): bool
    {
        if (! $phoneDigits || ! $haystack) {
            return false;
        }

        $normalized = Lead::normalizePhone($haystack);

        return $normalized !== null && str_contains($normalized, $phoneDigits);
    }

    /** Mirrors docs/storyboard/global-search.html's "שם (תפקיד) · טלפון" subtitle format. */
    private function subtitleFor(?Contact $primaryContact, ?string $fallbackPhone): string
    {
        if (! $primaryContact) {
            return $fallbackPhone ?: '—';
        }

        $name = $primaryContact->role ? "{$primaryContact->name} ({$primaryContact->role})" : $primaryContact->name;

        return $primaryContact->phone ? "{$name} · {$primaryContact->phone}" : $name;
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">חיפוש גלובלי</h1>
        </div>
    </div>

    <div class="search-box">
        <svg><use href="#icon-search"></use></svg>
        <input type="text" wire:model.live="query" aria-label="חיפוש" placeholder="חפשי לפי שם, טלפון או בית ספר…" autofocus>
    </div>

    @if (mb_strlen(trim($query)) >= 2)
        <p class="search-caption">{{ $this->results->count() }} תוצאות עבור "{{ trim($query) }}"</p>
    @endif

    <div class="card">
        @forelse ($this->results as $result)
            <a class="result-row" href="{{ $result['url'] }}">
                <span class="icon-chip {{ $result['tone'] }}"><svg><use href="#{{ $result['icon'] }}"></use></svg></span>
                <div class="result-main">
                    <div class="result-title">{{ $result['title'] }}</div>
                    <div class="result-sub">{{ $result['subtitle'] }}</div>
                </div>
                <span class="badge {{ $result['badgeClass'] }}">{{ $result['badgeLabel'] }}</span>
            </a>
        @empty
            <div class="empty-state">
                @if (mb_strlen(trim($query)) < 2)
                    הקלידי לפחות 2 תווים כדי להתחיל בחיפוש.
                @else
                    לא נמצאו תוצאות עבור "{{ trim($query) }}".
                @endif
            </div>
        @endforelse
    </div>
</div>
