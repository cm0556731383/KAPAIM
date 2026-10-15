<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Chronological, append-only record of significant system events (FR-7.17–FR-7.20).
 * Entries are never edited or deleted — booted() below enforces that at the model
 * layer regardless of what any future controller/UI tries to do.
 */
#[Fillable([
    'user_id', 'school_id', 'lead_id', 'customer_id', 'deal_id', 'subscription_id',
    'material_delivery_id', 'task_id', 'document_id', 'expense_id', 'external_operation_id',
    'activity_type', 'description', 'metadata', 'occurred_at',
])]
class ActivityLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Activity log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Activity log entries are immutable and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** How each activity_type reads on screen — the technical key itself is never shown. */
    public const TYPE_LABELS = [
        'app_setting.updated' => 'עדכון הגדרות מערכת',
        'bundle.created' => 'מארז חדש',
        'bundle.program_added' => 'תוכנית נוספה למארז',
        'bundle.program_removed' => 'תוכנית הוסרה ממארז',
        'bundle.toggled' => 'הפעלה/השבתה של מארז',
        'business_entity.created' => 'עוסק חדש',
        'business_entity.updated' => 'עדכון עוסק',
        'business_entity.toggled' => 'הפעלה/השבתה של עוסק',
        'business_entity.sumit_updated' => 'עדכון חיבור SUMIT של עוסק',
        'contact.created' => 'איש קשר חדש',
        'contact.removed' => 'הסרת איש קשר',
        'contact.restored' => 'שחזור איש קשר',
        'contact.updated' => 'עדכון איש קשר',
        'customer.created' => 'לקוחה חדשה',
        'customer.school_details_updated' => 'עדכון פרטי בית ספר',
        'customer.school_updated' => 'עדכון פרטי בית ספר',
        'deal.created' => 'עסקה חדשה',
        'deal.standing_order_stopped' => 'עצירת הוראת קבע',
        'deal.status_changed' => 'שינוי סטטוס עסקה',
        'deal.updated' => 'עדכון עסקה',
        'document.confirmed' => 'מסמך אושר',
        'document.credit_note_generated' => 'הפקת חשבונית זיכוי',
        'document.deleted' => 'מחיקת מסמך',
        'document.demo_chain_seeded' => 'יצירת מסמכים לדוגמה',
        'document.pdf_emailed' => 'מסמך נשלח כ-PDF',
        'document.received' => 'מסמך התקבל',
        'document.sent' => 'מסמך נשלח',
        'document.signed' => 'מסמך נחתם',
        'document_template.created' => 'תבנית מסמך חדשה',
        'document_template.toggled' => 'הפעלה/השבתה של תבנית מסמך',
        'document_template.updated' => 'עדכון תבנית מסמך',
        'document_template_field.created' => 'שדה חדש בתבנית מסמך',
        'document_template_field.removed' => 'הסרת שדה מתבנית מסמך',
        'email_template.created' => 'תבנית דואר חדשה',
        'email_template.updated' => 'עדכון תבנית דואר',
        'email_template.toggled' => 'הפעלה/השבתה של תבנית דואר',
        'email_template_field.created' => 'שדה חדש בתבנית דואר',
        'email_template_field.removed' => 'הסרת שדה מתבנית דואר',
        'expense.created' => 'הוצאה חדשה',
        'expense.deleted' => 'מחיקת הוצאה',
        'expense.invoice_attached' => 'צירוף חשבונית להוצאה',
        'expense.invoice_removed' => 'מחיקת חשבונית מהוצאה',
        'expense.invoice_uploaded_by_supplier' => 'הספק העלה חשבונית',
        'expense.updated' => 'עדכון הוצאה',
        'external_integration_setting.updated' => 'עדכון הגדרות חיבור',
        'external_integration_setting.toggled' => 'הפעלה/השבתה של חיבור',
        'lead.assigned' => 'שיוך ליד למטפלת',
        'lead.bundle_interest_added' => 'התעניינות במארז נוספה',
        'lead.bundle_interest_removed' => 'התעניינות במארז הוסרה',
        'lead.program_interest_added' => 'התעניינות בתוכנית נוספה',
        'lead.program_interest_removed' => 'התעניינות בתוכנית הוסרה',
        'lead.confirmation_email_skipped' => 'מייל אישור לליד לא נשלח',
        'lead.converted' => 'ליד הומר ללקוחה',
        'lead.created' => 'ליד חדש',
        'lead.follow_up_deleted' => 'מחיקת Follow Up',
        'lead.follow_up_logged' => 'תיעוד Follow Up',
        'lead.follow_up_updated' => 'עדכון Follow Up',
        'lead.interaction_deleted' => 'מחיקת אינטראקציה',
        'lead.interaction_logged' => 'תיעוד אינטראקציה',
        'lead.interaction_updated' => 'עדכון אינטראקציה',
        'lead.repeat_inquiry' => 'פנייה חוזרת',
        'lead.school_updated' => 'עדכון פרטי בית ספר',
        'lead.status_changed' => 'שינוי סטטוס ליד',
        'lead.updated' => 'עדכון ליד',
        'lead_source.created' => 'מקור פנייה חדש',
        'lead_source.updated' => 'עדכון מקור פנייה',
        'lead_source.toggled' => 'הפעלה/השבתה של מקור פנייה',
        'mailing_list.email_sent' => 'מייל נשלח לרשימות תפוצה',
        'material_delivery.sent' => 'חומרי לימוד נשלחו',
        'payment.check_cleared' => 'צ\'ק נפרע',
        'payment.deleted' => 'מחיקת תשלום',
        'payment.recorded' => 'תשלום נרשם',
        'payment.updated' => 'עדכון תשלום',
        'payment_link.cancelled' => 'ביטול קישור לתשלום',
        'payment_method.created' => 'אמצעי תשלום חדש',
        'payment_method.updated' => 'עדכון אמצעי תשלום',
        'payment_method.toggled' => 'הפעלה/השבתה של אמצעי תשלום',
        'program.created' => 'תוכנית חדשה',
        'program.toggled' => 'הפעלה/השבתה של תוכנית',
        'receipt.issued' => 'הפקת קבלה',
        'status.updated' => 'עדכון סטטוס',
        'status_definition.created' => 'סטטוס חדש',
        'status_definition.toggled' => 'הפעלה/השבתה של סטטוס',
        'subscription.cancelled' => 'ביטול מנוי',
        'subscription.delivery_supplied' => 'תוכנית סומנה כסופקה',
        'subscription.delivery_unsupplied' => 'ביטול סימון אספקה',
        'subscription.demo_deliveries_seeded' => 'סימון אספקות לדוגמה',
        'subscription.ended' => 'סיום מנוי',
        'subscription.monthly_payment_overridden' => 'עדכון תשלום חודשי במנוי',
        'supplier.created' => 'ספק חדש',
        'supplier.deleted' => 'מחיקת ספק',
        'supplier.updated' => 'עדכון ספק',
        'task.cancelled' => 'ביטול משימה',
        'task.completed' => 'משימה הושלמה',
        'task.created' => 'משימה חדשה',
        'task.restored' => 'שחזור משימה',
        'task.updated' => 'עדכון משימה',
        'user.created' => 'משתמשת חדשה',
        'user.password_reset' => 'איפוס סיסמה',
        'user.updated' => 'עדכון משתמשת',
    ];

    /**
     * Hebrew label for an activity_type. External-system entries
     * ("{system}.{operation}.succeeded|failed", see ExternalOperationRunner)
     * are composed from ExternalOperation's labels; anything unknown falls
     * back to a generic Hebrew label rather than the technical key.
     */
    public static function typeLabel(string $type): string
    {
        if (isset(self::TYPE_LABELS[$type])) {
            return self::TYPE_LABELS[$type];
        }

        if (preg_match('/^([a-z_]+)\.([a-z_]+)\.(succeeded|failed)$/', $type, $m) && isset(ExternalOperation::SYSTEM_LABELS[$m[1]])) {
            $operation = ExternalOperation::OPERATION_LABELS[$m[2]] ?? 'פעולה';

            return $operation.' ב-'.ExternalOperation::SYSTEM_LABELS[$m[1]].($m[3] === 'failed' ? ' — נכשלה' : '');
        }

        return 'פעולה במערכת';
    }

    /**
     * True when a human performed the action; false means it was recorded by an
     * external system/integration (Smove, Summit, ...) on the user's behalf (FR-7.20).
     */
    public function isManual(): bool
    {
        return $this->user_id !== null;
    }
}
