<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The navigation path above every screen (layouts/app.blade.php), e.g.
 * לקוחות › בית ספר אורות › עסקה #12 › הצעת מחיר — worked out from the
 * current route and its record, so no page builds its own. A step the
 * user has no permission to open is shown as plain text, not a link.
 */
class Breadcrumbs
{
    /** List pages: route name => [label, the permission that opens it]. */
    private const PAGES = [
        'leads' => ['לידים', 'leads.view'],
        'customers' => ['לקוחות', 'customers.view'],
        'collections' => ['גבייה ותשלומים', 'payments.view'],
        'mailing-lists' => ['רשימות תפוצה', 'mailing-lists.view'],
        'suppliers' => ['ספקים', 'suppliers.view'],
        'expenses' => ['הוצאות', 'expenses.view'],
        'cashflow-report' => ['דוח הכנסות והוצאות', 'cashflow-report.view'],
        'programs-catalog' => ['קטלוג תוכניות ומארזים', 'catalog.view'],
        'settings' => ['הגדרות מערכת', 'settings.view'],
        'users-roles' => ['משתמשות והרשאות', 'users.view'],
        'activity-log' => ['יומן פעילות', 'activity-log.view'],
        'search' => ['חיפוש גלובלי', 'search.view'],
    ];

    /**
     * The steps after the home icon, the last one being the current screen
     * (no url). Empty on the work screen itself and on unknown routes.
     *
     * @return list<array{label: string, url: ?string}>
     */
    public static function for(Request $request): array
    {
        $route = $request->route();

        $steps = match ($route?->getName()) {
            'lead-detail' => self::lead(self::record($route->parameter('lead'), Lead::class)),
            'customer-detail' => self::customer(self::record($route->parameter('customer'), Customer::class)),
            'deal-detail' => self::deal(self::record($route->parameter('deal'), Deal::class)),
            'document-view' => self::document(self::record($route->parameter('document'), Document::class)),
            null => [],
            default => isset(self::PAGES[$route->getName()]) ? [self::page($route->getName())] : [],
        };

        if ($steps) {
            $steps[array_key_last($steps)]['url'] = null;
        }

        return $steps;
    }

    /** @return array{label: string, url: ?string} */
    private static function page(string $routeName): array
    {
        [$label, $permission] = self::PAGES[$routeName];

        return ['label' => $label, 'url' => auth()->user()?->can($permission) ? route($routeName) : null];
    }

    private static function lead(?Lead $lead): array
    {
        if (! $lead) {
            return [self::page('leads')];
        }

        return [self::page('leads'), [
            'label' => $lead->school?->name ?? $lead->email ?? "ליד #{$lead->id}",
            'url' => auth()->user()?->can('leads.view') ? route('lead-detail', $lead) : null,
        ]];
    }

    private static function customer(?Customer $customer): array
    {
        if (! $customer) {
            return [self::page('customers')];
        }

        return [self::page('customers'), [
            'label' => $customer->school?->name ?? "לקוחה #{$customer->id}",
            'url' => auth()->user()?->can('customers.view') ? route('customer-detail', $customer) : null,
        ]];
    }

    private static function deal(?Deal $deal): array
    {
        if (! $deal) {
            return [self::page('customers')];
        }

        return [...self::customer($deal->customer), [
            'label' => "עסקה #{$deal->id}",
            'url' => auth()->user()?->can('deals.view') ? route('deal-detail', $deal) : null,
        ]];
    }

    private static function document(?Document $document): array
    {
        if (! $document) {
            return [];
        }

        $parents = match (true) {
            (bool) $document->deal_id => self::deal($document->deal),
            (bool) $document->lead_id => self::lead($document->lead),
            default => [self::page('expenses')],
        };

        return [...$parents, ['label' => Document::TYPE_LABELS[$document->document_type] ?? $document->document_type, 'url' => null]];
    }

    /**
     * A route parameter as its record — a Livewire page route may hand over
     * the bound model or still the raw id.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private static function record(mixed $value, string $class): ?Model
    {
        return $value instanceof $class ? $value : $class::find($value);
    }
}
