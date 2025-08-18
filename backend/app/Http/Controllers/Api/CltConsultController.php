<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCltConsultJob;
use App\Models\CltConsultJob;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class CltConsultController extends Controller
{
    public function index(Request $request)
    {
        $jobs = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        return response()->json([
            'id'            => $job->id,
            'title'         => $job->title,
            'status'        => $job->status,
            'total_cpfs'    => $job->total_cpfs,
            'success_count' => $job->success_count,
            'fail_count'    => $job->fail_count,
            'has_file'      => $job->file_disk && $job->file_path,
            'started_at'    => $job->started_at,
            'finished_at'   => $job->finished_at,
            'created_at'    => $job->created_at,
        ]);
    }

    public function store(Request $request)
    {
        // Aceitar 'cpfs' como array OU 'cpfs' como string do textarea
        $data = $request->validate([
            'title' => ['required','string','max:191'],
            'cpfs'  => ['required'], // array|string
        ]);

        $cpfs = $data['cpfs'];

        if (is_string($cpfs)) {
            $tokens = preg_split('/[\s,;]+/u', $cpfs, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($cpfs)) {
            $tokens = $cpfs;
        } else {
            $tokens = [];
        }

        $valid = [];
        $invalid = [];

        foreach ($tokens as $t) {
            $norm = Cpf::normalize((string) $t);
            if ($norm === null) {
                continue; // ignora lixos (ex.: <10 ou >11 dígitos não-normalizáveis)
            }
            if (Cpf::isValid($norm)) {
                $valid[] = $norm;
            } else {
                $invalid[] = $norm;
            }
        }

        // Unicos e reindexados
        $valid = array_values(array_unique($valid));
        $invalid = array_values(array_diff(array_unique($invalid), $valid));

        if ((count($valid) + count($invalid)) === 0) {
            return response()->json([
                'message' => 'Nenhum CPF válido ou normalizável encontrado (10–11 dígitos).'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = CltConsultJob::create([
            'user_id'       => $request->user()->id,
            'title'         => $data['title'],
            'status'        => 'pendente',
            'total_cpfs'    => count($valid) + count($invalid),
            'success_count' => 0,
            'fail_count'    => 0,
        ]);

        // despacha o job (válidos vão para a API; inválidos viram linhas com mensagem)
        ProcessCltConsultJob::dispatch($job->id, $request->user()->id, $job->title, $valid, $invalid);

        return response()->json([
            'id'     => $job->id,
            'status' => $job->status,
        ], Response::HTTP_ACCEPTED);
    }

    public function download(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->status !== 'concluido' || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        $filename = $job->file_name ?: "clt-consulta-{$job->id}.xlsx";

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($job->file_disk);

        if (! $disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (method_exists($disk, 'download')) {
            return $disk->download($job->file_path, $filename);
        }

        $content = $disk->get($job->file_path);
        $mime = $disk->mimeType($job->file_path)
            ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content, Response::HTTP_OK, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
