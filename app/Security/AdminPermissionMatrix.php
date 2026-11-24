<?php

namespace App\Security;

use App\Enums\AdminSubrole;
use App\Models\User;

final class AdminPermissionMatrix
{
    /**
     * Central permission-to-subroles mapping (Single Source of Truth).
     *
     * @var array<string, list<AdminSubrole>>
     */
    public const PERMISSIONS = [
        'finance.view' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::FINANCE],
        'finance.audit' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::FINANCE],
        'withdrawals.approve' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::FINANCE],
        'disputes.resolve' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::CS_ARBITRATOR],
        'warranty.resolve' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::CS_ARBITRATOR],
        'moderation.manage' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::CS_ARBITRATOR],
        'kyc.review' => [AdminSubrole::SUPER_ADMIN, AdminSubrole::KYC_OFFICER],
        'cms.manage' => [AdminSubrole::SUPER_ADMIN],
        'chaos.run' => [AdminSubrole::SUPER_ADMIN],
    ];

    public static function allows(User $user, string $permission): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        $allowedSubroles = self::PERMISSIONS[$permission] ?? [];
        if ($allowedSubroles === []) {
            return false;
        }

        $subrole = $user->admin_subrole ?? AdminSubrole::SUPER_ADMIN;

        return in_array($subrole, $allowedSubroles, true);
    }
}
