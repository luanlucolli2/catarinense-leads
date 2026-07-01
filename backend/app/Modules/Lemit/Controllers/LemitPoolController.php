<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lemit\Requests\PreviewLemitPoolRequest;
use App\Modules\Lemit\Requests\SampleLemitPoolRequest;
use App\Modules\Lemit\Services\LemitPoolQueryService;
use Illuminate\Http\JsonResponse;

class LemitPoolController extends Controller
{
    public function preview(PreviewLemitPoolRequest $request, LemitPoolQueryService $service): JsonResponse
    {
        return response()->json($service->preview($request->filters()));
    }

    public function sample(SampleLemitPoolRequest $request, LemitPoolQueryService $service): JsonResponse
    {
        return response()->json($service->sample($request->filters(), $request->quantity()));
    }
}
