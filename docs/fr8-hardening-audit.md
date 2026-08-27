[<< 19 — ולידציות קצה, אבטחה ואמינות](build-plan/19-hardening-qa.md) | [אינדקס](build-plan/README.md)

# FR-8.x Hardening Audit (Build-plan 19)

Systematic rundown of every `FR-8.x` requirement in `docs/prd.md` (FR-8.1
through FR-8.26 — 26 rows total). This is a **verification** artifact: most
rows were already implemented and tested in earlier stages; this pass
spot-checked the actual enforcing code and re-ran the actual named tests
(not just trusted each stage's own docblocks), and fixed the two genuine
gaps found (FR-8.14's upload validation, FR-8.22's missing restore
mechanism).

Legend: ✅ covered and verified · ⚠️ gap found and fixed this stage ·
❌ gap found, not fixed (reason given).

| FR | Requirement (paraphrase) | Enforced in | Tested in | Status |
|----|---------------------------|-------------|-----------|--------|
| FR-8.1 | Lead save blocked without email or phone; missing field shown | `⚡leads.blade.php::addLead()` (`'newEmail' => required\|email`, `'newPhone' => required`) | `LeadsManagementTest::test_creating_a_lead_requires_email_and_phone`, `test_owner_can_create_a_lead_with_only_email_and_phone` | ✅ |
| FR-8.2 | Customer can only be created by converting a lead | `Lead::convertToCustomer()` — the only place `Customer::create()` is ever called; no create route exists | `LeadToCustomerTest::test_there_is_no_manual_create_route_for_customers`, `test_a_lead_cannot_be_converted_twice`, `test_the_customers_table_enforces_a_unique_lead_id_at_the_database_level` | ✅ |
| FR-8.3 | Customer can never be deleted | `App\Models\Customer` — no `SoftDeletes`, no delete route/action anywhere (structural, see class docblock) | `LeadToCustomerTest::test_customer_never_gets_a_delete_route` | ✅ |
| FR-8.4 | Deal requires exactly one of program/bundle | `Deal::createForCustomer()` (only place a `Deal` row is created) | `DealsManagementTest::test_creating_a_deal_requires_exactly_one_of_program_or_bundle`, `..._is_blocked_when_neither_program_nor_bundle_is_given`, `..._is_blocked_when_both_program_and_bundle_are_given` | ✅ |
| FR-8.5 | Cannot buy a disabled program/bundle | `Deal::createForCustomer()` (`is_active` check, throws before row is created) | `DealsManagementTest::test_creating_a_deal_against_a_disabled_program_is_blocked`, `..._disabled_bundle_is_blocked` | ✅ |
| FR-8.6 | Program/bundle price must be > 0 | `⚡programs-catalog.blade.php` (`'programPrice'/'bundlePrice' => ['required','numeric','gt:0']`) — spot-checked directly in code this stage | `ProgramsCatalogTest::test_program_price_must_be_greater_than_zero`, `test_bundle_price_must_be_greater_than_zero` — re-run this stage, pass | ✅ |
| FR-8.7 | Contract cannot be generated without a received order form | `Document::generateFor()` gate | `DocumentsEngineTest::test_contract_generation_is_blocked_without_a_received_order_form`, `..._still_blocked_when_order_form_exists_but_not_received` — re-run this stage, pass | ✅ |
| FR-8.8 | Invoice cannot be generated without a signed contract | `Document::generateFor()` gate | `DocumentsEngineTest::test_invoice_generation_is_blocked_without_a_signed_contract`, `..._still_blocked_when_contract_exists_but_not_signed` | ✅ |
| FR-8.9 | Invoice generation requires an active (עוסק פטור) business entity | `Document::generateFor()` gate | `DocumentsEngineTest::test_invoice_generation_requires_a_business_entity`, `test_invoice_generation_requires_an_active_business_entity` — re-run this stage, pass | ✅ |
| FR-8.10 | Invoice line requires description + amount | `Document::addLine()` — spot-checked directly in code this stage (`$description === '' \|\| $amount <= 0` throws) | `DocumentsEngineTest::test_invoice_line_without_description_is_rejected`, `test_invoice_line_without_a_positive_amount_is_rejected` — re-run this stage, pass | ✅ |
| FR-8.11 | Invoice total always equals sum of its lines | `Document::totalAmount()` — spot-checked: always `$this->lines()->sum('amount')`, never a settable column | `DocumentsEngineTest::test_invoice_total_always_equals_sum_of_lines` — re-run this stage, pass | ✅ |
| FR-8.12 | Cannot generate a credit note for a deal with no invoice | `Document::generateFor()` / `Subscription::generateCreditNote()` | `SubscriptionsTest::test_credit_note_generation_is_blocked_without_an_invoice`, `test_credit_note_cannot_be_generated_before_cancellation` | ✅ |
| FR-8.13 | Sending materials requires ≥1 recipient with a valid email | `MaterialDelivery::sendFor()` | `MaterialsMailingTest::test_sending_materials_is_blocked_with_no_recipient` | ✅ |
| FR-8.14 | Sending materials requires ≥1 attachment | `MaterialDelivery::sendFor()` (presence check) **+ this stage:** real file-level validation (size/type) was missing entirely — see Security section | `MaterialsMailingTest::test_sending_materials_is_blocked_with_no_attachment` **+ new:** `test_an_oversized_attachment_is_rejected`, `test_an_attachment_with_a_disallowed_mime_type_is_rejected` | ⚠️ fixed — upload had no `max`/type validation at all; added (see Security §1) |
| FR-8.15 | Business-rule violation shows a blocking, explanatory error | Shared `<x-business-error-banner>` partial + per-component `?string $xxxError` pattern (`app/Concerns/Notifies.php`) | `SystemNotificationsTest::test_business_error_banner_still_blocks_and_explains_a_duplicate_subscription_program`, `..._still_shows_a_concurrent_status_update_conflict`, plus implicitly every `_is_blocked_`/`_requires_`-style test across the suite | ✅ |
| FR-8.16 | Smove/Summit integration failure shows an immediate alert | — | — | ❌ genuinely blocked on stage 12 (external integrations), which was deliberately skipped — no real Smove/Summit credentials exist, and no `EXTERNAL_OPERATION` table/model was ever built (it exists only as a design entity in `docs/erd.md`). Cannot be honestly implemented/tested without that stage. |
| FR-8.17 | Integration failure never shown as if it succeeded | — | — | ❌ same as FR-8.16 — same missing stage-12 dependency. |
| FR-8.18 | Activity history preserved even when a manual action follows an external failure | `ActivityLog` itself is immutable and append-only regardless of trigger source (`App\Models\ActivityLog::booted()`, `isManual()`) — this general guarantee is solid | `CoreAuthRolesActivityLogTest::test_activity_log_entries_cannot_be_updated_or_deleted`, `test_activity_logger_creates_a_real_row_and_tracks_manual_vs_system` | ❌ the *general* immutability/manual-vs-system tracking is ✅, but the specific scenario ("manual action after an external failure") needs stage 12's `EXTERNAL_OPERATION` rows to exist to be tested end-to-end — blocked on stage 12 for the full scenario. |
| FR-8.19 | Concurrent work by two users stays consistent, no silent overwrite | `Deal::updateStatusWithLock()`, `Deal::recordPayment()`, `Subscription::markDeliverySupplied()` — all atomic `WHERE id=? AND version=?` updates | `DealsManagementTest::test_concurrent_status_update_is_rejected_with_a_conflict_error`; `PaymentsCollectionsTest::test_concurrent_payment_recording_is_rejected_with_a_conflict_error` + `test_a_rejected_concurrent_payment_never_creates_a_payment_row`; `SubscriptionsTest::test_concurrent_delivery_marking_is_rejected_with_a_conflict_error` + `test_a_rejected_concurrent_delivery_marking_never_updates_the_delivery_row` | ✅ verified this stage (work item 3) — all three genuinely bump `version` out from under a loaded model via a real prior successful update, assert the stale attempt is rejected, and assert no data corruption. No strengthening needed. |
| FR-8.20 | Mobile browser usable for quick checks (not full workflows) | CSS responsive breakpoints (`resources/css/layout.css`), mobile sidebar toggle (`resources/views/layouts/app.blade.php`) — build-plan 17 | No dedicated automated test — this is presentation/CSS behavior; no browser/Dusk testing is configured in this stack. Verified manually per stage 17's own Definition of Done. | ✅ (judgment call: appropriately untested at the PHPUnit/business-logic level — there is no business rule here to regress-test, only a visual layout) |
| FR-8.21 | Historical Excel/CSV data included in onboarding, no "start from zero" | `App\Console\Commands\ImportLegacyData` (`legacy:import`) | `LegacyDataImportTest` — 8 tests (creation, idempotent re-run, dry-run, unmatched-program skip+report, backdated activity logs, summary report file) | ✅ |
| FR-8.22 | System allows restoring accidentally-lost/deleted information per a defined recovery policy | **Previously nothing existed** — `Contact`/`Task` were soft-deletable since stage 4 but had no restore path anywhere. **Fixed this stage:** `Contact::restore()` (`app/Models/Contact.php`), `Task::restore()` (`app/Models/Task.php`) — both log to `ActivityLog`; "פריטים שהוסרו לאחרונה" panels on `⚡lead-detail.blade.php` and `⚡customer-detail.blade.php` list soft-deleted rows from the last ~30 days with a "שחזור" button | `LeadsManagementTest::test_a_removed_lead_contact_can_be_restored`, `test_a_cancelled_task_can_be_restored`, `test_the_recovery_panel_excludes_items_removed_more_than_thirty_days_ago`; `LeadToCustomerTest::test_a_removed_customer_contact_can_be_restored`, `test_restoring_a_primary_contact_updates_the_last_primary_bookkeeping`, `test_a_cancelled_customer_task_can_be_restored`, `test_the_recovery_panel_excludes_contacts_removed_more_than_thirty_days_ago` | ⚠️ fixed this stage — see "FR-8.22 in detail" below |
| FR-8.23 | Customer card alerts when standalone-program purchases exceed a full annual subscription price | `Customer::exceedsSubscriptionPriceAlert()` / `standaloneProgramsTotal()` | `SubscriptionsTest::test_price_exceeded_alert_appears_when_standalone_purchases_exceed_the_subscription_price`, `..._does_not_appear_below_the_threshold`, `..._bundle_deals_never_count_toward_the_price_exceeded_alert`, `..._the_subscription_type_program_itself_never_counts_as_a_standalone_purchase` | ✅ |
| FR-8.24 | Subscription cancellation credit computed dynamically from programs supplied and the agreed price | `Subscription`'s cancellation-credit calculation, used by `generateCreditNote()` | `SubscriptionsTest::test_cancellation_credit_is_computed_from_supplied_count_and_agreed_price`, `test_cancellation_credit_uses_the_agreed_price_not_the_catalog_price`, `test_other_deals_and_bundles_never_affect_the_cancellation_credit` | ✅ |
| FR-8.25 | Disabling/removing a program from the active catalog leaves historical deals showing it as it was | Deals snapshot the program's name/price at purchase time (never a live join for historical display); `Program` is disabled (`is_active`), never deleted | `DealsManagementTest::test_deal_snapshot_remains_stable_after_the_live_program_changes`; `ProgramsCatalogTest::test_toggling_a_program_never_deletes_it` | ✅ |
| FR-8.26 | Business relationships (customer/deals/documents/payments/subscriptions/deliveries) preserved across their whole lifecycle | FK constraints (`restrictOnDelete`/`nullOnDelete`, `cascadeOnDelete` reserved for genuinely dependent join tables only — see migrations); no delete route exists anywhere for any business entity | The "no delete route for X" tests across every module: `DealsManagementTest::test_there_is_no_delete_route_for_deals`, `DocumentsEngineTest::test_there_is_no_delete_route_for_documents_or_templates`, `PaymentsCollectionsTest::test_there_is_no_delete_route_for_payments_receipts_or_collections`, `SubscriptionsTest::test_there_is_no_delete_route_for_subscriptions_or_deliveries` + `test_cancellation_only_changes_status_and_never_deletes_deal_subscription_or_deliveries`, `MaterialsMailingTest::test_there_is_no_delete_route_for_materials_or_mailing_lists`, `LeadToCustomerTest::test_customer_never_gets_a_delete_route`, `LeadsManagementTest::test_lead_never_gets_a_delete_route`, `ProgramsCatalogTest::test_no_delete_route_exists_for_programs_or_bundles` | ✅ |

**Tally:** 21 ✅ · 2 ⚠️ (fixed this stage: FR-8.14, FR-8.22) · 3 ❌ (FR-8.16,
FR-8.17, FR-8.18 — all genuinely blocked on stage 12's external
integrations, deliberately out of scope for this project).

## FR-8.22 in detail

Before this stage, `Contact` and `Task` (confirmed via `grep -rl SoftDeletes
app/Models` — still the complete list; `Customer` explicitly documents it
does *not* use `SoftDeletes`) could be soft-deleted (`removeContact()`,
`cancelContactEdit()`/`cancelTask()`) but had no restore path at all — a
misclick was permanent from the user's point of view even though the row
technically survived in the database.

Added:
- `Contact::restore(ActivityLogger $activityLogger): bool` (`app/Models/Contact.php`) — clears `deleted_at` (via an aliased `SoftDeletes::restore`) and logs `contact.restored`. Restoring is purely additive, so it cannot violate FR-2.8/FR-2.9's "at least one primary contact" invariant (that invariant is only checked on *removal*/*unmarking*, both already guarded and unaffected by this change) — verified explicitly by `test_restoring_a_primary_contact_updates_the_last_primary_bookkeeping`.
- `Task::restore(ActivityLogger $activityLogger): bool` (`app/Models/Task.php`) — same pattern, logs `task.restored`.
- "פריטים שהוסרו לאחרונה" panel on `⚡lead-detail.blade.php` (this lead's school's removed contacts + this lead's own cancelled reminders) and `⚡customer-detail.blade.php` (this customer's removed contacts + any cancelled tasks tied to `customer_id`), both scoped to `deleted_at >= now()->subDays(30)`, each row with a "שחזור" button. Both panels only render when there's something to show.
- No new permission gate was needed — restore actions ride on the same page-level `customers.manage`/`leads.manage`/`leads.view` (+ `LeadPolicy::view()`) checks already enforced in each component's `mount()`.
- Scope kept deliberately minimal per the stage brief: a plain list + restore button, no trash-bin screen, no search/pagination/filtering.

## Security review

See `docs/security-review-19.md` for the full write-up (file uploads, the
signed acknowledge route, permission-gate exhaustiveness, raw-SQL/mass
assignment spot-check, and the deal/document IDOR check).

## CI

`.github/workflows/tests.yml` runs `php artisan test` on every push/PR to
`main` — see that file's header comment and the report for this stage.

---
[<< 19 — ולידציות קצה, אבטחה ואמינות](build-plan/19-hardening-qa.md) | [אינדקס](build-plan/README.md)
