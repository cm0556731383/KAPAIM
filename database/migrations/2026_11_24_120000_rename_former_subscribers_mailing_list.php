<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The former-subscribers list was first named "מנויות לשעבר"; the owner's wording is "מנויים לשעבר". */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mailing_lists')->where('list_type', 'former_subscription')->where('name', 'מנויות לשעבר')->update(['name' => 'מנויים לשעבר']);
    }

    public function down(): void
    {
        DB::table('mailing_lists')->where('list_type', 'former_subscription')->where('name', 'מנויים לשעבר')->update(['name' => 'מנויות לשעבר']);
    }
};
