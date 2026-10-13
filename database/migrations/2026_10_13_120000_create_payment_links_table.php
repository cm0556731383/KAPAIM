<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A SUMIT payment page sent to the customer for a deal (PaymentLink).
     * payments.sumit_payment_id / sumit_document_id tie a local payment to
     * the SUMIT payment it came from (a payment page, a standing-order
     * collection) and the receipt SUMIT issued for it — the payment sync
     * (sumit:sync-payments) uses the first to never record a payment twice.
     */
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 40)->unique();
            $table->decimal('amount', 10, 2);
            $table->text('url')->nullable();
            $table->string('status')->default('pending');
            $table->string('sent_to_email')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('sumit_payment_id')->nullable()->unique()->after('cleared_date');
            $table->string('sumit_document_id')->nullable()->after('sumit_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['sumit_payment_id']);
            $table->dropColumn(['sumit_payment_id', 'sumit_document_id']);
        });

        Schema::dropIfExists('payment_links');
    }
};
