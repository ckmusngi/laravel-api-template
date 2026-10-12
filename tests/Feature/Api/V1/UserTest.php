<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_users(): void
    {
        $this->getJson(route('v1.users.index'))->assertUnauthorized();
    }

    public function test_admin_can_list_users_paginated(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        User::factory()->count(3)->create();

        $this->getJson(route('v1.users.index'))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles']], 'links', 'meta'])
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_regular_user_cannot_list_users(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('v1.users.index'))->assertForbidden();
    }

    public function test_users_can_be_searched_filtered_by_role_and_sorted(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Zed Admin']);
        Sanctum::actingAs($admin);
        User::factory()->create(['name' => 'Alice Smith'])->assignRole(Role::User);
        User::factory()->create(['name' => 'Bob Smith'])->assignRole(Role::User);
        User::factory()->create(['name' => 'Carol Jones'])->assignRole(Role::User);

        $this->getJson(route('v1.users.index', ['search' => 'smith', 'sort' => '-name']))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Bob Smith', 'Alice Smith']);

        $this->getJson(route('v1.users.index', ['role' => Role::Admin->value]))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$admin->id]);

        $this->getJson(route('v1.users.index', ['sort' => 'name', 'per_page' => 2]))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Alice Smith', 'Bob Smith'])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4);
    }

    public function test_listing_rejects_unknown_sort_columns_and_oversized_pages(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson(route('v1.users.index', ['sort' => 'password', 'per_page' => 500]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_admin_can_create_a_user(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(route('v1.users.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.roles', [Role::User->value])
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'jane@example.com');
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_regular_user_cannot_create_a_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('v1.users.store'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_creating_a_user_validates_input(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['email' => 'taken@example.com']));

        $this->postJson(route('v1.users.store'), [
            'email' => 'taken@example.com',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_user_can_view_their_own_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('v1.users.show', $user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_regular_user_cannot_view_another_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('v1.users.show', User::factory()->create()))->assertForbidden();
    }

    public function test_admin_can_view_any_user(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $other = User::factory()->create();

        $this->getJson(route('v1.users.show', $other))
            ->assertOk()
            ->assertJsonPath('data.email', $other->email);
    }

    public function test_missing_user_returns_not_found_without_leaking_model_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson(route('v1.users.show', 999))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_user_can_update_their_own_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson(route('v1.users.update', $user), [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_changing_email_sends_verification_when_verification_is_required(): void
    {
        Notification::fake();
        config(['auth.verification.required' => true]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson(route('v1.users.update', $user), ['email' => 'new@example.com'])->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_changing_password_requires_the_current_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson(route('v1.users.update', $user), [
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->patchJson(route('v1.users.update', $user), [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-password', $user->fresh()->password));
    }

    public function test_regular_user_cannot_update_another_user(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = User::factory()->create();

        $this->patchJson(route('v1.users.update', $other), ['name' => 'Hacked'])
            ->assertForbidden();

        $this->assertNotSame('Hacked', $other->fresh()->name);
    }

    public function test_admin_can_update_another_user(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $other = User::factory()->create();

        $this->patchJson(route('v1.users.update', $other), ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $other->fresh()->name);
    }

    public function test_user_can_soft_delete_their_own_account(): void
    {
        $user = User::factory()->create();
        $user->createToken('api');
        Sanctum::actingAs($user);

        $this->deleteJson(route('v1.users.destroy', $user))->assertNoContent();

        $this->assertSoftDeleted($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_regular_user_cannot_delete_another_user(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = User::factory()->create();

        $this->deleteJson(route('v1.users.destroy', $other))->assertForbidden();

        $this->assertNotSoftDeleted($other);
    }

    public function test_admin_can_delete_another_user(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $other = User::factory()->create();

        $this->deleteJson(route('v1.users.destroy', $other))->assertNoContent();

        $this->assertSoftDeleted($other);
    }

    public function test_unverified_user_is_blocked_when_verification_is_required(): void
    {
        config(['auth.verification.required' => true]);
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('v1.users.show', $user))->assertForbidden();
        $this->getJson(route('v1.auth.me'))->assertOk();
    }

    public function test_unverified_user_is_allowed_when_verification_is_optional(): void
    {
        config(['auth.verification.required' => false]);
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('v1.users.show', $user))->assertOk();
    }
}
