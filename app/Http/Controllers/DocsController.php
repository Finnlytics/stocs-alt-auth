<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocsController extends Controller
{
    public function openapi(): BinaryFileResponse
    {
        return response()->file(
            base_path('docs/openapi.yaml'),
            ['Content-Type' => 'application/yaml']
        );
    }
}
