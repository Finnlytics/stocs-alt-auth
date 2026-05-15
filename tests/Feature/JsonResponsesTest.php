<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * stocs-auth is a headless API. Every endpoint, every error path, must
 * return application/json — no HTML stack traces, no welcome views, no
 * framework-default error pages. This suite exercises the well-known
 * leak points (unknown route, wrong method, unauthenticated, validation
 * error, root path) and asserts JSON each time.
 */
class JsonResponsesTest extends TestCase
{
    public function test_unknown_api_route_returns_json_404(): void
    {
        $response = $this->get('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404);
        $this->assertJsonContentType($response);
        $response->assertJson(['message' => 'Not found.']);
    }

    public function test_unknown_root_route_returns_json_404(): void
    {
        $response = $this->get('/some/random/path');

        $response->assertStatus(404);
        $this->assertJsonContentType($response);
    }

    public function test_root_returns_json_not_welcome_view(): void
    {
        $response = $this->get('/');

        $this->assertJsonContentType($response);
        $this->assertStringNotContainsString('<html', (string) $response->getContent());
    }

    public function test_wrong_http_method_returns_json_405(): void
    {
        // /api/health is GET-only — a POST should be rejected as JSON.
        $response = $this->post('/api/health');

        $response->assertStatus(405);
        $this->assertJsonContentType($response);
    }

    public function test_validation_error_returns_json_even_without_accept_header(): void
    {
        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/auth/login/b2b',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'text/html',
            ],
            content: json_encode(['email' => 'not-an-email']),
        );

        $response->assertStatus(422);
        $this->assertJsonContentType($response);
        $response->assertJsonStructure(['message', 'errors']);
    }

    public function test_unauthenticated_request_returns_json_401(): void
    {
        $response = $this->get('/api/v1/auth/me');

        $response->assertStatus(401);
        $this->assertJsonContentType($response);
    }

    private function assertJsonContentType($response): void
    {
        $contentType = $response->headers->get('Content-Type', '');
        $this->assertStringContainsString(
            'application/json',
            $contentType,
            "Expected JSON Content-Type, got: {$contentType}",
        );
    }
}
