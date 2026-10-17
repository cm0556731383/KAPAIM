<?php

namespace App\Console\Commands;

use App\Models\BusinessEntity;
use App\Models\Deal;
use App\Models\ExternalOperation;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reads the payments SUMIT took in each connected company over the last few
 * days and records the ones this app doesn't have yet: a payment page the
 * customer paid but never returned from (PaymentLink), and each month a
 * standing order collects (Deal::registerStandingOrderWithSummit() — matched
 * by its recurring item IDs). SUMIT has no webhook for payments, so this
 * polling is how they arrive; it runs twice a day
 * (routes/console.php) and is safe to repeat: a SUMIT payment is
 * recorded once (payments.sumit_payment_id).
 *
 * A failure to read a company is recorded as a failed scheduled_job
 * operation — the dashboard's integration-failure list shows it.
 */
class SyncSumitPayments extends Command
{
    protected $signature = 'sumit:sync-payments {--days=3 : How many days back to read}';

    protected $description = 'Record payments taken in SUMIT (payment pages, standing orders) that are not recorded yet';

    public function handle(ExternalOperationRunner $runner, SummitClient $summit, ActivityLogger $logger): int
    {
        $standingOrders = $this->standingOrderDeals();

        foreach ($summit->syncableEntities() as $entity) {
            try {
                $payments = $summit->listPayments($entity, now()->subDays((int) $this->option('days')));
            } catch (Throwable $e) {
                $this->recordFailure($runner, "קריאת התשלומים מ-SUMIT (\"{$entity->sumitConnectionLabel()}\") נכשלה", $e);

                continue;
            }

            foreach ($payments as $payment) {
                try {
                    $this->apply((array) $payment, $entity, $standingOrders, $runner, $logger);
                } catch (Throwable $e) {
                    $this->recordFailure($runner, 'רישום תשלום SUMIT #'.($payment['ID'] ?? '?').' נכשל', $e);
                }
            }
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $standingOrders SUMIT recurring item ID => deal ID */
    private function apply(array $sumitPayment, BusinessEntity $entity, array $standingOrders, ExternalOperationRunner $runner, ActivityLogger $logger): void
    {
        if (! ($sumitPayment['ValidPayment'] ?? false) || Payment::where('sumit_payment_id', (string) ($sumitPayment['ID'] ?? ''))->exists()) {
            return;
        }

        $linkId = PaymentLink::idFromExternalIdentifier($sumitPayment['ExternalIdentifier'] ?? null);
        $link = $linkId ? PaymentLink::find($linkId) : null;

        if ($link && ! $link->isPaid() && $link->deal->business_entity_id === $entity->id) {
            $link->complete($sumitPayment, $runner, $logger);

            return;
        }

        $dealId = collect((array) ($sumitPayment['RecurringCustomerItemIDs'] ?? []))
            ->map(fn ($itemId) => $standingOrders[(string) $itemId] ?? null)
            ->filter()
            ->first();
        $deal = $dealId ? Deal::find($dealId) : null;

        if (! $deal || $deal->business_entity_id !== $entity->id) {
            return;
        }

        $payment = $deal->recordSumitPayment($sumitPayment, $runner, 'recurring');

        if ($payment?->wasRecentlyCreated) {
            $logger->log('payment.recorded', "נגבה תשלום חודשי בהוראת קבע ב-SUMIT בסך ₪{$payment->amount} עבור עסקה #{$deal->id}", [
                'deal_id' => $deal->id,
                'customer_id' => $deal->customer_id,
                'user' => null,
                'metadata' => ['amount' => (float) $payment->amount, 'payment_id' => $payment->id],
            ]);
        }
    }

    /** @return array<string, int> every registered standing order's recurring item ID => its deal ID */
    private function standingOrderDeals(): array
    {
        $map = [];

        $operations = ExternalOperation::where('system', 'summit')
            ->where('operation_type', 'standing_order_registered')
            ->where('status', ExternalOperation::STATUS_SUCCESS)
            ->whereNotNull('deal_id')
            ->get(['deal_id', 'external_reference']);

        foreach ($operations as $operation) {
            foreach (array_filter(array_map('trim', explode(',', (string) $operation->external_reference))) as $itemId) {
                $map[$itemId] = $operation->deal_id;
            }
        }

        return $map;
    }

    private function recordFailure(ExternalOperationRunner $runner, string $description, Throwable $e): void
    {
        $runner->run('summit', 'payments_sync', 'scheduled_job', fn () => throw $e, ['user' => null, 'description' => $description]);
    }
}
