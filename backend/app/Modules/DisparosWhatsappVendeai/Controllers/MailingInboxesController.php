<?php

declare(strict_types=1);

namespace App\Modules\DisparosWhatsappVendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DisparosWhatsappVendeai\Exceptions\MailingInboxesConfigurationException;
use App\Modules\DisparosWhatsappVendeai\Exceptions\MailingInboxesRequestException;
use App\Modules\DisparosWhatsappVendeai\Services\MailingInboxesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailingInboxesController extends Controller
{
    public function __invoke(Request $request, MailingInboxesService $service): JsonResponse
    {
        try {
            return response()->json($service->list($request->boolean('refresh')));
        } catch (MailingInboxesConfigurationException) {
            return response()->json(['message' => 'Integração de mailing VendeAI não configurada.'], 503);
        } catch (MailingInboxesRequestException) {
            return response()->json(['message' => 'Não foi possível carregar inboxes e templates da VendeAI. Tente novamente.'], 502);
        }
    }
}
