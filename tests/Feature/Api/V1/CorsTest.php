<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_preflight_from_an_allowed_origin_is_accepted(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);

        $this->withHeaders([
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
        ])->options(route('v1.auth.login'))
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
    }

    public function test_preflight_from_an_unknown_origin_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.com',
            'Access-Control-Request-Method' => 'POST',
        ])->options(route('v1.auth.login'));

        $this->assertNotSame('https://evil.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
