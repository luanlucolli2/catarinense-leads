<?php

declare(strict_types=1);

namespace App\Modules\DisparosWhatsappVendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DisparosWhatsappVendeai\Requests\PreviewRegisteredLeadsRequest;
use App\Modules\DisparosWhatsappVendeai\Services\RegisteredLeadsPreviewService;
use Illuminate\Http\JsonResponse;

class RegisteredLeadsPreviewController extends Controller
{
    public function __invoke(PreviewRegisteredLeadsRequest $request, RegisteredLeadsPreviewService $service): JsonResponse
    {
        return response()->json($service->preview($request->filters()));
    }
}
