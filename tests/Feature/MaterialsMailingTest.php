<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\EmailTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\Lead;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\MaterialDelivery;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use RuntimeException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaterialsMailingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);
    }

    // ----- auth / permissions -----

    public function test_mailing_lists_page_requires_auth(): void
    {
        $this->get('/mailing-lists')->assertRedirect('/login');
    }

    public function test_mailing_lists_page_is_blocked_without_the_mailing_lists_permission(): void
    {
        $limited = $this->userWithOnlyCustomersPermission();

        $this->actingAs($limited)->get('/mailing-lists')->assertStatus(403);
    }

    public function test_mailing_lists_page_renders_for_a_user_with_the_permission(): void
    {
        $this->actingAs($this->owner)->get('/mailing-lists')->assertOk();
    }

    public function test_sending_materials_is_blocked_without_the_materials_permission(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $limited = $this->userWithOnlyCustomersPermission();

        Livewire::actingAs($limited)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('doc.pdf', 10)])
            ->call('sendMaterials')
            ->assertStatus(403);

        $this->assertSame(0, MaterialDelivery::count());
    }

    // ----- sending materials (FR-5.1-FR-5.9/FR-8.13/FR-8.14) -----

    public function test_sending_materials_is_blocked_with_no_recipient(): void
    {
        $customer = $this->createCustomer(); // no contacts at all
        $program = $this->createProgram();

        $this->expectException(RuntimeException::class);
        MaterialDelivery::sendFor($customer, $program, [], [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));
    }

    public function test_sending_materials_is_blocked_with_no_attachment(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $this->expectException(RuntimeException::class);
        MaterialDelivery::sendFor($customer, $program, [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']], [], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));
    }

    public function test_sending_materials_succeeds_with_a_recipient_and_an_attachment(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $delivery = MaterialDelivery::sendFor(
            $customer,
            $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $this->assertSame(1, MaterialDelivery::count());
        $this->assertSame($customer->id, $delivery->customer_id);
        $this->assertSame($program->id, $delivery->program_id);
        $this->assertSame(MaterialDelivery::STATUS_NOT_OPENED, $delivery->status?->name);
        $this->assertSame(1, $delivery->recipients()->count());
        $this->assertSame(1, $delivery->attachments()->count());
        $this->assertSame('a.pdf', $delivery->attachments()->first()->file_name);
        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.sent')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_sending_materials_defaults_recipients_to_primary_contacts_without_mutating_is_primary(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $contact = Contact::where('customer_id', $customer->id)->first();

        $defaults = MaterialDelivery::defaultRecipients($customer);
        $this->assertCount(1, $defaults);
        $this->assertSame($contact->id, $defaults->first()->id);

        MaterialDelivery::sendFor(
            $customer,
            $program,
            $defaults->map(fn (Contact $c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])->all(),
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $this->assertTrue($contact->fresh()->is_primary);
    }

    public function test_resending_materials_creates_a_new_delivery_row_not_an_update(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        $recipients = [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']];

        MaterialDelivery::sendFor($customer, $program, $recipients, [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));
        MaterialDelivery::sendFor($customer, $program, $recipients, [['file_reference' => 'tmp2', 'file_name' => 'b.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));

        $this->assertSame(2, MaterialDelivery::where('customer_id', $customer->id)->where('program_id', $program->id)->count());
    }

    public function test_sending_materials_via_the_customer_card_creates_a_real_delivery_and_discards_the_temp_file(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $this->signContract(Deal::createForCustomer($customer, $program, null));
        $file = UploadedFile::fake()->create('doc.pdf', 10);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [$file])
            ->call('sendMaterials')
            ->assertSet('materialsError', null);

        $this->assertSame(1, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    /**
     * An attachment over the 95MB cap (just under Cloudflare's 100MB request
     * limit — raised from 25MB on 2026-10-13) is rejected by real Livewire
     * validation before anything is sent, and no delivery is created; one
     * just under it is accepted.
     */
    public function test_an_oversized_attachment_is_rejected(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsFiles', [UploadedFile::fake()->create('video.mp4', 90000)])
            ->assertHasNoErrors(['materialsFiles.*']);

        $file = UploadedFile::fake()->create('huge.pdf', 97281);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [$file])
            ->assertHasErrors(['materialsFiles.*']);

        $this->assertSame(0, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    /**
     * Stage 19 hardening: a disallowed file type (outside the pdf/office/
     * image/media whitelist) is rejected by real Livewire validation.
     */
    public function test_an_attachment_with_a_disallowed_mime_type_is_rejected(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $file = UploadedFile::fake()->create('script.exe', 10);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [$file])
            ->assertHasErrors(['materialsFiles.*']);

        $this->assertSame(0, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    public function test_the_materials_program_list_holds_only_programs_the_customer_bought(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $bought = $this->createProgram('תוכנית שנרכשה');
        $inBundle = $this->createProgram('תוכנית בתוך מארז');
        $cancelled = $this->createProgram('תוכנית מעסקה מבוטלת');
        $notBought = $this->createProgram('תוכנית שלא נרכשה');
        $bundle = Bundle::create(['name' => 'מארז בדיקה', 'description' => null, 'price' => 900, 'is_subscription_type' => false, 'is_active' => true]);
        $bundle->programs()->attach($inBundle->id);

        $this->signContract(Deal::createForCustomer($customer, $bought, null));
        $this->signContract(Deal::createForCustomer($customer, null, $bundle));
        $cancelledDeal = Deal::createForCustomer($customer, $cancelled, null);
        $cancelledStatus = StatusDefinition::firstOrCreate(['scope' => 'deal', 'name' => 'מבוטלת'], ['is_active' => true, 'sort_order' => 4]);
        $cancelledDeal->updateStatusWithLock($cancelledDeal->version, $cancelledStatus->id);

        $ids = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->instance()->materialsPrograms->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$bought->id, $inBundle->id], $ids);
        $this->assertNotContains($notBought->id, $ids);
    }

    public function test_a_cancelled_subscription_keeps_every_program_of_its_months_before_the_cancellation(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $subscriptionBundle = $this->createSubscriptionBundle();
        $subscription = $this->signContract(Deal::createForCustomer($customer, null, $subscriptionBundle))->subscription;

        // Named after their months the way the business owner types them — a plain " not ״.
        [$first, $second, $third] = collect([1, 2, 3])
            ->map(fn ($month) => $this->createProgram(str_replace('״', '"', $subscription->monthLabel($month))))
            ->all();
        $subscriptionBundle->programs()->attach([$first->id, $second->id, $third->id]);

        $firstMonth = $subscription->deliveries()->where('sequence_number', 1)->first();
        $subscription->markDeliverySupplied($subscription->version, $firstMonth->id, $first->id, $this->owner);
        $subscription->refresh()->cancel(3);

        $ids = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->instance()->materialsPrograms->pluck('id')->all();

        // Month 2 was never supplied but is before the cancellation; month 3 is cancelled.
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
    }

    public function test_materials_cannot_be_sent_for_a_program_the_customer_did_not_buy(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('doc.pdf', 10)])
            ->call('sendMaterials')
            ->assertSet('materialsError', 'ניתן לשלוח חומרים רק עבור תוכנית שהלקוחה רכשה.');

        $this->assertSame(0, MaterialDelivery::count());
    }

    public function test_materials_can_be_sent_only_once_the_deal_contract_is_signed(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram('תוכנית ללא חוזה חתום');
        $deal = Deal::createForCustomer($customer, $program, null);
        $status = StatusDefinition::firstOrCreate(['scope' => 'document', 'name' => Document::DEFAULT_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        // A contract that was only sent, not signed, doesn't count.
        Document::create(['deal_id' => $deal->id, 'document_type' => 'contract', 'status_id' => $status->id, 'sent_at' => now()]);

        $component = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('activeTab', 'materials')
            ->assertSee('ממתינות לחתימה על חוזה העסקה: תוכנית ללא חוזה חתום')
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('doc.pdf', 10)])
            ->call('sendMaterials')
            ->assertSet('materialsError', 'ניתן לשלוח חומרים רק אחרי חתימה על חוזה העסקה.');
        $this->assertSame(0, MaterialDelivery::count());
        $this->assertNotContains($program->id, $component->instance()->materialsPrograms->pluck('id')->all());

        $deal->documents()->where('document_type', 'contract')->first()->markSigned();

        $ids = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('activeTab', 'materials')
            ->assertDontSee('ממתינות לחתימה על חוזה העסקה')
            ->instance()->materialsPrograms->pluck('id')->all();
        $this->assertSame([$program->id], $ids);
    }

    public function test_materials_can_be_sent_as_a_link_with_or_without_a_file(): void
    {
        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x']]);
        // Smove's contact lookup answers a list; everything else an id.
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? [] : ['id' => 42], 200));
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $this->signContract(Deal::createForCustomer($customer, $program, null));

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->call('addMaterialsLink')
            ->set('materialsLinks.0', ['name' => 'סרטון הדרכה', 'url' => 'https://youtu.be/abc123'])
            ->call('addMaterialsLink')
            ->set('materialsLinks.1', ['name' => '', 'url' => 'https://drive.google.com/file/d/xyz/view'])
            ->call('sendMaterials')
            ->assertSet('materialsError', null)
            ->assertSet('materialsLinks', []);

        $delivery = MaterialDelivery::where('customer_id', $customer->id)->sole();
        $links = $delivery->attachments()->orderBy('id')->get();
        $this->assertSame(['סרטון הדרכה', 'drive.google.com'], $links->pluck('file_name')->all());
        $this->assertTrue($links->every->isLink());
        $this->assertSame('https://youtu.be/abc123', $links->first()->downloadUrl());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns')
            && str_contains($request['body'], 'https://youtu.be/abc123') && str_contains($request['body'], 'סרטון הדרכה'));

        // An old email's signed link to a link attachment forwards to it.
        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute('materials.attachment.download', now()->addDay(), ['attachment' => $links->first()->id]))
            ->assertRedirect('https://youtu.be/abc123');
    }

    public function test_the_materials_wording_can_be_edited_for_one_send(): void
    {
        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? [] : ['id' => 42], 200));
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $this->signContract(Deal::createForCustomer($customer, $program, null));
        $template = MaterialDelivery::emailTemplate();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            // Starts from the template in settings.
            ->assertSet('materialsSubject', $template->subject)
            ->assertSet('materialsBody', $template->content)
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('a.pdf', 10)])
            ->set('materialsSubject', 'מיוחד: {{תוכנית}}')
            ->set('materialsBody', "היי צוות\n{{רשימת_החומרים}}\nתודה")
            ->call('sendMaterials')
            ->assertSet('materialsError', null)
            ->assertSet('materialsBody', $template->content);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns')
            && $request['subject'] === 'מיוחד: '.$program->name
            && str_contains($request['body'], 'היי צוות')
            && str_contains($request['body'], 'a.pdf')
            && str_contains($request['body'], 'קיבלתי את החומרים'));
    }

    public function test_the_materials_email_window_checks_the_wording_and_can_restore_the_template(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $template = MaterialDelivery::emailTemplate();

        $component = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('activeTab', 'materials')
            ->assertSee('לפי תבנית "שליחת חומרים" בהגדרות', false)
            // Insert-field buttons, as in settings → תבניות דואר.
            ->assertSee(['+ שם בית הספר', '+ תוכנית', '+ רשימת החומרים', '+ כפתור "קיבלתי את החומרים"'])
            ->set('materialsBody', '')
            ->call('saveMaterialsWording')
            ->assertHasErrors(['materialsBody'])
            ->assertDispatched('open-modal', name: 'materials-email')
            ->set('materialsBody', 'נוסח אחר')
            ->call('saveMaterialsWording')
            ->assertHasNoErrors()
            ->assertDispatched('close-modals')
            ->assertSee('נוסח שנערך לשליחה זו');

        $this->assertTrue($component->instance()->materialsWordingEdited());
        $component->call('restoreMaterialsTemplate')->assertSet('materialsBody', $template->content);
        $this->assertFalse($component->instance()->materialsWordingEdited());
    }

    public function test_sending_materials_with_an_incomplete_wording_reopens_the_email_window(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $this->signContract(Deal::createForCustomer($customer, $program, null));

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('a.pdf', 10)])
            ->set('materialsMode', 'html')
            ->call('sendMaterials')
            ->assertHasErrors(['materialsHtmlFile'])
            ->assertDispatched('open-modal', name: 'materials-email');

        $this->assertSame(0, MaterialDelivery::count());
    }

    public function test_materials_can_be_sent_in_an_uploaded_html_file(): void
    {
        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? [] : ['id' => 42], 200));
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $this->signContract(Deal::createForCustomer($customer, $program, null));
        $html = '<html><body><h1>חומרים ל{{תוכנית}}</h1><div>{{רשימת_החומרים}}</div></body></html>';

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->call('addMaterialsLink')
            ->set('materialsLinks.0', ['name' => 'סרטון', 'url' => 'https://youtu.be/abc123'])
            ->set('materialsMode', 'html')
            ->call('sendMaterials')
            ->assertHasErrors(['materialsHtmlFile'])
            ->set('materialsHtmlFile', UploadedFile::fake()->createWithContent('mail.html', $html))
            ->call('sendMaterials')
            ->assertSet('materialsError', null);

        Http::assertSent(function ($request) use ($program) {
            if (! str_contains($request->url(), '/Campaigns')) {
                return false;
            }
            $body = $request['body'];

            // Used as is (no branded shell), tokens filled; the button, unplaced, goes before </body>.
            return str_starts_with($body, '<html><body><h1>חומרים ל'.$program->name.'</h1><div><ul')
                && str_contains($body, 'https://youtu.be/abc123')
                && preg_match('/קיבלתי את החומרים.*<\/body><\/html>$/su', $body) === 1;
        });
    }

    public function test_materials_can_be_attached_to_a_mailing_list_email(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $customer = $this->createCustomerWithEmailContact();
        MailingMembership::addCustomer(MailingList::primaryList(), $customer);

        $component = Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('sendListIds', [(string) MailingList::primaryList()->id])
            ->set('sendSubject', 'עדכון')
            ->set('sendBody', 'שלום לכולם')
            ->set('sendFiles', [UploadedFile::fake()->create('guide.pdf', 10)])
            ->call('addSendLink')
            ->set('sendLinks.0', ['name' => '', 'url' => 'https://youtu.be/abc123'])
            ->call('preview');

        // The preview stored the attachments once; the send reuses them.
        $this->assertSame(2, \App\Models\MailingListSendAttachment::count());
        $component->call('send')->assertSet('sendError', null)->assertSet('sendFiles', [])->assertSet('sendLinks', []);
        $this->assertSame(2, \App\Models\MailingListSendAttachment::count());

        $send = \App\Models\MailingListSend::firstOrFail();
        $attachments = $send->attachments()->orderBy('id')->get();
        $this->assertSame(['guide.pdf', 'youtu.be'], $attachments->pluck('file_name')->all());
        $this->assertStringContainsString('חומרים מצורפים:', $send->body_html);
        $this->assertStringContainsString(e($attachments->first()->downloadUrl()), $send->body_html);
        $this->assertStringContainsString('https://youtu.be/abc123', $send->body_html);

        $this->get($attachments->first()->downloadUrl())->assertOk()->assertDownload('guide.pdf');
        $this->get(route('mailing-lists.attachment.download', $attachments->first()))->assertForbidden();
    }

    public function test_changing_the_attached_files_drops_the_stale_stored_copies(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $component = Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('sendSubject', 'עדכון')
            ->set('sendBody', 'שלום')
            ->set('sendFiles', [UploadedFile::fake()->create('old.pdf', 10), UploadedFile::fake()->create('new.pdf', 10)])
            ->call('preview')
            ->call('removeSendFile', 0)
            ->call('preview');

        $this->assertSame(['new.pdf'], \App\Models\MailingListSendAttachment::pluck('file_name')->all());
        $this->assertCount(1, \Illuminate\Support\Facades\Storage::disk('local')->allFiles('mailing-list-attachments'));
        $this->assertStringContainsString('new.pdf', $component->get('emailPreview')['html']);
    }

    public function test_an_uploaded_html_list_email_gets_the_materials_where_it_places_them(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $html = '<html><body><p>פתיח</p>{{רשימת_החומרים}}<p>סיום</p></body></html>';

        $preview = Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('sendSubject', 'ניוזלטר')
            ->set('sendMode', 'html')
            ->set('htmlFile', UploadedFile::fake()->createWithContent('news.html', $html))
            ->call('addSendLink')
            ->set('sendLinks.0', ['name' => 'סרטון', 'url' => 'https://youtu.be/abc123'])
            ->call('preview')
            ->get('emailPreview')['html'];

        $this->assertMatchesRegularExpression('/פתיח<\/p><ul.*סרטון.*<\/ul><p>סיום/su', $preview);
        $this->assertStringNotContainsString('חומרים מצורפים:', $preview);
    }

    public function test_the_materials_preview_shows_the_exact_email_and_a_test_copy_goes_only_to_me(): void
    {
        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? [] : ['id' => 42], 200));
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $this->signContract(Deal::createForCustomer($customer, $program, null));

        $component = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('guide.pdf', 10)])
            ->call('previewMaterialsEmail')
            ->assertDispatched('open-modal', name: 'email-preview');

        $preview = $component->get('emailPreview');
        $expected = MaterialDelivery::emailMessage($customer, $program->name, [['name' => 'guide.pdf', 'url' => '#']], '#');
        $this->assertSame($expected->subject, $preview['subject']);
        $this->assertSame($expected->html, $preview['html']);

        $component->call('sendEmailPreviewToMe');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns') && $request['subject'] === '[בדיקה] '.$expected->subject && $request['body'] === $expected->html);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Contacts') && $request->method() === 'POST' && $request['email'] === $this->owner->email);
        $this->assertSame(0, MaterialDelivery::count());
    }

    public function test_the_previewed_email_cannot_be_swapped_from_the_browser(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('emailPreview', ['subject' => 'x', 'html' => '<p>x</p>', 'attachments' => [], 'to' => null, 'note' => null]);
    }

    public function test_the_mailing_list_preview_opens_the_preview_window(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        MailingMembership::addCustomer(MailingList::primaryList(), $customer);

        $preview = Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('sendListIds', [(string) MailingList::primaryList()->id])
            ->set('sendSubject', 'עדכון חודשי')
            ->set('sendBody', 'שלום לכולם')
            ->call('preview')
            ->assertDispatched('open-modal', name: 'email-preview')
            ->get('emailPreview');

        $this->assertSame('עדכון חודשי', $preview['subject']);
        $this->assertStringContainsString('שלום לכולם', $preview['html']);
        $this->assertStringContainsString('(1 כתובות)', $preview['to']);
    }

    public function test_a_materials_link_must_be_a_web_address(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->call('addMaterialsLink')
            ->set('materialsLinks.0', ['name' => 'x', 'url' => 'javascript:alert(1)'])
            ->call('sendMaterials')
            ->assertHasErrors(['materialsLinks.0.url']);

        $this->assertSame(0, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    public function test_a_deal_id_is_attached_to_the_activity_log_when_a_matching_deal_exists(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        $deal = Deal::createForCustomer($customer, $program, null);

        $delivery = MaterialDelivery::sendFor(
            $customer, $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $log = ActivityLog::where('activity_type', 'material_delivery.sent')->where('material_delivery_id', $delivery->id)->first();
        $this->assertSame($deal->id, $log->deal_id);
    }

    public function test_sending_materials_never_blocks_when_no_matching_deal_exists(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $delivery = MaterialDelivery::sendFor(
            $customer, $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $log = ActivityLog::where('activity_type', 'material_delivery.sent')->where('material_delivery_id', $delivery->id)->first();
        $this->assertNull($log->deal_id);
    }

    // ----- FR-5.12/FR-5.13/FR-5.15: the "קיבלתי, תודה" signed link -----

    public function test_the_signed_acknowledge_link_sets_acknowledged_at_and_logs_activity(): void
    {
        $delivery = $this->createDelivery();
        $url = URL::signedRoute('materials.acknowledge', ['material' => $delivery->id]);

        $response = $this->get($url);

        $response->assertOk();
        $delivery->refresh();
        $this->assertNotNull($delivery->acknowledged_at);
        $this->assertSame(MaterialDelivery::STATUS_ACKNOWLEDGED, $delivery->status?->name);
        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.acknowledged')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_clicking_the_acknowledge_link_twice_is_idempotent(): void
    {
        $delivery = $this->createDelivery();
        $url = URL::signedRoute('materials.acknowledge', ['material' => $delivery->id]);

        $this->get($url);
        $firstAcknowledgedAt = $delivery->fresh()->acknowledged_at;

        $this->get($url)->assertOk();

        $this->assertSame(1, ActivityLog::where('activity_type', 'material_delivery.acknowledged')->where('material_delivery_id', $delivery->id)->count());
        $this->assertTrue($firstAcknowledgedAt->equalTo($delivery->fresh()->acknowledged_at));
    }

    public function test_an_unsigned_acknowledge_url_is_rejected(): void
    {
        $delivery = $this->createDelivery();

        $this->get("/materials/{$delivery->id}/acknowledge")->assertStatus(403);

        $this->assertNull($delivery->fresh()->acknowledged_at);
    }

    public function test_a_tampered_acknowledge_signature_is_rejected(): void
    {
        $delivery = $this->createDelivery();
        $url = URL::signedRoute('materials.acknowledge', ['material' => $delivery->id]);

        $this->get($url.'x')->assertStatus(403);

        $this->assertNull($delivery->fresh()->acknowledged_at);
    }

    // ----- reminder job (FR-5.16/FR-5.17) -----

    public function test_reminder_job_ignores_deliveries_not_yet_overdue(): void
    {
        Mail::fake();
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(10)]);

        $this->artisan('materials:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_reminder_job_flags_overdue_unopened_deliveries(): void
    {
        Mail::fake();
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(49)]);

        $this->artisan('materials:process-reminders');

        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_reminder_job_ignores_already_opened_deliveries(): void
    {
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(49)]);
        $delivery->markOpened();

        $this->artisan('materials:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_reminder_job_ignores_already_acknowledged_deliveries(): void
    {
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(49)]);
        $delivery->acknowledge(app(ActivityLogger::class));

        $this->artisan('materials:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_the_materials_email_has_a_received_button_that_confirms_the_delivery(): void
    {
        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x', 'list_id' => 111]]);
        Http::fake(fn ($request) => $request->method() === 'GET' ? Http::response([], 200) : Http::response(['id' => 5], 200));

        EmailTemplate::materialDelivery()->update(['subject' => 'חומרים: {{תוכנית}}', 'content' => "שלום {{שם_בית_הספר}}\n{{רשימת_החומרים}}"]);

        $delivery = $this->createDelivery();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns')
            && $request['subject'] === 'חומרים: '.$delivery->program->name
            && str_contains($request['body'], 'a.pdf')
            // Not placed by the text — appended at the end.
            && str_contains($request['body'], 'קיבלתי את החומרים')
            && str_contains($request['body'], e($delivery->acknowledgeUrl())));

        $this->get($delivery->acknowledgeUrl())->assertOk();
        $this->assertNotNull($delivery->fresh()->acknowledged_at);
    }

    public function test_an_overdue_delivery_gets_one_reminder_to_every_recipient_in_the_template_wording(): void
    {
        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x', 'list_id' => 111, 'material_reminder_hours' => 48]]);
        Http::fake(fn ($request) => $request->method() === 'GET' ? Http::response([], 200) : Http::response(['id' => 5], 200));
        EmailTemplate::materialReminder()->update(['subject' => 'עוד לא אישרתם את {{תוכנית}}', 'content' => "שלום {{שם_בית_הספר}}\n{{כפתור_אישור_קבלה}}"]);

        $delivery = $this->createDelivery();
        $delivery->recipients()->create(['contact_id' => null, 'recipient_name' => 'ב', 'recipient_email' => 'b@example.com']);
        $delivery->update(['sent_at' => now()->subHours(49)]);

        $this->artisan('materials:process-reminders');
        $this->artisan('materials:process-reminders');

        $reminders = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/Campaigns') && str_starts_with((string) $pair[0]['subject'], 'עוד לא אישרתם'));
        $this->assertCount(1, $reminders);
        $this->assertCount(2, $reminders->first()[0]['toMembersById']);
        $this->assertStringContainsString('קיבלתי את החומרים', $reminders->first()[0]['body']);
        $this->assertNotNull($delivery->fresh()->reminder_sent_at);
    }

    public function test_a_handled_delivery_or_a_disabled_reminder_template_sends_no_reminder(): void
    {
        $handled = $this->createDelivery();
        $handled->update(['sent_at' => now()->subHours(49)]);
        $handled->markHandled();

        $this->artisan('materials:process-reminders');
        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $handled->id)->exists());

        EmailTemplate::materialReminder()->update(['is_active' => false]);
        $overdue = $this->createDelivery();
        $overdue->update(['sent_at' => now()->subHours(49)]);

        $this->artisan('materials:process-reminders');
        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $overdue->id)->exists());
        $this->assertNull($overdue->fresh()->reminder_sent_at);
    }

    public function test_reminder_window_is_configurable_via_the_smove_integration_setting(): void
    {
        ExternalIntegrationSetting::updateOrCreate(
            ['system' => 'smove'],
            ['is_active' => false, 'settings' => ['material_reminder_hours' => 2]],
        );

        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(3)]);

        $this->artisan('materials:process-reminders');

        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_needing_attention_scope_excludes_handled_deliveries(): void
    {
        $delivery = $this->createDelivery();

        $this->assertSame(1, MaterialDelivery::needingAttention()->count());

        $delivery->markHandled();

        $this->assertSame(0, MaterialDelivery::needingAttention()->count());
    }

    // ----- mailing lists (US-013/FR-5.19-FR-5.26) -----

    public function test_a_new_lead_joins_the_primary_mailing_list(): void
    {
        $lead = $this->createLead();

        $lead->joinPrimaryMailingList();

        $primary = MailingList::primaryList();
        $this->assertTrue(MailingMembership::where('mailing_list_id', $primary->id)->where('lead_id', $lead->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());
    }

    public function test_converting_a_lead_backfills_its_primary_membership_onto_the_customer(): void
    {
        $lead = $this->createLead();
        $lead->joinPrimaryMailingList();

        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $primary = MailingList::primaryList();
        $membership = MailingMembership::where('mailing_list_id', $primary->id)->where('lead_id', $lead->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame($customer->id, $membership->customer_id);
        // No duplicate row was created for the same person.
        $this->assertSame(1, MailingMembership::where('mailing_list_id', $primary->id)->where('customer_id', $customer->id)->count());
    }

    public function test_a_converted_leads_list_membership_reaches_the_customers_contacts(): void
    {
        $lead = $this->createLead();
        $lead->joinPrimaryMailingList();
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));
        $customer->contacts()->first()->update(['email' => 'principal@example.com']);
        \App\Models\Contact::create(['school_id' => $customer->school_id, 'customer_id' => $customer->id, 'name' => 'מזכירה', 'email' => 'office@example.com']);

        $emails = collect(MailingList::primaryList()->recipients())->pluck('email')->all();

        $this->assertEqualsCanonicalizing(['principal@example.com', 'office@example.com'], $emails);
    }

    public function test_converting_a_lead_that_never_joined_still_puts_the_customer_on_the_primary_list(): void
    {
        $lead = $this->createLead(); // never called joinPrimaryMailingList()

        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $primary = MailingList::primaryList();
        $this->assertTrue(MailingMembership::where('mailing_list_id', $primary->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());
    }

    public function test_buying_a_plain_program_adds_the_customer_to_that_programs_list(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        Deal::createForCustomer($customer, $program, null);

        $list = MailingList::forProgram($program);
        $this->assertTrue(MailingMembership::where('mailing_list_id', $list->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());
    }

    public function test_buying_a_plain_program_does_not_add_the_customer_to_the_subscribers_list(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        Deal::createForCustomer($customer, $program, null);

        $subscribers = MailingList::subscribersList();
        $this->assertFalse(MailingMembership::where('mailing_list_id', $subscribers->id)->where('customer_id', $customer->id)->exists());
    }

    public function test_buying_the_subscription_bundle_adds_subscribers_list_and_the_list_of_every_program_in_the_bundle(): void
    {
        $customer = $this->createCustomer();
        $monthlyA = $this->createProgram(name: 'חודשית א');
        $monthlyB = $this->createProgram(name: 'חודשית ב');
        $notInBundle = $this->createProgram(name: 'מחוץ למנוי');
        $subscriptionBundle = $this->createSubscriptionBundle();
        $subscriptionBundle->programs()->attach([$monthlyA->id, $monthlyB->id]);

        Deal::createForCustomer($customer, null, $subscriptionBundle);

        $subscribers = MailingList::subscribersList();
        $this->assertTrue(MailingMembership::where('mailing_list_id', $subscribers->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());

        foreach ([$monthlyA, $monthlyB] as $monthly) {
            $list = MailingList::forProgram($monthly);
            $this->assertTrue(
                MailingMembership::where('mailing_list_id', $list->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists(),
                "Expected customer to be on the mailing list for {$monthly->name}",
            );
        }

        // Only the programs inside the subscription bundle in the catalog.
        $otherList = MailingList::forProgram($notInBundle);
        $this->assertFalse(MailingMembership::where('mailing_list_id', $otherList->id)->where('customer_id', $customer->id)->exists());
    }

    public function test_a_program_added_to_or_removed_from_the_subscription_bundle_follows_its_active_subscribers(): void
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = $this->createSubscriptionBundle();
        Deal::createForCustomer($customer, null, $subscriptionBundle);
        $added = $this->createProgram(name: 'נוספה למנוי');

        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('membershipBundleId', $subscriptionBundle->id)
            ->set('membershipProgramId', $added->id)
            ->call('addBundleProgram');

        $this->assertTrue($this->isActiveMember(MailingList::forProgram($added), $customer));

        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->call('removeBundleProgram', $subscriptionBundle->id, $added->id);

        $this->assertFalse($this->isActiveMember(MailingList::forProgram($added), $customer));
    }

    public function test_cancelling_a_subscription_removes_subscribers_and_undelivered_lists_but_keeps_primary_and_delivered(): void
    {
        $customer = $this->createCustomer();
        $monthlyA = $this->createProgram(name: 'חודשית א');
        $monthlyB = $this->createProgram(name: 'חודשית ב');
        $subscriptionBundle = $this->createSubscriptionBundle();
        $subscriptionBundle->programs()->attach([$monthlyA->id, $monthlyB->id]);

        $deal = Deal::createForCustomer($customer, null, $subscriptionBundle);
        $subscription = Subscription::where('deal_id', $deal->id)->firstOrFail();

        // Mark two deliveries supplied against monthlyA before cancelling —
        // proves the "already delivered" carve-out (FR-5.25).
        foreach ($subscription->deliveries()->limit(2)->get() as $delivery) {
            $subscription->markDeliverySupplied($subscription->fresh()->version, $delivery->id, $monthlyA->id, $this->owner);
        }

        $primaryListBefore = MailingList::primaryList();
        MailingMembership::addCustomer($primaryListBefore, $customer); // ensure customer is really on it

        $subscription->fresh()->cancel();

        $subscribers = MailingList::subscribersList();
        $this->assertFalse($this->isActiveMember($subscribers, $customer));

        // monthlyA was delivered — membership stays.
        $this->assertTrue($this->isActiveMember(MailingList::forProgram($monthlyA), $customer));

        // monthlyB was never delivered — membership is removed.
        $this->assertFalse($this->isActiveMember(MailingList::forProgram($monthlyB), $customer));

        // The primary list is never touched by cancellation.
        $this->assertTrue($this->isActiveMember(MailingList::primaryList(), $customer));
    }

    // ----- the lists screen: one row per list, sending an email to a list -----

    public function test_the_lists_screen_shows_the_general_subscribers_and_every_active_program_list(): void
    {
        $active = $this->createProgram(name: 'תוכנית פעילה בקטלוג');
        $this->createCustomerWithEmailContact();

        Livewire::actingAs($this->owner)->test('mailing-lists')
            ->assertSee('כללית — כל הלידים והלקוחות')
            ->assertSee('מנויים')
            ->assertSee('ספקים')
            ->assertSee($active->name)
            ->assertDontSee('מזהה רשימה ב-Smove');
    }

    public function test_sending_to_a_list_queues_one_send_for_its_recipients(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $customer = $this->createCustomerWithEmailContact();
        MailingMembership::addCustomer(MailingList::primaryList(), $customer);

        Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('sendListIds', [(string) MailingList::primaryList()->id])
            ->set('sendSubject', 'עדכון חודשי')
            ->set('sendBody', "שלום לכולם,\nיש לנו תוכנית חדשה!")
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sendError', null);

        $send = \App\Models\MailingListSend::firstOrFail();
        $this->assertSame('עדכון חודשי', $send->subject);
        $this->assertSame(1, $send->recipient_count);
        $this->assertStringContainsString('יש לנו תוכנית חדשה!', $send->body_html);
        $this->assertStringContainsString('images/logo.png', $send->body_html); // the branded shell
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendMailingListEmail::class, fn ($job) => $job->sendId === $send->id);
    }

    public function test_one_send_can_target_several_lists_and_each_address_gets_it_once(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $program = $this->createProgram(name: 'תוכנית לשליחה משולבת');
        $both = $this->createCustomerWithEmailContact();
        $onlyGeneral = $this->createCustomerWithEmailContact();
        MailingMembership::addCustomer(MailingList::primaryList(), $both);
        MailingMembership::addCustomer(MailingList::primaryList(), $onlyGeneral);
        MailingMembership::addCustomer(MailingList::forProgram($program), $both);

        Livewire::actingAs($this->owner)->test('mailing-lists')
            ->call('send')
            ->assertHasErrors(['sendListIds'])
            ->set('sendListIds', [(string) MailingList::primaryList()->id, (string) MailingList::forProgram($program)->id])
            ->assertSet('selectedRecipientCount', 2)
            ->set('sendSubject', 'לשתי רשימות')
            ->set('sendBody', 'שלום')
            ->call('send')
            ->assertSet('sendError', null);

        $send = \App\Models\MailingListSend::firstOrFail();
        $this->assertSame(2, $send->recipient_count);
        $this->assertCount(2, $send->mailingLists);
        $this->assertCount(2, $send->recipients());
    }

    public function test_an_uploaded_html_file_is_sent_as_is_and_an_empty_list_is_refused(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $customer = $this->createCustomerWithEmailContact();
        MailingMembership::addCustomer(MailingList::primaryList(), $customer);
        $html = '<html><body><h1>ניוזלטר</h1></body></html>';

        Livewire::actingAs($this->owner)->test('mailing-lists')
            ->set('sendListIds', [(string) MailingList::suppliersList()->id])
            ->set('sendSubject', 'ניוזלטר')
            ->set('sendMode', 'html')
            ->set('htmlFile', UploadedFile::fake()->createWithContent('news.html', $html))
            ->call('send')
            ->assertSet('sendError', 'ברשימה "ספקים" אין עדיין נמענים עם כתובת דוא"ל.')
            ->set('sendListIds', [(string) MailingList::primaryList()->id])
            ->call('send')
            ->assertSet('sendError', null);

        $this->assertSame($html, \App\Models\MailingListSend::firstOrFail()->body_html);
    }

    public function test_the_send_job_sends_one_smove_campaign_to_the_lists_recipients(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x', 'list_id' => 111]]);
        Http::fake(fn ($request) => $request->method() === 'GET' ? Http::response([], 200) : Http::response(['id' => 77], 200));
        $customer = $this->createCustomerWithEmailContact();
        MailingMembership::addCustomer(MailingList::primaryList(), $customer);
        $send = \App\Models\MailingListSend::create([
            'user_id' => $this->owner->id, 'subject' => 'שלום',
            'content_type' => 'text', 'body_html' => '<p>שלום</p>', 'status' => \App\Models\MailingListSend::STATUS_QUEUED,
        ]);
        $send->mailingLists()->attach(MailingList::primaryList());

        (new \App\Jobs\SendMailingListEmail($send->id))->handle(app(ExternalOperationRunner::class), app(SmoveClient::class));

        $this->assertSame(\App\Models\MailingListSend::STATUS_SENT, $send->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns') && $request['subject'] === 'שלום' && $request['toMembersById'] === [77]);
    }

    public function test_the_smove_list_id_is_set_in_the_smove_integration_settings(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('activeTab', 'integrations')
            ->set('smoveListId', '1174405')
            ->call('saveSmoveSettings')
            ->assertHasNoErrors();

        $this->assertSame(1174405, app(SmoveClient::class)->listId());
    }

    // ----- no delete route anywhere for materials/mailing entities -----

    public function test_there_is_no_delete_route_for_materials_or_mailing_lists(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'material') || str_contains($uri, 'mailing')) {
                $this->assertNotContains('DELETE', $route->methods(), "Unexpected DELETE route: {$uri}");
            }
        }
    }

    // ----- helpers -----

    private function isActiveMember(MailingList $list, Customer $customer): bool
    {
        return MailingMembership::where('mailing_list_id', $list->id)
            ->where('customer_id', $customer->id)
            ->where('membership_status', MailingMembership::STATUS_ACTIVE)
            ->exists();
    }

    public function test_the_dashboard_lists_sent_materials_not_yet_confirmed_received(): void
    {
        $waiting = $this->createDelivery();
        $opened = $this->createDelivery();
        $opened->markOpened();
        $confirmed = $this->createDelivery();
        $confirmed->acknowledge(app(ActivityLogger::class));
        $handled = $this->createDelivery();
        $handled->markHandled();

        $items = app(\App\Services\DashboardSummary::class)->attentionItems($this->owner)
            ->filter(fn (array $item) => str_contains($item['label'], 'טרם אושרה קבלה'));

        $this->assertSame(
            [$waiting->customer->school->name, $opened->customer->school->name],
            $items->map(fn (array $item) => explode(' — ', $item['label'])[0])->values()->all(),
        );
        $this->assertStringContainsString('המייל נפתח', $items->last()['subtitle']);
        $this->assertSame(route('customer-detail', ['customer' => $waiting->customer_id, 'tab' => 'materials']), $items->first()['url']);

        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee($waiting->customer->school->name.' — נשלחו חומרים, טרם אושרה קבלה');
        $this->actingAs($this->owner)->get($items->first()['url'])->assertOk()->assertSee('היסטוריית משלוחים');
    }

    public function test_a_user_without_materials_access_does_not_see_unconfirmed_materials_on_the_dashboard(): void
    {
        $this->createDelivery();
        $leadsOnly = Role::create(['name' => 'לידים בלבד', 'is_active' => true]);
        $leadsOnly->permissions()->create(['resource' => 'leads', 'action' => 'view', 'is_allowed' => true]);
        $limited = User::create(['name' => 'לידים בלבד', 'email' => 'leads-only@kapaim.test', 'password' => 'password', 'role_id' => $leadsOnly->id, 'is_active' => true]);
        $this->assertFalse($limited->hasPermission('materials', 'view'));

        $items = app(\App\Services\DashboardSummary::class)->attentionItems($limited);

        $this->assertFalse($items->contains(fn (array $item) => str_contains($item['label'], 'טרם אושרה קבלה')));
    }

    /** Materials go out only once the deal's contract is signed. */
    private function signContract(Deal $deal): Deal
    {
        $status = StatusDefinition::firstOrCreate(['scope' => 'document', 'name' => Document::DEFAULT_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        Document::create(['deal_id' => $deal->id, 'document_type' => 'contract', 'status_id' => $status->id, 'signed_at' => now()]);

        return $deal;
    }

    private function createDelivery(): MaterialDelivery
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        return MaterialDelivery::sendFor(
            $customer, $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );
    }

    private function userWithOnlyCustomersPermission(): User
    {
        $limited = Role::create(['name' => 'עובדת מכירות מוגבלת '.random_int(1, 999999), 'is_active' => true]);
        // Materials are sent from the customer card, so they follow the customers page's edit permission.
        $limited->permissions()->create(['resource' => 'customers', 'action' => 'view', 'is_allowed' => true]);

        return User::create([
            'name' => 'שרית לוי', 'email' => 'sarit'.random_int(1, 999999).'@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);
    }

    private function createLead(): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת חומרים '.random_int(1, 999999)]);

        return Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    public function test_marking_materials_handled_only_reaches_this_customers_deliveries(): void
    {
        $mine = $this->createCustomer();
        $other = $this->createCustomer();
        $delivery = MaterialDelivery::sendFor($other, $this->createProgram(), [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $mine])->call('markMaterialHandled', $delivery->id);
    }

    public function test_a_materials_send_that_fails_leaves_no_stored_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        Deal::createForCustomer($customer, $program, null, 400);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [\Illuminate\Http\UploadedFile::fake()->create('חוברת.pdf', 10, 'application/pdf')])
            // A recipient row with no usable address — MaterialDelivery::sendFor() refuses it.
            ->set('materialsRecipients', [['contact_id' => null, 'name' => 'ללא כתובת', 'email' => '']])
            ->call('sendMaterials')
            ->assertNotSet('materialsError', null);

        $this->assertSame(0, MaterialDelivery::count());
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('material-attachments'));
    }

    private function createCustomer(): Customer
    {
        $customer = $this->createLead()->convertToCustomer(app(ActivityLogger::class));
        // These fixtures add their own contacts — drop the primary one conversion opens from the lead's details.
        $customer->contacts()->forceDelete();

        return $customer;
    }

    private function createCustomerWithEmailContact(): Customer
    {
        $customer = $this->createCustomer();

        Contact::create([
            'school_id' => $customer->school_id,
            'customer_id' => $customer->id,
            'name' => 'איש קשר לבדיקה',
            'email' => 'contact'.random_int(1, 999999).'@example.com',
            'is_primary' => true,
        ]);

        return $customer;
    }

    private function createSubscriptionBundle(?string $name = null, float $price = 4200): Bundle
    {
        return Bundle::create([
            'name' => $name ?? 'מנוי שנתי לבדיקת חומרים '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_subscription_type' => true,
            'is_active' => true,
        ]);
    }

    private function createProgram(?string $name = null, float $price = 400, bool $isPremium = false): Program
    {
        return Program::create([
            'name' => $name ?? 'תוכנית בדיקת חומרים '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_premium' => $isPremium,
            'is_active' => true,
        ]);
    }
}
