<?php

use App\Models\Document;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Until now documents.status_id never left "טיוטה" and payments.status_id was
 * frozen at the moment the payment was recorded ("שולם"/"חלקי" for the deal,
 * not the payment). Both now follow their real state (Document::booted(),
 * Payment::syncStatus()); this brings existing rows into line once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Document::query()->withoutGlobalScopes()->select(['id', 'status_id', 'sent_at', 'confirmed_at', 'signed_at'])
            ->each(function (Document $document) {
                $statusId = Document::statusForDates($document)->id;

                if ($document->status_id !== $statusId) {
                    DB::table('documents')->where('id', $document->id)->update(['status_id' => $statusId]);
                }
            });

        Payment::query()->withTrashed()->with(['paymentMethod', 'receipt'])->each(fn (Payment $payment) => $payment->syncStatus());
    }

    public function down(): void
    {
        // Data-only: the earlier values carried no information worth restoring.
    }
};
