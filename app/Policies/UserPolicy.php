<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ViewUsers);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $user->checkPermissionTo(Permission::ViewUsers);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->checkPermissionTo(Permission::CreateUsers);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->is($model) || $user->checkPermissionTo(Permission::UpdateUsers);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->is($model) || $user->checkPermissionTo(Permission::DeleteUsers);
    }

    /**
     * Determine whether the user can change the model's roles.
     *
     * Users may not change their own roles, so an admin cannot lock themselves out.
     */
    public function assignRoles(User $user, User $model): bool
    {
        return ! $user->is($model) && $user->checkPermissionTo(Permission::AssignRoles);
    }
}
