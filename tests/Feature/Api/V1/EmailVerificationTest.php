<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_can_be_verified_with_a_valid_signed_link(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user))->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_email_is_not_verified_with_an_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user, hash: sha1('wrong@example.com')))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_is_not_verified_with_a_tampered_or_expired_signature(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user).'tampered')->assertForbidden();

        $url = $this->verificationUrl($user);
        $this->travel(61)->minutes();
        $this->getJson($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_email_link_points_to_the_signed_verify_route(): void
    {
        Notification::fake();
        config(['auth.verification.required' => true]);
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('v1.auth.verification.send'))->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/api/v1/auth/email/verify/'.$user->id.'/') && str_contains($url, 'signature=');
        });
    }

    public function test_verification_email_is_not_resent_to_verified_users(): void
    {
        Notification::fake();
        config(['auth.verification.required' => true]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('v1.auth.verification.send'))->assertOk();

        Notification::assertNothingSent();
    }

    public function test_resend_endpoint_is_unavailable_when_verification_is_disabled(): void
    {
        Notification::fake();
        config(['auth.verification.required' => false]);
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->postJson(route('v1.auth.verification.send'))->assertNotFound();

        Notification::assertNothingSent();
    }

    private function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('v1.auth.verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }
}
