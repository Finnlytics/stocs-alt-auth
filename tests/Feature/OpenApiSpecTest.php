<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    public function test_openapi_spec_is_served(): void
    {
        $response = $this->get('/api/docs/openapi.yaml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/yaml');

        $body = $response->streamedContent();

        $this->assertStringContainsString('openapi: 3.1.0', $body);
        $this->assertStringContainsString('title: Stocs Auth API', $body);
        $this->assertStringContainsString('/v1/auth/login/b2b:', $body);
        $this->assertStringContainsString('/v1/service/validate-token:', $body);
    }

    public function test_openapi_spec_file_exists_on_disk(): void
    {
        $this->assertFileExists(base_path('docs/openapi.yaml'));
    }
}
