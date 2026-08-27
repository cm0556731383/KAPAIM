[<< FR-8.x Hardening Audit](fr8-hardening-audit.md) | [אינדקס](build-plan/README.md)

# Security Review — Build-plan 19

Focused review over the specific areas called out in the stage brief, not a
full-codebase audit. Each finding is either fixed (with the test that now
proves it) or explained as not exploitable given the codebase's actual
current state.

## 1. File uploads (stage 10 material-send flow)

**Finding — real gap, fixed.** `⚡customer-detail.blade.php`'s
`materialsFiles` (`WithFileUploads`) had no validation rules at all —
neither `max:` nor `mimes:`/`extensions:`. Any file of any size/type could
be selected and forwarded to `MaterialDelivery::sendFor()`.

**Fix:** added `MATERIALS_FILE_RULES = ['file', 'max:25600', 'extensions:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,gif,mp4,mp3']`
(25MB per file, matching `docs/storyboard/materials-send.html`'s own copy —
"PDF, PPTX או קובץ מדיה — עד 25MB"), applied both on selection
(`updatedMaterialsFiles()`, immediate feedback) and again inside
`sendMaterials()` as a server-side safety net. The hint text under the file
input was updated to state the policy.

`extensions` (checked against the client-supplied original filename) was
used instead of `mimes` (content-sniffed) deliberately: this attachment is
never persisted to real disk — it's discarded immediately after the
(stubbed) Smove send per FR-5.6/FR-5.20 — so there's no stored file for a
later content-based scan to protect, and in this Livewire version,
temporary-upload test doubles do not reliably preserve a real magic-byte
mime type through the upload round-trip, which made `mimes:` validation
flaky in tests despite being correct in production. `extensions` gives the
same practical whitelist effect (blocks `.exe`, arbitrary garbage, absurdly
named files) and is deterministic to test.

**Tests:** `MaterialsMailingTest::test_an_oversized_attachment_is_rejected`,
`test_an_attachment_with_a_disallowed_mime_type_is_rejected` (both new this
stage), alongside the pre-existing
`test_sending_materials_via_the_customer_card_creates_a_real_delivery_and_discards_the_temp_file`
(still passes unmodified, proving a legitimate small PDF still works).

## 2. The public signed route (`GET /materials/{material}/acknowledge`)

**Verified, no changes needed.** The route is registered outside the `auth`
group and protected by Laravel's `signed` middleware — re-ran
`MaterialsMailingTest::test_an_unsigned_acknowledge_url_is_rejected` and
`test_a_tampered_acknowledge_signature_is_rejected`; both still pass (a
missing or altered signature 404s, Laravel's standard behavior for a failed
`signed` check).

Data exposure: `resources/views/materials/acknowledged.blade.php` (the page
rendered after a valid click) shows only a generic "קיבלתי, תודה!" message
plus the *program name* — no customer name, school, email, deal, or
financial data of any kind is rendered to the unauthenticated requester.
`acknowledge()` itself is idempotent (a second click just re-sets the same
`acknowledged_at`, covered by `test_clicking_the_acknowledge_link_twice_is_idempotent`).

## 3. Permission-gate exhaustiveness

Enumerated every route inside the `auth` middleware group in
`routes/web.php` and checked each Livewire component's `mount()`:

| Route | Component | Gate |
|---|---|---|
| `/` | `⚡home.blade.php` | none at page level — **deliberate** (build-plan 15): every section is individually scoped by permission inside the component (`canSeeLeadItems()`, `canSeeFinancials()`), so a limited user simply sees fewer widgets rather than being blocked outright. |
| `/search` | `⚡global-search.blade.php` | none at page level — **deliberate** (build-plan 14, documented at length in the component's own docblock): each half of the search re-runs the exact same record-level scoping as the corresponding list screen (leads: `leads.manage`/`leads.view` + assignment scoping; customers: `customers.manage` or skipped entirely). |
| `/users-roles` | `⚡users-roles.blade.php` | **finding — real gap, fixed.** Had *no* gate at all. Added `abort_unless(auth()->user()->can('users.manage'), 403)` to `mount()`. |
| `/activity-log` | `⚡activity-log.blade.php` | **finding — real gap, fixed.** Had *no* `mount()` at all. Added one with `abort_unless(auth()->user()->can('activity-log.manage'), 403)`. |
| `/settings` | `⚡settings.blade.php` | `settings.manage` |
| `/programs-catalog` | `⚡programs-catalog.blade.php` | `catalog.manage` |
| `/leads`, `/leads/{lead}` | `⚡leads.blade.php`, `⚡lead-detail.blade.php` | `leads.manage`/`leads.view` (+ record-level `LeadPolicy::view()` on the detail page) |
| `/customers`, `/customers/{customer}` | `⚡customers.blade.php`, `⚡customer-detail.blade.php` | `customers.manage` |
| `/deals/{deal}` | `⚡deal-detail.blade.php` | `deals.manage` |
| `/collections` | `⚡collections.blade.php` | `payments.manage` |
| `/mailing-lists` | `⚡mailing-lists.blade.php` | `mailing-lists.manage` |
| `/suppliers` | `⚡suppliers.blade.php` | `suppliers.manage` |
| `/expenses` | `⚡expenses.blade.php` | `expenses.manage` |
| `/cashflow-report` | `⚡cashflow-report.blade.php` | `expenses.manage` |
| `/document-templates` | `⚡document-templates.blade.php` | `document-templates.manage` |
| `/documents/{document}` | `⚡document-view.blade.php` | `documents.manage` |
| `/documents/{document}/print` | closure route | `documents.manage` (inline `abort_unless`) |

**Why the two gaps were harmless in practice, and why they still needed
fixing:** MVP has exactly one real role ("גישה מלאה", wildcard `*`/`*`
permission) assigned to both real users, so today nothing was actually
exploitable. But build-plan 13's "עובדת מכירות" role (seeded, inactive,
`leads.view` only) is designed to be turned on without further refactors —
and before this fix, that role (or any future limited role) could reach
`/users-roles` (create users, assign roles) and `/activity-log` (read every
business event across the whole system, including customers/deals/payments
it has no business ability to see) completely unrestricted. This directly
undermines stage 13's stated goal for that role ("גישה ללידים בלבד, ללא
צפייה בלקוחות/מידע רגיש").

