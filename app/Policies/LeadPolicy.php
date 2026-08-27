<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Build-plan 13 (FR-7.2–FR-7.4): record-level access on top of the
 * resource-level `leads.manage`/`leads.view` check already answered by
 * AppServiceProvider's Gate::before for dotted abilities. This policy is
 * reached through non-dotted abilities only (e.g. `can('view', $lead)`),
 * which the Gate::before callback deliberately lets fall through — see its
 * docblock.
 *
 * Auto-discovered by Laravel's Model→Policy naming convention — no explicit
 * registration needed (verified: no AuthServiceProvider/policy map exists
 * anywhere in this codebase, and this Lead <-> LeadPolicy pairing follows it).
 */
class LeadPolicy
{
    /**
     * Full-access roles (owner/secretary, `leads`/`manage`) always see every
     * lead, unfiltered. A `leads`/`view`-only role (a future "עובדת מכירות")
     * may only view a lead it is assigned to, and only while it is still a
     * lead — FR-7.4: once converted to a customer, `converted_at` is set and
     * this same check silently revokes access, no separate process needed.
     */
    public function view(User $user, Lead $lead): bool
    {
        if ($user->hasPermission('leads', 'manage')) {
            return true;
        }

        if ($user->hasPermission('leads', 'view')) {
            return $lead->assigned_user_id === $user->id && $lead->converted_at === null;
        }

        return false;
    }
}
