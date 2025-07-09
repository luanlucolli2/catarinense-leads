<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportJob;
use App\Jobs\ProcessLeadImportJob;
use App\Imports\CadastralImport;
use App\Imports\HigienizacaoImport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;

class ImportController extends Controller
{
    public function store(Request $request)
    {
        /* 1. Validação básica */
        $validated = $request->validate([
            'file'   => ['required', 'file', 'mimes:xlsx,xls'],
            'type'   => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];

        /* 2. Validação de cabeçalhos */
        $importerClass   = $type === 'cadastral' ? CadastralImport::class : HigienizacaoImport::class;
        $requiredHeaders = $importerClass::REQUIRED_HEADERS;

        $missing = $this->missingHeaders($file, $requiredHeaders);

        if ($missing) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /* 3. Conta linhas de dados (para a barra de progresso) */
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet       = $spreadsheet->getActiveSheet();
        $totalRows   = max($sheet->getHighestDataRow() - 1, 0); // -1 = cabeçalho

        /* 4. Salva arquivo e cria ImportJob */
        $path = $file->store('imports');

        $importJob = ImportJob::create([
            'user_id'        => Auth::id(),
            'type'           => $type,
            'origin'         => $validated['origin'] ?? 'Upload Padrão',
            'file_name'      => $file->getClientOriginalName(),
            'file_path'      => $path,
            'status'         => 'pendente',
            'total_rows'     => $totalRows,  // 🆕
            'processed_rows' => 0,           // 🆕
        ]);

        /* 5. Despacha o Job para a fila */
        ProcessLeadImportJob::dispatch($importJob);

        /* 6. Resposta */
        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id'  => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }
    /* -----------------------------------------------------------------------
     |  🚚 Helpers
     |-----------------------------------------------------------------------*/

    /**
     * Retorna um array com os cabeçalhos ausentes na planilha enviada.
     * Se o array estiver vazio, todos os cabeçalhos obrigatórios existem.
     *
     * @param  UploadedFile $file
     * @param  array        $requiredHeaders
     * @return array
     */
    private function missingHeaders(UploadedFile $file, array $requiredHeaders): array
    {
        // Lê apenas a primeira linha (cabeçalhos) do arquivo ainda no tmp
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $firstRow = $sheet->rangeToArray(
            'A1:' . $sheet->getHighestColumn() . '1',
            null,
            true,
            false
        )[0];

        // Normaliza: trim + lowercase + remove espaços
        $normalize = fn($v) => Str::of($v)->trim()->lower()->replace(' ', '')->value();
        $present = array_map($normalize, $firstRow);

        // Checa quais obrigatórios não aparecem
        $missing = [];
        foreach ($requiredHeaders as $h) {
            if (!in_array($normalize($h), $present, true)) {
                $missing[] = $h;
            }
        }

        return $missing;
    }
}
