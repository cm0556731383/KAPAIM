<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealFile;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** 2026-11-24: a small folder of PDFs/images per deal — upload, view, download, delete. */
class DealFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);
    }

    public function test_files_uploaded_on_the_deal_page_are_stored_and_can_be_viewed_and_downloaded(): void
    {
        $deal = $this->createDeal();

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('dealUploads', [
                UploadedFile::fake()->create('טופס הזמנה.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->image('צילום צ׳ק.jpg'),
            ])
            ->assertHasNoErrors()
            ->assertSee('טופס הזמנה.pdf')
            ->assertSee('צילום צ׳ק.jpg');

        $this->assertSame(2, $deal->files()->count());
        $pdf = $deal->files()->where('original_name', 'טופס הזמנה.pdf')->sole();
        Storage::disk('local')->assertExists($pdf->path);
        $this->assertSame($this->owner->id, $pdf->uploaded_by);

        $this->actingAs($this->owner)->get(route('deals.files.show', [$deal, $pdf]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->owner)->get(route('deals.files.show', [$deal, $pdf, 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition', "attachment; filename=deal-{$deal->id}-file-{$pdf->id}.pdf; filename*=utf-8''".rawurlencode('טופס הזמנה.pdf'));
    }

    public function test_only_pdfs_and_images_are_accepted(): void
    {
        $deal = $this->createDeal();

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('dealUploads', [UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
            ->assertHasErrors('dealUploads.0');

        $this->assertSame(0, $deal->files()->count());
    }

    public function test_deleting_a_file_removes_it_from_the_folder_and_the_disk(): void
    {
        $deal = $this->createDeal();
        $file = DealFile::attachTo($deal, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $this->owner);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->call('deleteDealFile', $file->id)
            ->assertDontSee('a.pdf');

        $this->assertModelMissing($file);
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_a_file_is_served_only_under_its_own_deal_and_only_to_users_who_see_deals(): void
    {
        $deal = $this->createDeal();
        $other = $this->createDeal();
        $file = DealFile::attachTo($deal, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $this->owner);

        $this->actingAs($this->owner)->get(route('deals.files.show', [$other, $file]))->assertNotFound();

        $noAccess = User::create([
            'name' => 'ללא הרשאה', 'email' => 'none@kapaim.test', 'password' => 'password',
            'role_id' => Role::create(['name' => 'ריק', 'is_active' => true])->id, 'is_active' => true,
        ]);
        $this->actingAs($noAccess)->get(route('deals.files.show', [$deal, $file]))->assertForbidden();

        auth()->logout();
        $this->get(route('deals.files.show', [$deal, $file]))->assertRedirect();
    }

    private function createDeal(): Deal
    {
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $lead = Lead::create([
            'school_id' => School::create(['name' => 'בית ספר '.random_int(1, 999999)])->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        return Deal::createWithItems($customer, [['program' => Program::create(['name' => 'אלול', 'price' => 1000, 'is_active' => true])]]);
    }
}
