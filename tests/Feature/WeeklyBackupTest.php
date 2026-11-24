<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WeeklyBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** 2026-11-24: the weekly password-locked backup that downloads by itself to the chosen user. */
class WeeklyBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $dir;

    private WeeklyBackup $backup;

    protected function setUp(): void
    {
        parent::setUp();

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);

        $this->dir = sys_get_temp_dir().'/weekly-backup-test-'.uniqid();
        $this->backup = new WeeklyBackup($this->dir, $this->dir.'/app');
        $this->app->instance(WeeklyBackup::class, $this->backup);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_package_is_a_regular_password_zip_with_the_database_and_the_files(): void
    {
        File::ensureDirectoryExists($this->dir.'/app/private/deal-files/1');
        File::ensureDirectoryExists($this->dir.'/app/private/livewire-tmp');
        file_put_contents($this->dir.'/app/private/deal-files/1/הזמנה.pdf', 'pdf bytes');
        file_put_contents($this->dir.'/app/private/livewire-tmp/half-uploaded', 'skip me');
        $this->backup->setPassword('סוד-גדול-123');

        $zip = $this->backup->package($this->fakeDump('database contents'), 'abc123');
        $this->assertFileExists($zip);
        $this->assertStringNotContainsString('database contents', file_get_contents($zip));

        // Classic zip encryption — what Windows opens with its own zip folder.
        $archive = new \ZipArchive;
        $archive->open($zip);
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);
            $names[] = $stat['name'];
            $this->assertSame(\ZipArchive::EM_TRAD_PKWARE, $stat['encryption_method'], $stat['name']);
        }
        $archive->close();
        $this->assertContains('database.dump', $names);
        $this->assertContains('app-key.env', $names);
        $this->assertContains('files/private/deal-files/1/הזמנה.pdf', $names);
        $this->assertNotContains('files/private/livewire-tmp/half-uploaded', $names);

        $out = $this->dir.'/out';
        $this->assertFalse(WeeklyBackup::open($zip, 'wrong-password', $out));

        $this->assertTrue(WeeklyBackup::open($zip, 'סוד-גדול-123', $out));
        $this->assertSame('database contents', file_get_contents($out.'/database.dump'));
        $this->assertSame('pdf bytes', file_get_contents($out.'/files/private/deal-files/1/הזמנה.pdf'));
        $this->assertSame('APP_KEY='.config('app.key')."\n", file_get_contents($out.'/app-key.env'));
        $this->assertSame("abc123\n", file_get_contents($out.'/version.txt'));
    }

    public function test_only_the_two_newest_packages_are_kept(): void
    {
        $this->backup->setPassword('password1');
        File::ensureDirectoryExists($this->dir);
        touch($this->dir.'/kapaim-backup_2026-01-01_03-00.zip');
        touch($this->dir.'/kapaim-backup_2026-01-08_03-00.zip');

        $this->backup->package($this->fakeDump());

        $this->assertCount(2, glob($this->dir.'/kapaim-backup_*.zip'));
        $this->assertFileDoesNotExist($this->dir.'/kapaim-backup_2026-01-01_03-00.zip');
    }

    public function test_the_package_needs_a_password(): void
    {
        $this->artisan('backup:weekly-package', ['dump' => $this->fakeDump()])->assertSuccessful();
        $this->assertSame([], glob($this->dir.'/kapaim-backup_*.zip') ?: []);
    }

    public function test_it_downloads_by_itself_once_for_the_chosen_user_only(): void
    {
        $other = User::create([
            'name' => 'מזכירה', 'email' => 'other@kapaim.test', 'password' => 'password',
            'role_id' => $this->owner->role_id, 'is_active' => true,
        ]);
        $this->backup->setPassword('password1');
        $this->backup->setRecipient($this->owner->id);
        $this->backup->package($this->fakeDump());
        $download = route('backups.weekly.download');

        $notice = 'הגיבוי השבועי של המערכת יורד';
        $this->actingAs($other)->get('/')->assertOk()->assertDontSee($notice);
        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee($notice)->assertSee('backups\/weekly\/download', false);

        $this->actingAs($this->owner)->get($download)->assertOk()->assertDownload($this->backup->latest()['name']);

        // Once it's down, it doesn't start again until next week's package.
        $this->actingAs($this->owner)->get('/')->assertOk()->assertDontSee($notice);
        $this->assertSame($this->owner->name, $this->backup->lastDownloaded()['user']);
    }

    public function test_a_user_who_is_not_the_recipient_and_cannot_edit_settings_cannot_download_it(): void
    {
        $viewer = User::create([
            'name' => 'צופה', 'email' => 'viewer@kapaim.test', 'password' => 'password',
            'role_id' => Role::create(['name' => 'ריק', 'is_active' => true])->id, 'is_active' => true,
        ]);
        $this->backup->setPassword('password1');
        $this->backup->package($this->fakeDump());

        $this->actingAs($viewer)->get(route('backups.weekly.download'))->assertForbidden();
    }

    public function test_the_settings_tab_saves_the_password_and_the_recipient(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('activeTab', 'backup')
            ->set('backupUserId', (string) $this->owner->id)
            ->call('saveWeeklyBackup')
            ->assertHasErrors('backupPassword')
            ->set('backupPassword', 'short')
            ->set('backupPassword_confirmation', 'short')
            ->call('saveWeeklyBackup')
            ->assertHasErrors('backupPassword')
            ->set('backupPassword', 'long-enough-1')
            ->set('backupPassword_confirmation', 'long-enough-1')
            ->call('saveWeeklyBackup')
            ->assertHasNoErrors()
            ->assertSee('פעיל');

        $this->assertTrue($this->backup->isActive());
        $this->assertSame($this->owner->id, $this->backup->recipient()->id);
    }

    private function fakeDump(string $content = 'x'): string
    {
        File::ensureDirectoryExists($this->dir);
        $path = $this->dir.'/dump-'.uniqid().'.dump';
        file_put_contents($path, $content);

        return $path;
    }
}
