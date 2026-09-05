<?php

use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts.app', ['title' => 'משתמשות והרשאות — כפיים'])]
class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $personalEmail = '';
    public ?int $roleId = null;
    public bool $isActive = true;

    public ?string $generatedPassword = null;
    public ?string $generatedPasswordForName = null;

    /**
     * Stage 19 hardening (FR-7.1-7.6 permission-gate exhaustiveness audit):
     * this screen creates users and assigns roles/permissions — genuinely
     * sensitive, and previously had no gate at all (harmless only because
     * MVP's one real role is full-access; a future limited role like
     * "עובדת מכירות" would otherwise reach it unrestricted).
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->can('users.manage'), 403);

        $this->roleId = Role::where('is_active', true)->value('id');
    }

    /**
     * Manual creation is the only way a user enters the system — there is no
     * self-registration (build-plan 01 / PRD 1.3.12).
     */
    public function addUser(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'personalEmail' => ['nullable', 'email'],
            'roleId' => ['required', 'exists:roles,id'],
        ]);

        $password = Str::password(12);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'personal_email' => $data['personalEmail'] ?: null,
            'role_id' => $data['roleId'],
            'is_active' => $this->isActive,
            'password' => $password,
        ]);

        $activityLogger->log('user.created', "נוצרה משתמשת חדשה: {$user->name}", [
            'metadata' => ['created_user_id' => $user->id],
        ]);

        $this->generatedPassword = $password;
        $this->generatedPasswordForName = $user->name;

        $this->reset(['name', 'email', 'personalEmail']);
        $this->isActive = true;

        unset($this->users);
    }

    #[Computed]
    public function users()
    {
        return User::with('role')->orderBy('name')->get();
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('id')->get();
    }

    /** Resources shown in the per-role permission preview table. */
    public function previewResources(): array
    {
        return ['leads', 'customers', 'deals', 'documents', 'payments'];
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">משתמשות והרשאות</h1>
            <p class="text-text-secondary m-0">ב-MVP לבעלת העסק ולמזכירה גישה מלאה זהה. מנגנון ההרשאות תומך בהרחבה עתידית לתפקיד מוגבל בלי שינוי ארכיטקטורה.</p>
        </div>
    </div>

    @if ($generatedPassword)
        <div class="mb-6" style="background: var(--color-success-bg); color: var(--color-success); border-radius: var(--radius-control); padding: var(--sp-md); font-size: var(--fs-small);">
            נוצרה משתמשת <strong>{{ $generatedPasswordForName }}</strong>. סיסמה ראשונית (מסרו למשתמשת באופן מאובטח): <code class="ltr-num" dir="ltr">{{ $generatedPassword }}</code>
        </div>
    @endif

    <section>
        <div class="section-head">
            <h2>משתמשות</h2>
        </div>
        <div class="card" style="padding:0; overflow:hidden">
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>שם</th>
                        <th>אימייל</th>
                        <th>אימייל אישי</th>
                        <th>תפקיד</th>
                        <th>סטטוס</th>
                        <th>פעולות</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td class="ltr-num" style="direction:ltr; text-align:right">{{ $user->email }}</td>
                            <td class="ltr-num" style="direction:ltr; text-align:right">{{ $user->personal_email ?? '—' }}</td>
                            <td><span class="badge badge-primary">{{ $user->role?->name ?? '—' }}</span></td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge badge-success">פעילה</span>
                                @else
                                    <span class="badge badge-neutral">לא פעילה</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="btn btn-ghost is-disabled" style="padding:6px 12px" disabled title="עריכת משתמשת תתווסף בשלב עתידי">עריכה</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </section>

    <section>
        <div class="section-head">
            <h2>תפקידים והרשאות</h2>
        </div>
        <div class="cols2">
            @foreach ($this->roles as $role)
                <div class="card">
                    <h3>{{ $role->name }}</h3>
                    <div class="table-scroll">
                    <table class="perm-table">
                        <thead><tr><th>משאב</th><th>צפייה</th><th>עריכה</th></tr></thead>
                        <tbody>
                            @foreach ($this->previewResources() as $resource)
                                <tr>
                                    <td>{{ $resource }}</td>
                                    <td class="{{ $role->hasPermission($resource, 'view') ? 'perm-cell-yes' : 'perm-cell-no' }}">{{ $role->hasPermission($resource, 'view') ? 'כן' : '—' }}</td>
                                    <td class="{{ $role->hasPermission($resource, 'edit') ? 'perm-cell-yes' : 'perm-cell-no' }}">{{ $role->hasPermission($resource, 'edit') ? 'כן' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <div class="section-head">
            <h2>הוספת משתמשת חדשה</h2>
        </div>
        <div class="card" style="max-width:640px">
            <form wire:submit="addUser" class="form-grid">
                <div>
                    <label for="name">שם מלא</label>
                    <input type="text" id="name" wire:model="name" placeholder="למשל: שרית לוי">
                    @error('name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="roleId">תפקיד</label>
                    <select id="roleId" wire:model="roleId">
                        @foreach ($this->roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="email">אימייל מערכת</label>
                    <input type="text" id="email" wire:model="email" class="ltr-num" dir="ltr" placeholder="name@kapaim.co.il">
                    @error('email') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="personalEmail">אימייל אישי</label>
                    <input type="text" id="personalEmail" wire:model="personalEmail" class="ltr-num" dir="ltr" placeholder="name@gmail.com">
                    @error('personalEmail') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full checkbox-row">
                    <input type="checkbox" id="isActive" wire:model="isActive">
                    <label for="isActive" style="margin:0">משתמשת פעילה</label>
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">הוספת משתמשת</button></div>
            </form>
        </div>
    </section>
</div>
