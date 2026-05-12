<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\AuditLogRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $logs = $this->auditLogRepository->list(
            $request->only(['user_id', 'action', 'platform']),
            $request->integer('per_page', 50)
        );

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