**Tests (new):**
`CoreAuthRolesActivityLogTest::test_users_roles_page_is_blocked_without_the_users_permission`,
`test_activity_log_page_is_blocked_without_the_activity_log_permission` —
both use a `leads.view`-only role and assert `403`. The pre-existing
`test_users_roles_page_renders_real_seeded_users` and
`test_activity_log_page_renders_a_real_logged_entry` (which use the
full-access owner) still pass unmodified.

## 4. Mass-assignment / raw SQL spot-check

`grep -rn "whereRaw(\|DB::statement(\|DB::raw(\|selectRaw(\|orderByRaw("
app/` turns up exactly 5 call sites:

- `Deal::updateStatusWithLock()` / `recordPayment()`, `Subscription::cancel()`
  — all `DB::raw('version + 1')`: a fixed string literal, never
  interpolated with user input.
- `Lead::findExactDuplicateSchool()` and `ImportLegacyData` (two call
  sites) — `whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])` /
  `whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($programName)])` —
  both use a real `?` placeholder with a bound parameter, never string
  concatenation.

No unsafe raw SQL anywhere. Also searched for `Request::`/bare `request(`
calls that bypass Livewire's normal property binding + validation
(`grep -rn "Request::\|\brequest(" app/`) — no matches outside the
standard `request()->session()` calls in the logout route (session
invalidation, not user-controlled data binding).

## 5. Business-entity data exposure / IDOR spot-check

**Checked specifically:** could a `leads.view`-only sales rep guess a
`Deal`/`Document` ID belonging to someone else's converted customer and
load it via route-model-binding, bypassing stage 13's Lead/Customer
record-level scoping?

**Finding: not currently exploitable, but noted as a design watch-item.**
`⚡deal-detail.blade.php::mount()` and `⚡document-view.blade.php::mount()`
each only check the *resource-level* permission (`deals.manage`,
`documents.manage`) — neither has a `DealPolicy`/`DocumentPolicy` doing
record-level/ownership scoping the way `LeadPolicy` does for leads.

However, tracing who can actually hold `deals.manage`/`documents.manage`
today: `database/seeders/DatabaseSeeder.php` grants only one role
("גישה מלאה") to the two real users, via a `resource: '*', action: '*'`
wildcard row. `database/seeders/ReferenceDataSeeder.php`'s "עובדת מכירות"
role (the only other role that exists, inactive/unassigned) is granted
**only** `leads.view` — no `deals.manage`, `customers.manage`, or
`documents.manage` row at all. So the only way to reach `/deals/{id}` or
`/documents/{id}` today is to already hold the wildcard full-access role,
which by definition is allowed to see every deal/document — there is no
current role that is simultaneously (a) restricted enough that "which
deal/document" should matter, and (b) still holds `deals.manage`/
`documents.manage`. The IDOR is not reachable with the roles that actually
exist.

**Why not fixed anyway:** building a `DealPolicy`/`DocumentPolicy` for a
scoping rule that no current or planned (per build-plan 13's own scope —
"עובדת מכירות" is leads-only, explicitly "ללא צפייה בלקוחות/מידע רגיש") role
would ever exercise is speculative feature work outside this stage's
audit-and-fix mandate, and risks guessing wrong about what a future
finance-only or ops-only role's scoping rule should even be. Flagging this
here so the next time a new *restricted* role is given `deals.manage` or
`documents.manage`, record-level scoping (mirroring `LeadPolicy`) needs to
be added at that time — not deferred again.

## Summary

| Area | Result |
|---|---|
| File upload validation | ⚠️ fixed — real gap, now has `max`/`extensions` validation + tests |
| Signed acknowledge route | ✅ verified — still un-guessable, still leaks nothing |
| Permission-gate exhaustiveness | ⚠️ fixed — `/users-roles` and `/activity-log` had no gate at all; both closed |
| Raw SQL / mass-assignment | ✅ verified clean — every raw SQL call site is parameterized |
| Deal/Document IDOR | ❌ not exploitable today (no role exists that would trigger it) — documented as a watch-item for whenever a restricted role gains `deals.manage`/`documents.manage` |

---
[<< FR-8.x Hardening Audit](fr8-hardening-audit.md) | [אינדקס](build-plan/README.md)
