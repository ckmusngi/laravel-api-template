<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;

class UserRoleController extends Controller
{
    /**
     * Replace the user's roles with the given set.
     */
    public function update(UpdateUserRolesRequest $request, User $user): UserResource
    {
        $user->syncRoles($request->validated('roles'));

        return new UserResource($user->load('roles'));
    }
}
