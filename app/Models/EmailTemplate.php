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
#[Fillable(['name', 'template_type', 'subject', 'content', 'content_format', 'is_active'])]
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

    /** A template nothing sends automatically — created, named and deleted in settings, picked when sending to mailing lists or sending materials. */
    public const GENERAL = 'general';

    /** The template's text goes inside the branded shell (BrandedEmail::wrap()). */
    public const FORMAT_TEXT = 'text';

    /** The template is the owner's own HTML (typed or uploaded), sent as is. */
    public const FORMAT_HTML = 'html';

    /** The types whose template may be HTML — the others are text only. */
    public const HTML_TYPES = [self::GENERAL, self::MATERIAL_DELIVERY];

    /** Placeholders filled per customer — a mailing-list send can't fill them. */
    public const PERSONAL_PLACEHOLDERS = ['שם_איש_הקשר', 'שם_בית_הספר', 'תוכנית'];

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
     * Seeded by migration, edited in settings → תבניות דואר — kept out of
     * TYPE_LABELS, so it can't be created there or switched to another use.
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

    /** The supplier-invoice email types. */
    public const SUPPLIER_TYPES = [self::SUPPLIER_INVOICE_REQUEST, self::SUPPLIER_INVOICES_REQUEST];

    /**
     * The one reminder a customer gets for materials she hasn't confirmed
     * (materials:process-reminders). Seeded by migration; can use
     * {{שם_בית_הספר}} and {{תוכנית}}, and ACKNOWLEDGE_BUTTON_TOKEN.
     */
    public const MATERIAL_REMINDER = 'material_reminder';

    /** Where the "I received the materials" button goes in the reminder (appended at the end when absent). */
    public const ACKNOWLEDGE_BUTTON_TOKEN = '{{כפתור_אישור_קבלה}}';

    /**
     * The materials email itself (MaterialDelivery::sendFor()). Seeded by
     * migration; can use {{שם_בית_הספר}} and {{תוכנית}}, MATERIALS_LIST_TOKEN
     * and ACKNOWLEDGE_BUTTON_TOKEN. Always sent — it can't be disabled.
     */
    public const MATERIAL_DELIVERY = 'material_delivery';

    /** The materials email's wording as seeded — the download and "received" buttons always follow it (EmailMessage::materials()). */
    public const MATERIAL_DELIVERY_DEFAULT_CONTENT = "שלום,\n\nשמחים לשלוח לכם את חומרי הלימוד של {{תוכנית}}.\nאפשר להוריד את כולם בלחיצה על \"להורדת החומרים\", ואחרי שהורדתם — נשמח שתאשרו בלחיצה על \"קיבלתי, תודה\".\n\nבברכה, כפיים";

    /** Old token for the list of material links — still used by mailing-list emails; dropped from a materials email, whose buttons replace it. */
    public const MATERIALS_LIST_TOKEN = '{{רשימת_החומרים}}';

    /** A document sent alone from its card (quote / order form / contract — Document::emailMessage()): the online-form button goes where DOCUMENT_BUTTON_TOKEN sits. */
    public const DOCUMENT_SEND = 'document_send';

    /** The order form and its contract sent together in one email — a button per document where DOCUMENT_BUTTON_TOKEN sits. */
    public const DOCUMENTS_SEND = 'documents_send';

    /** A one-time SUMIT payment link (PaymentLink::emailMessage()) — the payment button goes where PAYMENT_BUTTON_TOKEN sits. */
    public const PAYMENT_LINK = 'payment_link';

    /** A SUMIT link to set up a standing order — same as PAYMENT_LINK, with the number of months. */
    public const STANDING_ORDER_LINK = 'standing_order_link';

    /** A user's morning email of her reminders for the day (tasks:send-reminders) — the list goes where TASK_LIST_TOKEN sits. Disabled → no morning emails. */
    public const TASK_REMINDERS = 'task_reminders';

    /** Where the document's online-form button (or the pair's two buttons) goes. */
    public const DOCUMENT_BUTTON_TOKEN = '{{כפתור_המסמך}}';

    /** Where the payment button goes in a payment-link email. */
    public const PAYMENT_BUTTON_TOKEN = '{{כפתור_התשלום}}';

    /** Where the list of the day's reminders goes in the morning email. */
    public const TASK_LIST_TOKEN = '{{רשימת_התזכורות}}';

    /** What a document email can include — Document::emailMessage(). */
    public const DOCUMENT_PLACEHOLDERS = [
        'שם_הנמען' => 'recipient_name',
        'סוג_המסמך' => 'document_type',
        'המסמכים' => 'documents',
    ];

    /** What a payment-link email can include — PaymentLink::emailMessage(). */
    public const PAYMENT_PLACEHOLDERS = [
        'שם_הנמען' => 'recipient_name',
        'פריט' => 'item',
        'סכום' => 'amount',
        'מספר_חודשים' => 'months',
        'תוקף_הקישור' => 'expires_at',
    ];

    /** What the morning reminders email can include — SendTaskReminders. */
    public const TASK_PLACEHOLDERS = [
        'שם_המשתמשת' => 'user_name',
        'מספר_התזכורות' => 'task_count',
    ];

    /**
     * The wording each system email is seeded with (migration 2026_10_27_000600)
     * — and what is sent if its row is ever missing (forType()).
     */
    public const SYSTEM_DEFAULTS = [
        self::DOCUMENT_SEND => [
            'name' => 'שליחת מסמך',
            'subject' => '{{סוג_המסמך}}',
            'content' => "שלום {{שם_הנמען}},\n\nמצורף בהמשך קישור ל\"{{סוג_המסמך}}\" שהוכן עבורך, לצפייה, למילוי פרטים ולאישור מקוון. המסמך מצורף למייל גם כקובץ PDF.\n\n{{כפתור_המסמך}}\n\nבברכה, כפיים",
        ],
        self::DOCUMENTS_SEND => [
            'name' => 'שליחת טופס הזמנה וחוזה',
            'subject' => '{{המסמכים}}',
            'content' => "שלום {{שם_הנמען}},\n\nמצורפים בהמשך קישורים ל{{המסמכים}} שהוכנו עבורך, לצפייה, למילוי פרטים ולאישור מקוון. כל מסמך מאושר בקישור שלו. המסמכים מצורפים למייל גם כקובצי PDF.\n\n{{כפתור_המסמך}}\n\nבברכה, כפיים",
        ],
        self::PAYMENT_LINK => [
            'name' => 'קישור לתשלום',
            'subject' => 'קישור לתשלום — {{פריט}}',
            'content' => "שלום {{שם_הנמען}},\n\nלתשלום עבור \"{{פריט}}\" בסך ₪{{סכום}}, בכרטיס אשראי בדף התשלום המאובטח של SUMIT. הקבלה תישלח אלייך במייל מיד עם התשלום.\n\n{{כפתור_התשלום}}\n\nהקישור בתוקף עד {{תוקף_הקישור}}.\n\nבברכה, כפיים",
        ],
        self::STANDING_ORDER_LINK => [
            'name' => 'קישור להקמת הוראת קבע',
            'subject' => 'הקמת הוראת קבע — {{פריט}}',
            'content' => "שלום {{שם_הנמען}},\n\nלהקמת הוראת קבע עבור \"{{פריט}}\": ₪{{סכום}} לחודש, {{מספר_חודשים}} חודשים. התשלום הראשון מתבצע עכשיו בכרטיס אשראי בדף התשלום המאובטח של SUMIT, והתשלומים הבאים יחויבו אוטומטית מאותו כרטיס בכל חודש. על כל תשלום תישלח אלייך קבלה במייל.\n\n{{כפתור_התשלום}}\n\nהקישור בתוקף עד {{תוקף_הקישור}}.\n\nבברכה, כפיים",
        ],
        self::TASK_REMINDERS => [
            'name' => 'תזכורות בוקר למשתמשות',
            'subject' => 'התזכורות שלך להיום ({{מספר_התזכורות}})',
            'content' => "בוקר טוב {{שם_המשתמשת}}, אלה התזכורות שלך להיום:\n\n{{רשימת_התזכורות}}",
        ],
    ];

    /** Emails the code looks up by type — settings never lets their type change. */
    public const FIXED_TYPES = [...self::SUPPLIER_TYPES, self::MATERIAL_DELIVERY, self::MATERIAL_REMINDER, ...self::SYSTEM_TYPES];

    /** The system emails seeded from SYSTEM_DEFAULTS. */
    public const SYSTEM_TYPES = [self::DOCUMENT_SEND, self::DOCUMENTS_SEND, self::PAYMENT_LINK, self::STANDING_ORDER_LINK, self::TASK_REMINDERS];

    /** Emails that are always sent — settings offers no disable button for them. */
    public const ALWAYS_ACTIVE_TYPES = [self::MATERIAL_DELIVERY, self::DOCUMENT_SEND, self::DOCUMENTS_SEND, self::PAYMENT_LINK, self::STANDING_ORDER_LINK];

    /** What each fixed email is for — the settings list's "שימוש" column. */
    public const FIXED_LABELS = [
        self::SUPPLIER_INVOICE_REQUEST => 'בקשת חשבונית מספק',
        self::SUPPLIER_INVOICES_REQUEST => 'בקשת כמה חשבוניות מספק',
        self::MATERIAL_DELIVERY => 'שליחת חומרי לימוד',
        self::MATERIAL_REMINDER => 'תזכורת על חומרים שלא אושרו',
        self::DOCUMENT_SEND => 'שליחת מסמך (הצעת מחיר / טופס הזמנה / חוזה)',
        self::DOCUMENTS_SEND => 'שליחת טופס הזמנה וחוזה יחד',
        self::PAYMENT_LINK => 'קישור לתשלום',
        self::STANDING_ORDER_LINK => 'קישור להקמת הוראת קבע',
        self::TASK_REMINDERS => 'מייל תזכורות בוקר (פנימי)',
    ];

    /** The settings list's "שימוש" text for a template. */
    public function usageLabel(): string
    {
        return self::FIXED_LABELS[$this->template_type] ?? self::TYPE_LABELS[$this->template_type] ?? 'כללי';
    }

    /** A system email's template — its saved row, or SYSTEM_DEFAULTS' wording when the row is missing. */
    public static function forType(string $type): self
    {
        return self::where('template_type', $type)->first()
            ?? new self([...self::SYSTEM_DEFAULTS[$type], 'template_type' => $type, 'is_active' => true]);
    }

    public function isHtml(): bool
    {
        return $this->content_format === self::FORMAT_HTML;
    }

    /**
     * The templates offered when composing an email by hand: the active
     * general ones, plus $alsoTypes (e.g. the materials email on the customer card).
     *
     * @param  list<string>  $alsoTypes
     */
    public static function pickable(array $alsoTypes = [])
    {
        return self::whereIn('template_type', [self::GENERAL, ...$alsoTypes])->where('is_active', true)->orderBy('name')->get()
            ->sortBy(fn (self $template) => $template->template_type === self::GENERAL)->values();
    }

    public static function materialDelivery(): ?self
    {
        return self::where('template_type', self::MATERIAL_DELIVERY)->first();
    }

    public static function materialReminder(): ?self
    {
        return self::where('template_type', self::MATERIAL_REMINDER)->first();
    }

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
            fn ($m) => $data[self::PLACEHOLDERS[$m[1]] ?? self::EXPENSE_PLACEHOLDERS[$m[1]] ?? self::SUPPLIER_PLACEHOLDERS[$m[1]] ?? self::DOCUMENT_PLACEHOLDERS[$m[1]] ?? self::PAYMENT_PLACEHOLDERS[$m[1]] ?? self::TASK_PLACEHOLDERS[$m[1]] ?? $m[1]] ?? $m[0],
            $text,
        );

        return ['subject' => $replace($this->subject), 'content' => $replace($this->content)];
    }
}
