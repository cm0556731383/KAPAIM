<?php

namespace Database\Seeders;

use App\Models\Deal;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Seeder;

/**
 * Build-plan 08 demo data: records a real partial payment (plus its receipt)
 * against DealsDemoSeeder's demo deal (בית ספר יובלים), once
 * DocumentsDemoSeeder has walked it all the way to an invoice — the same
 * "example from the storyboard" spirit as the other demo seeders, echoing
 * docs/storyboard/payment-record.html (בית ספר יובלים, בנקאית, תשלום חלקי).
 */
class PaymentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $activityLogger = app(ActivityLogger::class);

        $deal = Deal::whereHas('customer.school', fn ($q) => $q->where('name', 'בית ספר יובלים'))->first();
        $bankTransfer = PaymentMethod::where('type', 'bank_transfer')->first();

        if (! $deal || ! $bankTransfer) {
            return;
        }

        $deal->updatePaymentMethod($bankTransfer->id);

        $amount = min(2500.0, (float) $deal->agreed_amount);
        $payment = $deal->recordPayment($deal->version, $amount);

        $activityLogger->log('payment.recorded', "נרשם תשלום בסך ₪{$amount} עבור עסקה #{$deal->id}", [
            'deal_id' => $deal->id, 'customer_id' => $deal->customer_id,
            'metadata' => ['amount' => $amount, 'payment_id' => $payment->id],
        ]);

        if ($deal->documents()->where('document_type', 'invoice')->exists()) {
            $receipt = Receipt::issueFor($deal, $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

            $activityLogger->log('receipt.issued', "הופקה קבלה עבור תשלום בעסקה #{$deal->id}", [
                'deal_id' => $deal->id, 'customer_id' => $deal->customer_id,
                'metadata' => ['receipt_id' => $receipt->id, 'payment_id' => $payment->id],
            ]);
        }
    }
}
