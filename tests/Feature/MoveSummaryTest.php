<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MoveSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_passes_when_every_file_is_here_and_the_sumit_keys_open(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/a.png', 'x');
        DB::table('schools')->insert(['name' => 'אורות', 'logo_path' => 'logos/a.png', 'created_at' => now(), 'updated_at' => now()]);
        BusinessEntity::create(['name' => 'עוסק', 'classification' => 'עוסק פטור', 'company_number' => '123456789', 'email' => 'a@example.com', 'phone' => '0501234567', 'sumit_api_key' => 'secret', 'is_active' => true]);

        $this->artisan('app:move-summary')->expectsOutput('כל הקבצים שמסד הנתונים מפנה אליהם נמצאים כאן.')->assertSuccessful();
    }

    public function test_it_fails_on_a_missing_file_or_a_key_from_another_app_key(): void
    {
        Storage::fake('public');
        DB::table('schools')->insert(['name' => 'אורות', 'logo_path' => 'logos/gone.png', 'created_at' => now(), 'updated_at' => now()]);
        $entity = BusinessEntity::create(['name' => 'עוסק', 'classification' => 'עוסק פטור', 'company_number' => '123456789', 'email' => 'a@example.com', 'phone' => '0501234567', 'is_active' => true]);
        // Encrypted on another server — this APP_KEY can't open it.
        DB::table('business_entities')->where('id', $entity->id)->update(['sumit_api_key' => (new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'))->encrypt('secret')]);

        $this->artisan('app:move-summary')
            ->expectsOutputToContain('schools: logos/gone.png')
            ->expectsOutputToContain('מפתחות SUMIT לא נפתחים (עוסק)')
            ->assertFailed();
    }
}
