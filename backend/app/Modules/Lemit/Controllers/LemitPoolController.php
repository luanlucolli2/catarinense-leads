<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lemit\Requests\PreviewLemitPoolRequest;
use App\Modules\Lemit\Requests\SampleLemitPoolRequest;
use App\Modules\Lemit\Services\LemitPoolQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class LemitPoolController extends Controller
{
    public function preview(PreviewLemitPoolRequest $request, LemitPoolQueryService $service): JsonResponse
    {
        return response()->json($service->preview($request->filters()));
    }

    /**
     * @throws ValidationException
     */
    public function sample(SampleLemitPoolRequest $request, LemitPoolQueryService $service): JsonResponse
    {
        $filters = $request->filters();
        $quantity = $request->quantity();
        $poolSize = $service->count($filters);

        if ($quantity > $poolSize) {
            throw ValidationException::withMessages([
                'quantity' => ['A quantidade solicitada excede a base filtrada atual.'],
            ]);
        }

        return response()->json($service->sample($filters, $quantity, $poolSize));
    }
}
