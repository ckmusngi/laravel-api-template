<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_replace_another_users_roles(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $user = User::factory()->create();
        $user->assignRole(Role::User);

        $this->putJson(route('v1.users.roles.update', $user), ['roles' => [Role::Admin->value]])
            ->assertOk()
            ->assertJsonPath('data.roles', [Role::Admin->value]);

        $this->assertTrue($user->fresh()->hasRole(Role::Admin));
        $this->assertFalse($user->fresh()->hasRole(Role::User));
    }

    public function test_promoted_user_gains_admin_permissions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson(route('v1.users.roles.update', $user), ['roles' => [Role::Admin->value]])->assertOk();

        Sanctum::actingAs($user->fresh());

        $this->getJson(route('v1.users.index'))->assertOk();
    }

    public function test_admin_cannot_change_their_own_roles(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson(route('v1.users.roles.update', $admin), ['roles' => [Role::User->value]])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole(Role::Admin));
    }

    public function test_regular_user_cannot_assign_roles(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = User::factory()->create();

        $this->putJson(route('v1.users.roles.update', $other), ['roles' => [Role::Admin->value]])
            ->assertForbidden();

        $this->assertFalse($other->fresh()->hasRole(Role::Admin));
    }

    public function test_roles_must_be_known_values(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson(route('v1.users.roles.update', User::factory()->create()), ['roles' => ['superuser']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles.0');
    }
}
