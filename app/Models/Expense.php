<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Build-plan 11 — EXPENSE (FR-6.5-FR-6.10). createForSupplier() below is the
 * only place an Expense row is ever created, and attachInvoice() is the only
 * place a document is ever attached to one — the same "one clear
 * business-action factory method, throws RuntimeException on rule
 * violation" pattern as Deal::createForCustomer()/recordPayment() and
 * Receipt::issueFor().
 *
 * An expense is never deleted (matches every other business-record
 * convention in this codebase) — there is no soft-deletes column.
 */
#[Fillable(['supplier_id', 'program_id', 'document_id', 'status_id', 'amount', 'expense_date', 'notes'])]
class Expense extends Model
{
    use HasFactory;

    /** STATUS_DEFINITION scope='expense' names — seeded by ReferenceDataSeeder. */
    public const MISSING_INVOICE_STATUS_NAME = 'ממתינה לחשבונית';

    public const HAS_INVOICE_STATUS_NAME = 'תועדה';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    /**
     * FR-6.5/FR-6.6: the only place an Expense row is ever created. $program
     * is the sole optional business field — some expenses aren't tied to a
     * specific catalog program (FR-6.13's by-program profit calculation
     * simply excludes those at the by-program level — see RevenueReport).
     * Every expense starts in the "missing invoice" status.
     *
     * @throws RuntimeException on a business-rule violation.
     */
    public static function createForSupplier(
        Supplier $supplier,
        float $amount,
        string $expenseDate,
        ?Program $program = null,
        ?string $notes = null,
    ): self {
        if ($amount <= 0) {
            throw new RuntimeException('סכום ההוצאה חייב להיות גדול מאפס.');
        }

        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'expense', 'name' => self::MISSING_INVOICE_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        return self::create([
            'supplier_id' => $supplier->id,
            'program_id' => $program?->id,
            'document_id' => null,
            'status_id' => $status->id,
            'amount' => $amount,
            'expense_date' => $expenseDate,
            'notes' => $notes,
        ]);
    }

    /**
     * FR-6.6/FR-6.10: the only place an invoice Document is ever attached to
     * an expense. Reuses Document::generateFor()'s Expense branch (this is a
     * manual "record the invoice the supplier already sent us" step, not a
     * digital-form-fill flow like the sales document chain — see that
     * method's docblock) with the sole active 'expense_invoice' template,
     * the same "sole active template of that type" convention
     * ⚡deal-detail.blade.php's generateDocument() uses. Flips this expense's
     * status to HAS_INVOICE_STATUS_NAME automatically (FR-6.10).
     *
     * @throws RuntimeException when this expense already has an invoice
     *                          attached, or no active 'expense_invoice'
     *                          template exists yet.
     */
    public function attachInvoice(): Document
    {
        if ($this->document_id) {
            throw new RuntimeException('להוצאה זו כבר מצורפת חשבונית.');
        }

        $template = DocumentTemplate::where('document_type', 'expense_invoice')->where('is_active', true)->orderBy('id')->first();

        if (! $template) {
            throw new RuntimeException('לא נמצאה תבנית פעילה עבור חשבונית הוצאה — יש להגדיר תבנית תחילה במסך תבניות מסמכים.');
        }

        $document = Document::generateFor($this, $template);

        $hasInvoiceStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'expense', 'name' => self::HAS_INVOICE_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 2],
        );

        $this->update(['document_id' => $document->id, 'status_id' => $hasInvoiceStatus->id]);

        return $document;
    }

    /**
     * FR-6.7-FR-6.9: eligible for a "missing invoice" reminder once the
     * expense's own month has fully closed — i.e. expense_date falls before
     * the first day of the current calendar month. An expense from the
     * still-open current month is never included, and an expense that
     * already has a document attached is never included regardless of
     * month. See App\Console\Commands\ProcessExpenseReminders.
     */
    public function scopeMissingInvoiceForClosedMonth(Builder $query): Builder
    {
        return $query->whereNull('document_id')
            ->whereDate('expense_date', '<', now()->startOfMonth()->toDateString());
    }
}
