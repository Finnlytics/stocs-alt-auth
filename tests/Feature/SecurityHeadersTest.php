<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_present_on_api_response(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_cors_rejects_disallowed_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.com',
        ])->getJson('/api/health');

        $this->assertNotSame('https://evil.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_allows_configured_origin(): void
    {
        config(['cors.allowed_origins' => ['https://b2b.example.com']]);

        $response = $this->withHeaders([
            'Origin' => 'https://b2b.example.com',
        ])->getJson('/api/health');

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://b2b.example.com');
    }
}
