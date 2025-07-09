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
    /**
     * Armazena o arquivo enviado e despacha o job para processamento.
     */
    public function store(Request $request)
    {
        /* -----------------------------------------------------------
         | 1. Validação básica da requisição
         * -----------------------------------------------------------*/
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'type' => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $validated['file'];            /** @var UploadedFile $file */
        $type = $validated['type'];

        /* -----------------------------------------------------------
         | 2. Validação dos cabeçalhos
         * -----------------------------------------------------------*/
        $importerClass = $type === 'cadastral' ? CadastralImport::class : HigienizacaoImport::class;
        $requiredHeaders = $importerClass::REQUIRED_HEADERS;

        $missing = $this->missingHeaders($file, $requiredHeaders);

        if ($missing) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /* -----------------------------------------------------------
         | 3. Cabeçalhos OK → grava arquivo e cria ImportJob
         * -----------------------------------------------------------*/
        $path = $file->store('imports'); // disk default

        $importJob = ImportJob::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'origin' => $validated['origin'] ?? 'Upload Padrão',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'pendente',
        ]);

        /* -----------------------------------------------------------
         | 4. Despacha para fila
         * -----------------------------------------------------------*/
        ProcessLeadImportJob::dispatch($importJob);

        /* -----------------------------------------------------------
         | 5. Resposta imediata
         * -----------------------------------------------------------*/
        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id' => $importJob->id,
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
