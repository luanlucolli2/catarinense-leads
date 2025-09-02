<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFgtsOfflineJob;
use App\Models\FgtsOfflineJob;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator; // 👈 1. Importe o Facade Validator


class FgtsOfflineController extends Controller
{
    public function index(Request $request)
    {
        $jobs = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,         // autorizado
            'not_authorized_count' => $job->not_authorized_count,  // não autorizado
            'fail_count' => $job->fail_count,            // erro
            'has_file' => $job->file_disk && $job->file_path,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'scheduled_for' => $job->scheduled_for,         // início (UTC)
            'scheduled_until' => $job->scheduled_until,       // fim (UTC) 👈 novo
            'created_at' => $job->created_at,
            // prévia
            'has_preview' => $job->preview_disk && $job->preview_path,
            'preview_updated_at' => $job->preview_updated_at,
        ]);
    }

    public function store(Request $request)
    {
        // 👇 --- INÍCIO DA MUDANÇA --- 👇

        // 2. Definimos as regras de validação
        $rules = [
            'title' => ['required', 'string', 'max:191'],
            'cpfs' => ['required'],
            'run_at' => ['nullable', 'date'],
            // Regra 'after' já garante que a data final é maior que a inicial
            'end_at' => ['nullable', 'date', 'required_with:run_at', 'after:run_at'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];

        // 3. Usamos o Facade Validator para criar a validação manualmente
        $validator = Validator::make($request->all(), $rules, [
            // Mensagens de erro personalizadas (opcional)
            'end_at.required_with' => 'O campo end_at é obrigatório quando run_at está presente.',
            'end_at.after' => 'O horário final (end_at) deve ser maior que o horário inicial (run_at).',
        ]);

        // 4. Verificamos se a validação falhou
        if ($validator->fails()) {
            // 5. Se falhar, retornamos uma resposta JSON 422 imediatamente
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 6. Se passar, pegamos os dados validados para continuar
        $data = $validator->validated();

        // 👆 --- FIM DA MUDANÇA --- 👆


        $cpfs = $data['cpfs'];
        $tokens = is_string($cpfs)
            ? (preg_split('/[\s,;]+/u', $cpfs, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : (is_array($cpfs) ? $cpfs : []);

        $valid = [];
        $invalid = [];

        foreach ($tokens as $t) {
            $norm = Cpf::normalize((string) $t);
            if ($norm === null)
                continue;

            if (Cpf::isValid($norm))
                $valid[] = $norm;
            else
                $invalid[] = $norm;
        }

        $valid = array_values(array_unique($valid));
        $invalid = array_values(array_diff(array_unique($invalid), $valid));

        if ((count($valid) + count($invalid)) === 0) {
            return response()->json([
                'message' => 'Nenhum CPF válido ou normalizável encontrado (8–11 dígitos; 8–10 serão completados com zeros à esquerda).'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ---------- Agendamento ----------
        $tz = $data['timezone'] ?? 'America/Sao_Paulo';
        $runAt = isset($data['run_at']) ? Carbon::parse($data['run_at'], $tz) : null;
        $endAt = isset($data['end_at']) ? Carbon::parse($data['end_at'], $tz) : null;

        if ($runAt) {
            // A verificação de janela abaixo se torna desnecessária, pois a regra 'after:run_at' já cuidou disso.
            /*
            if (!$endAt || $endAt->lessThanOrEqualTo($runAt)) {
                return response()->json([
                    'message' => 'O horário final (end_at) deve ser maior que o horário inicial (run_at).'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            */

            // agenda se for futuro; se for passado, cai em execução imediata
            if ($runAt->greaterThan(Carbon::now($tz))) {
                $runAtUtc = $runAt->clone()->setTimezone('UTC');
                $endAtUtc = $endAt->clone()->setTimezone('UTC');

                $job = FgtsOfflineJob::create([
                    'user_id' => $request->user()->id,
                    'title' => $data['title'],
                    'status' => 'agendado',
                    'total_cpfs' => count($valid) + count($invalid),
                    'success_count' => 0,
                    'not_authorized_count' => 0,
                    'fail_count' => 0,
                    'scheduled_for' => $runAtUtc,
                    'scheduled_until' => $endAtUtc,
                ]);

                ProcessFgtsOfflineJob::dispatch($job->id, $request->user()->id, $job->title, $valid, $invalid)
                    ->delay($runAtUtc);

                return response()->json([
                    'id' => $job->id,
                    'status' => $job->status,
                    'scheduled_for' => $job->scheduled_for,
                    'scheduled_until' => $job->scheduled_until,
                ], Response::HTTP_ACCEPTED);
            }
        }

        // ---------- Execução imediata ----------
        $job = FgtsOfflineJob::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => 'pendente',
            'total_cpfs' => count($valid) + count($invalid),
            'success_count' => 0,
            'not_authorized_count' => 0,
            'fail_count' => 0,
            'scheduled_for' => null,
            'scheduled_until' => null,
        ]);

        ProcessFgtsOfflineJob::dispatch($job->id, $request->user()->id, $job->title, $valid, $invalid);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
        ], Response::HTTP_ACCEPTED);
    }

    /** Download do relatório FINAL */
    public function download(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        // agora permite download quando 'concluido' OU 'expirado'
        if (!in_array($job->status, ['concluido', 'expirado'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        $filename = $job->file_name ?: "fgts-offline-{$job->id}.xlsx";

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($job->file_disk);

        if (!$disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (method_exists($disk, 'download')) {
            return $disk->download($job->file_path, $filename);
        }

        $content = $disk->get($job->file_path);
        $mime = $disk->mimeType($job->file_path)
            ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content, Response::HTTP_OK, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Download da PRÉVIA (enquanto em andamento) */
    public function downloadPreview(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (empty($job->preview_disk) || empty($job->preview_path)) {
            return response()->json(['message' => 'Prévia não disponível.'], Response::HTTP_CONFLICT);
        }

        $filename = $job->preview_name ?: "fgts-offline-{$job->id}-preview.xlsx";

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($job->preview_disk);

        if (!$disk->exists($job->preview_path)) {
            return response()->json(['message' => 'Arquivo de prévia não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (method_exists($disk, 'download')) {
            return $disk->download($job->preview_path, $filename);
        }

        $content = $disk->get($job->preview_path);
        $mime = $disk->mimeType($job->preview_path)
            ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content, Response::HTTP_OK, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Cancelar job (apaga a PRÉVIA imediatamente) */
    public function cancel(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (in_array($job->status, ['concluido', 'falhou', 'cancelado', 'expirado'], true)) {
            return response()->json([
                'message' => 'Job não pode ser cancelado neste estado.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $job->update([
            'status' => 'cancelado',
            'canceled_at' => now(),
            'cancel_reason' => $data['reason'] ?? null,
        ]);

        // apaga PRÉVIA imediatamente
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar prévia no cancel (job {$job->id}): " . $e->getMessage());
        } finally {
            $job->update([
                'preview_disk' => null,
                'preview_path' => null,
                'preview_name' => null,
                'preview_updated_at' => null,
            ]);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
        ]);
    }

    /** Excluir job + arquivos (final e prévia). Bloqueia se pendente/em_progresso/agendado. */
    public function destroy(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (in_array($job->status, ['pendente', 'em_progresso', 'agendado'], true)) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job está em andamento/agendado. Cancele primeiro.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        // Apaga arquivo FINAL, se houver
        try {
            if ($job->file_disk && $job->file_path) {
                $disk = Storage::disk($job->file_disk);
                if ($disk->exists($job->file_path)) {
                    $disk->delete($job->file_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
        }

        // Apaga PRÉVIA, se ainda existir
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar arquivo de prévia (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent(); // 204
    }
}
