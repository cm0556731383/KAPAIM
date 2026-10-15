<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Build-plan 11 — EXPENSE (FR-6.5-FR-6.10). createForSupplier() below is the
 * only place an Expense row is ever created, and attachInvoice() is the only
 * place a document is ever attached to one — the same "one clear
 * business-action factory method, throws RuntimeException on rule
 * violation" pattern as Deal::createForCustomer()/recordPayment() and
 * Receipt::issueFor().
 *
 * An expense can be edited, and removed (a logical delete) only while no
 * invoice is attached — see deleteIfNoInvoice().
 */
#[Fillable(['supplier_id', 'program_id', 'document_id', 'status_id', 'amount', 'expense_date', 'notes'])]
class Expense extends Model
{
    use HasFactory, SoftDeletes;

    /** STATUS_DEFINITION scope='expense' names — seeded by ReferenceDataSeeder. */
    public const MISSING_INVOICE_STATUS_NAME = 'ממתינה לחשבונית';

    public const HAS_INVOICE_STATUS_NAME = 'תועדה';

    /** Where uploaded supplier invoices live on the private 'local' disk. */
    public const INVOICE_DIRECTORY = 'expense-invoices';

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
     * Once an invoice document is attached the expense is documented
     * bookkeeping, so it stays; before that it's just a mistaken entry.
     *
     * @throws RuntimeException when an invoice is already attached.
     */
    public function deleteIfNoInvoice(): void
    {
        if ($this->document_id) {
            throw new RuntimeException('לא ניתן למחוק הוצאה שכבר צורפה לה חשבונית.');
        }

        $this->delete();
    }

    /**
     * FR-6.6/FR-6.10: the only place an invoice is ever attached to an
     * expense. Stores the supplier's actual invoice file (PDF or image) on
     * the private 'local' disk and records it on an 'expense_invoice'
     * Document (Document::generateFor()'s Expense branch) — file_reference
     * holds the stored path, field_values the original file name. Flips
     * this expense's status to HAS_INVOICE_STATUS_NAME (FR-6.10).
     *
     * A wrong file is fixed by removeInvoice() and uploading again.
     */
    public function attachInvoice(UploadedFile $file): Document
    {
        $document = $this->document ?? Document::generateFor($this, 'expense_invoice');

        $document->update([
            'file_reference' => $file->store(self::INVOICE_DIRECTORY, 'local'),
            'field_values' => ['file_name' => $file->getClientOriginalName()],
            'received_at' => now(),
        ]);

        $hasInvoiceStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'expense', 'name' => self::HAS_INVOICE_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 2],
        );

        $this->update(['document_id' => $document->id, 'status_id' => $hasInvoiceStatus->id]);

        return $document;
    }

    /**
     * Removes the attached invoice entirely — the stored file and its
     * 'expense_invoice' Document row (nothing else ever references one) —
     * and returns the expense to MISSING_INVOICE_STATUS_NAME, so a wrong
     * file is fixed by deleting it and uploading the right one.
     */
    public function removeInvoice(): void
    {
        $document = $this->document;

        if (! $document) {
            return;
        }

        $missingStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'expense', 'name' => self::MISSING_INVOICE_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        DB::transaction(function () use ($document, $missingStatus) {
            $this->update(['document_id' => null, 'status_id' => $missingStatus->id]);
            $document->delete();
        });

        if ($document->file_reference) {
            Storage::disk('local')->delete($document->file_reference);
        }

        $this->unsetRelation('document');
    }

    /** Whether an actual invoice file is on hand (an older invoice row may have none). */
    public function hasInvoiceFile(): bool
    {
        return (bool) $this->document?->file_reference
            && Storage::disk('local')->exists($this->document->file_reference);
    }

    /** The name the invoice file downloads under — the original upload name. */
    public function invoiceFileName(): string
    {
        $extension = pathinfo((string) $this->document?->file_reference, PATHINFO_EXTENSION);

        return $this->document?->field_values['file_name'] ?? "invoice-{$this->id}.{$extension}";
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
