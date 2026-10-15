<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Build-plan 02/12 — EMAIL_TEMPLATE: operational emails the system sends
 * directly via SMTP (Laravel Mail, config/mail.php — 'log' driver until the
 * business owner configures a real one), never via Smove (see this model's
 * settings-screen note distinguishing the two). render() below is this
 * stage's addition — a pure, stateless {{key}}-placeholder substitution
 * (matching ReferenceDataSeeder's seeded 'אישור קליטת ליד' template content
 * exactly), used by Lead::createFromLandingPage() (FR-1.18).
 */
#[Fillable(['name', 'template_type', 'subject', 'content', 'is_active'])]
class EmailTemplate extends Model
{
    use HasFactory;

    /**
     * What a template is used for, by Hebrew label. The key is what the code
     * looks up (e.g. Lead::sendLandingPageConfirmation() wants
     * 'lead_confirmation'); 'general' is a template nothing sends automatically.
     */
    public const TYPE_LABELS = [
        'lead_confirmation' => 'אישור קליטת ליד (נשלח אוטומטית)',
        'general' => 'כללי',
    ];

    /**
     * The Hebrew placeholders a template's subject/content can use, mapped to
     * the data key render() receives. The older English spelling
     * ({{contact_name}}) still works — render() accepts both.
     */
    public const PLACEHOLDERS = [
        'שם_איש_הקשר' => 'contact_name',
        'שם_בית_הספר' => 'school_name',
    ];

    /**
     * The email asking a supplier for a missing invoice (Expense::requestInvoice()).
     * Seeded by migration, edited only on the document-templates page — kept
     * out of TYPE_LABELS so the settings screen neither lists nor creates one.
     */
    public const SUPPLIER_INVOICE_REQUEST = 'supplier_invoice_request';

    /** The expense row's details a supplier-invoice-request email can include — Expense::invoiceRequestData(). */
    public const EXPENSE_PLACEHOLDERS = [
        'שם_הספק' => 'supplier_name',
        'תאריך_התשלום' => 'expense_date',
        'סכום' => 'amount',
        'תוכנית' => 'program_name',
        'הערות' => 'notes',
        'מספר_הוצאה' => 'expense_id',
    ];

    /** One email asking a supplier for several missing invoices at once — Expense::requestAllMissingInvoices(). Same editing rules as SUPPLIER_INVOICE_REQUEST. */
    public const SUPPLIER_INVOICES_REQUEST = 'supplier_invoices_request';

    /** The supplier-level details a several-invoices request can include — Expense::requestAllMissingInvoices(). */
    public const SUPPLIER_PLACEHOLDERS = [
        'שם_הספק' => 'supplier_name',
        'מספר_החשבוניות_החסרות' => 'expense_count',
        'סכום_כולל' => 'total_amount',
    ];

    /** Where the list of expenses (each with its own upload button) goes in a several-invoices request (appended at the end when absent). */
    public const EXPENSE_LIST_TOKEN = '{{רשימת_ההוצאות}}';

    /** The supplier-invoice email types — edited on the document-templates page, never in settings. */
    public const SUPPLIER_TYPES = [self::SUPPLIER_INVOICE_REQUEST, self::SUPPLIER_INVOICES_REQUEST];

    public static function supplierInvoicesRequest(): ?self
    {
        return self::where('template_type', self::SUPPLIER_INVOICES_REQUEST)->first();
    }

    /** Where the "upload the invoice" button goes in a supplier-invoice-request email (appended at the end when absent). */
    public const UPLOAD_BUTTON_TOKEN = '{{כפתור_העלאה}}';

    public static function supplierInvoiceRequest(): ?self
    {
        return self::where('template_type', self::SUPPLIER_INVOICE_REQUEST)->first();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(EmailTemplateField::class)->orderBy('sort_order');
    }

    /**
     * @param  array<string, string>  $data  e.g. ['contact_name' => ..., 'school_name' => ...]
     * @return array{subject: string, content: string}
     */
    public function render(array $data): array
    {
        $replace = static fn (string $text): string => preg_replace_callback(
            '/\{\{\s*([\p{L}\p{N}_.]+)\s*\}\}/u',
            fn ($m) => $data[self::PLACEHOLDERS[$m[1]] ?? self::EXPENSE_PLACEHOLDERS[$m[1]] ?? self::SUPPLIER_PLACEHOLDERS[$m[1]] ?? $m[1]] ?? $m[0],
            $text,
        );

        return ['subject' => $replace($this->subject), 'content' => $replace($this->content)];
    }
}
