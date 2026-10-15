<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * QA smoke test: with the full demo data seeded, every screen — and every
 * lead/customer/deal/document detail page — renders for the business owner
 * without a server error. Catches regressions on records the focused tests
 * never build (e.g. a deal with no program, a customer with no contacts).
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_screen_and_record_page_renders(): void
    {
        Http::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'dana@kapaim.co.il')->firstOrFail();
        $this->actingAs($owner);

        $urls = [
            '/', '/search?q=ב', '/users-roles', '/activity-log', '/settings', '/programs-catalog',
            '/leads', '/customers', '/collections', '/mailing-lists', '/suppliers', '/expenses',
            '/cashflow-report', '/document-templates',
        ];

        foreach (Lead::pluck('id') as $id) {
            $urls[] = "/leads/{$id}";
        }
        foreach (Customer::pluck('id') as $id) {
            $urls[] = "/customers/{$id}";
        }
        foreach (Deal::pluck('id') as $id) {
            $urls[] = "/deals/{$id}";
        }
        foreach (Document::pluck('id') as $id) {
            $urls[] = "/documents/{$id}";
            $urls[] = "/documents/{$id}/print";
        }

        $failures = [];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $status = $response->getStatusCode();

            if ($status >= 500 && $response->exception) {
                $failures[] = "{$url}: ".$response->exception->getMessage().' @ '.$response->exception->getFile().':'.$response->exception->getLine();
            }

            if ($status >= 500 || $status === 404) {
                $failures[] = "{$url} → {$status}";
            }
        }

        $this->assertGreaterThan(20, count($urls));
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_a_user_without_permissions_and_a_guest_are_refused_cleanly(): void
    {
        Http::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $role = Role::create(['name' => 'ללא הרשאות', 'is_active' => true]);
        $nobody = User::create(['name' => 'ללא הרשאות', 'email' => 'nobody@example.com', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        $urls = ['/users-roles', '/activity-log', '/settings', '/programs-catalog', '/leads', '/customers', '/collections',
            '/mailing-lists', '/suppliers', '/expenses', '/cashflow-report', '/document-templates',
            '/leads/'.Lead::value('id'), '/customers/'.Customer::value('id'), '/deals/'.Deal::value('id'), '/documents/'.Document::value('id')];

        $failures = [];

        foreach ($urls as $url) {
            $asNobody = $this->actingAs($nobody)->get($url)->getStatusCode();
            if ($asNobody !== 403) {
                $failures[] = "{$url} (ללא הרשאות) → {$asNobody}";
            }
        }

        auth()->logout();

        foreach ($urls as $url) {
            $response = $this->get($url);
            if (! $response->isRedirect(route('login'))) {
                $failures[] = "{$url} (אורח) → {$response->getStatusCode()}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
