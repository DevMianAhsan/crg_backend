<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function requirePermission(Request $request, string $permission): void
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
            && ($this->isSuperAdmin($user) || in_array($permission, $user->permissions ?? [], true)),
            403
        );
    }

    protected function isSuperAdmin(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'super-admin', 'superAdmin'], true);
    }
}
