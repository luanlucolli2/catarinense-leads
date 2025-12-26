<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendUraOfficialTemplateJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UraSendOfficialTemplateController extends Controller
{
    /**
     * Endpoint chamado pela URA ao final da ligação.
     *
     * Não recebe token de conexão do Inovachat.
     * O backend escolhe uma conexão aleatória (INOVACHAT_CONNECTION_TOKENS) e envia via API Oficial.
     */
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'number'   => ['required', 'string', 'max:20', 'regex:/^\d{10,15}$/'], // ddi+ddd+numero (somente números)
            'name'     => ['required', 'string', 'max:255'],                       // nome do template
            'language' => ['nullable', 'string', 'max:10'],                        // ex: pt_BR
        ]);

        $trackingId = (string) Str::uuid();

        $language = $data['language'] ?: config('ura.default_language', 'pt_BR');

        SendUraOfficialTemplateJob::dispatch(
            number: $data['number'],
            templateName: $data['name'],
            language: $language,
            trackingId: $trackingId,
        )->onQueue(config('ura.job_queue', 'ura'));

        return response()->json([
            'ok'          => true,
            'tracking_id' => $trackingId,
            'queued'      => true,
        ], 202);
    }
}
