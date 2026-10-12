<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson(route('v1.auth.register'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'roles']]])
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.user.roles', [Role::User->value])
            ->assertJsonMissingPath('data.user.password');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_registration_does_not_send_verification_email_when_verification_is_disabled(): void
    {
        Notification::fake();
        config(['auth.verification.required' => false]);

        $this->postJson(route('v1.auth.register'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_registration_sends_verification_email_when_verification_is_required(): void
    {
        Notification::fake();
        config(['auth.verification.required' => true]);

        $this->postJson(route('v1.auth.register'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertCreated();

        Notification::assertSentTo(User::firstWhere('email', 'jane@example.com'), VerifyEmail::class);
    }

    public function test_registration_requires_a_unique_email_and_confirmed_password(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(route('v1.auth.register'), [
            'name' => 'Jane Doe',
            'email' => 'taken@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'iphone',
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertSame('iphone', $user->tokens()->first()->name);
    }

    public function test_issued_tokens_expire_after_the_configured_lifetime(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = User::factory()->create();

        $token = $this->postJson(route('v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.expires_at', fn (?string $value): bool => $value !== null)
            ->json('data.token');

        $this->travel(61)->minutes();

        $this->withToken($token)->getJson(route('v1.auth.me'))->assertUnauthorized();
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->postJson(route('v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('v1.auth.login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson(route('v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson(route('v1.auth.me'))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_guest_cannot_fetch_profile(): void
    {
        $this->getJson(route('v1.auth.me'))->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('current')->plainTextToken;
        $user->createToken('other');

        $this->withToken($token)->postJson(route('v1.auth.logout'))->assertNoContent();

        $this->assertSame(['other'], $user->tokens()->pluck('name')->all());
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $user->createToken('other');
        Sanctum::actingAs($user);

        $this->postJson(route('v1.auth.logout-all'))->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }
}
