<?php

namespace App\Support;

use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Program;

/**
 * The editable list of programs/bundles in a deal or quote window
 * (2026-11-24) — new deal (customer card), the deal card, the lead's quote.
 * A line is ['item' => 'program:{id}'|'bundle:{id}', 'name' => string,
 * 'amount' => string]; the screens bind amounts as text.
 */
class DealLines
{
    /** @return array{0: ?Program, 1: ?Bundle} */
    public static function resolve(string $key): array
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);

        return [
            $type === 'program' ? Program::find($id) : null,
            $type === 'bundle' ? Bundle::find($id) : null,
        ];
    }

    public static function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * Adds the picked item with its price ($priceFor gets [program, bundle]
     * and returns the default amount) — skipped when already listed or unknown.
     */
    public static function add(array $lines, string $key, callable $priceFor): array
    {
        [$program, $bundle] = self::resolve($key);

        if ((! $program && ! $bundle) || collect($lines)->contains('item', $key)) {
            return $lines;
        }

        $lines[] = [
            'item' => $key,
            'name' => $program?->name ?? "מארז: {$bundle->name}",
            'amount' => self::formatAmount($priceFor($program, $bundle)),
        ];

        return $lines;
    }

    /** Every line's amount back to its price (after the classes tier changed). */
    public static function reprice(array $lines, callable $priceFor): array
    {
        return array_map(function (array $line) use ($priceFor) {
            [$program, $bundle] = self::resolve($line['item']);

            return ($program || $bundle) ? ['amount' => self::formatAmount($priceFor($program, $bundle))] + $line : $line;
        }, $lines);
    }

    /** The lines as Deal::createWithItems()/replaceItems() items. */
    public static function toItems(array $lines): array
    {
        return array_values(array_map(function (array $line) {
            [$program, $bundle] = self::resolve($line['item']);
            $amount = trim((string) ($line['amount'] ?? ''));

            return ['program' => $program, 'bundle' => $bundle, 'amount' => $amount === '' ? null : (float) $amount];
        }, $lines));
    }

    public static function total(array $lines): float
    {
        return (float) collect($lines)->sum(fn ($line) => is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : 0);
    }

    /** Validation rules for a lines property named $prop. */
    public static function rules(string $prop): array
    {
        return [
            $prop => ['required', 'array', 'min:1'],
            "{$prop}.*.item" => ['required', 'string', 'regex:/^(program|bundle):\d+$/'],
            "{$prop}.*.amount" => ['nullable', 'numeric', 'gt:0', Money::maxRule()],
        ];
    }

    public static function attributes(string $prop): array
    {
        return [$prop => 'תוכניות ומארזים', "{$prop}.*.amount" => 'סכום', "{$prop}.*.item" => 'תוכנית / מארז'];
    }

    /** The deal's current lines, for editing on the deal card. */
    public static function fromDeal(Deal $deal): array
    {
        return $deal->items->map(fn ($item) => [
            'item' => $item->itemKey(),
            'name' => $item->program_id ? $item->name_snapshot : "מארז: {$item->name_snapshot}",
            'amount' => self::formatAmount((float) $item->amount),
        ])->values()->all();
    }

    /** "subscriber discount applied" note for the premium programs among the lines, or null. */
    public static function discountNote(Customer $customer, array $lines): ?string
    {
        $names = collect($lines)->map(fn ($line) => self::resolve($line['item'])[0])->filter()
            ->filter(fn (Program $program) => Deal::hasSubscriberDiscount($customer, $program))
            ->pluck('name');

        return $names->isEmpty() ? null
            : 'הלקוחה מנויה, ולכן חלה הנחת מנוי של 10% על תוכנית הפרימיום ('.$names->implode(', ').').';
    }
}
