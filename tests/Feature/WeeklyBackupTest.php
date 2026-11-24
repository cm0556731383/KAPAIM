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
        $this->backup = new WeeklyBackup($this->dir);
        $this->app->instance(WeeklyBackup::class, $this->backup);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_package_is_locked_with_the_password_and_opens_only_with_it(): void
    {
        $this->backup->setPassword('סוד-גדול-123');
        $bundle = $this->fakeBundle('database contents');

        $zip = $this->backup->package($bundle);
        $this->assertFileExists($zip);
        $this->assertStringNotContainsString('database contents', file_get_contents($zip));

        $out = $this->dir.'/out.tar.gz';
        $this->assertFalse(WeeklyBackup::open($zip, 'wrong-password', $out));
        $this->assertFileDoesNotExist($out);

        $this->assertTrue(WeeklyBackup::open($zip, 'סוד-גדול-123', $out));
        $this->assertSame(file_get_contents($bundle), file_get_contents($out));
    }

    public function test_only_the_two_newest_packages_are_kept(): void
    {
        $this->backup->setPassword('password1');
        File::ensureDirectoryExists($this->dir);
        touch($this->dir.'/kapaim-backup_2026-01-01_03-00.zip');
        touch($this->dir.'/kapaim-backup_2026-01-08_03-00.zip');

        $this->backup->package($this->fakeBundle());

        $this->assertCount(2, glob($this->dir.'/kapaim-backup_*.zip'));
        $this->assertFileDoesNotExist($this->dir.'/kapaim-backup_2026-01-01_03-00.zip');
    }

    public function test_the_package_needs_a_password(): void
    {
        $this->artisan('backup:weekly-package', ['bundle' => $this->fakeBundle()])->assertSuccessful();
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
        $this->backup->package($this->fakeBundle());
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
        $this->backup->package($this->fakeBundle());

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

    private function fakeBundle(string $content = 'x'): string
    {
        File::ensureDirectoryExists($this->dir);
        $path = $this->dir.'/bundle-'.uniqid().'.tar.gz';
        file_put_contents($path, gzencode($content));

        return $path;
    }
}
