<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Program;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Build-plan 11 seed data (US-016/US-017): one demo supplier (joined to the
 * "ספקים" mailing list, exactly like a real ⚡suppliers.blade.php submission)
 * and two demo expenses — one with an invoice attached, one without — so
 * ProcessExpenseReminders/Expense::missingInvoiceForClosedMonth() has a
 * real, non-trivial row to demonstrate against out of the box.
 */
class SuppliersExpensesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = Supplier::create([
            'name' => 'דפוס הראל בע"מ',
            'company_number' => '514392201',
            'classification' => 'חברה בעמ',
            'phone' => '03-6541200',
            'email' => 'orders@harel-print.co.il',
            'notes' => 'הדפסת חוברות תוכניות',
        ]);
        $supplier->joinSuppliersMailingList();

        $program = Program::query()->orderBy('id')->first();

        // Already invoiced — attachInvoice() flips its status to "תועדה".
        $invoicedExpense = Expense::createForSupplier(
            $supplier,
            3200,
            now()->subMonth()->startOfMonth()->addDays(4)->toDateString(),
            $program,
            'הדפסת 400 חוברות',
        );
        $invoicedExpense->attachInvoice();

        // Still missing an invoice, from last (closed) month — a real,
        // non-zero row for ProcessExpenseReminders/the "missing invoice"
        // detector to flag.
        Expense::createForSupplier(
            $supplier,
            1450,
            now()->subMonth()->startOfMonth()->addDays(9)->toDateString(),
            null,
            null,
        );
    }
}
