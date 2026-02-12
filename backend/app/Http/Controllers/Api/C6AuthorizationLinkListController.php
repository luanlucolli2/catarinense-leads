<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\C6AuthorizationLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class C6AuthorizationLinkListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'nome' => ['nullable', 'string', 'max:255'],
            'nome_cliente' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in([
                C6AuthorizationLink::STATUS_ACTIVE,
                C6AuthorizationLink::STATUS_EXPIRED,
            ])],
        ]);

        $user = $request->user();
        $now = now();

        $perPage = (int) ($data['per_page'] ?? 20);
        $perPage = max(1, min(100, $perPage));

        $query = C6AuthorizationLink::query()
            ->where('user_id', $user->id);

        $nomeCliente = trim((string) ($data['nome'] ?? $data['nome_cliente'] ?? ''));
        if ($nomeCliente !== '') {
            $query->where('nome_cliente', 'like', '%' . $nomeCliente . '%');
        }

        $cpf = $this->digitsOnly((string) ($data['cpf'] ?? ''));
        if ($cpf !== '') {
            if (strlen($cpf) === 11) {
                $query->where('cpf', $cpf);
            } else {
                $query->where('cpf', 'like', '%' . $cpf . '%');
            }
        }

        $status = $data['status'] ?? null;
        if ($status === C6AuthorizationLink::STATUS_ACTIVE) {
            $query->where('expires_at', '>', $now);
        } elseif ($status === C6AuthorizationLink::STATUS_EXPIRED) {
            $query->where('expires_at', '<=', $now);
        }

        $links = $query
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(static function (C6AuthorizationLink $item) use ($now): array {
                $effectiveStatus = $item->expires_at?->lte($now)
                    ? C6AuthorizationLink::STATUS_EXPIRED
                    : C6AuthorizationLink::STATUS_ACTIVE;

                return [
                    'id' => $item->id,
                    'cpf' => $item->cpf,
                    'nome_cliente' => $item->nome_cliente,
                    'link' => $item->link,
                    'generated_at' => $item->generated_at?->toIso8601String(),
                    'data_expiracao' => $item->expires_at?->toIso8601String(),
                    'status' => $effectiveStatus,
                ];
            });

        return response()->json($links);
    }

    protected function digitsOnly(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }
}
