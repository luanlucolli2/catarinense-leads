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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet; // Importe a classe Worksheet
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException; // Importe a exceção para o catch
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;

class ImportController extends Controller

    /* -----------------------------------------------------------------------
     |  POST /import  – envia arquivo e cria o Job (agora serializado)
     |-----------------------------------------------------------------------*/
{
    /**
     * Envia o arquivo, valida, cria o registro do Job e o despacha para a fila.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // 0. Impede imports concorrentes (globalmente)
        $inProgress = ImportJob::whereIn('status', ['pendente', 'em_progresso'])->exists();
        if ($inProgress) {
            return response()->json([
                'message' => 'Já existe uma importação em andamento. Aguarde a conclusão antes de iniciar outra.'
            ], Response::HTTP_CONFLICT);
        }

        // 1. Validação da requisição do Laravel
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'type' => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];

        // ======================= PONTO DE OTIMIZAÇÃO =======================
        // Carrega a planilha UMA ÚNICA VEZ em memória para todas as validações.
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
        } catch (ReaderException $e) {
            // Se o arquivo estiver corrompido ou for inválido, retorna um erro claro.
            return response()->json([
                'message' => 'Não foi possível ler o arquivo. Verifique se o formato é válido e se não está corrompido.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // =================================================================

        // 2. Validação de cabeçalhos usando a planilha já carregada
        $importerClass = $type === 'cadastral' ? CadastralImport::class : HigienizacaoImport::class;
        $requiredHeaders = $importerClass::REQUIRED_HEADERS;

        // Chama o helper modificado que recebe o objeto da planilha, não o caminho do arquivo.
        $missing = $this->getMissingHeadersFromSheet($sheet, $requiredHeaders);
        if ($missing) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3. Conta linhas de dados (já subtraindo o cabeçalho)
        $totalRows = max($sheet->getHighestDataRow() - 1, 0);

        // Libera o objeto da planilha da memória, pois não será mais usado.
        unset($spreadsheet, $sheet);

        // 4. Armazena o arquivo original e cria o registro do ImportJob no banco
        $path = $file->store('imports');

        $importJob = ImportJob::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'origin' => $validated['origin'] ?? 'Upload Padrão',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'pendente',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
        ]);

        // 5. Despacha o job para ser processado em segundo plano pela fila
        ProcessLeadImportJob::dispatch($importJob);

        // 6. Resposta 202 Accepted, informando que a tarefa foi aceita
        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id' => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Helper para verificar cabeçalhos ausentes a partir de um objeto Worksheet.
     *
     * @param Worksheet $sheet A planilha a ser verificada.
     * @param array $requiredHeaders A lista de cabeçalhos obrigatórios.
     * @return array A lista de cabeçalhos ausentes.
     */
    private function getMissingHeadersFromSheet(Worksheet $sheet, array $requiredHeaders): array
    {
        $firstRow = $sheet->rangeToArray(
            'A1:' . $sheet->getHighestColumn() . '1',
            null,
            true,
            false
        )[0] ?? []; // Adiciona ?? [] para segurança

        $normalize = fn(?string $v) => Str::of($v)->trim()->lower()->replace(' ', '')->value();
        $present = array_map($normalize, $firstRow);

        $missing = [];
        foreach ($requiredHeaders as $h) {
            if (!in_array($normalize($h), $present, true)) {
                $missing[] = $h;
            }
        }

        return $missing;
    }
    /* -----------------------------------------------------------------------
     |  GET /import/{id} – retorna progresso em tempo real
     |-----------------------------------------------------------------------*/
    public function show(ImportJob $importJob)
    {
        $errors = $importJob->errors()->count();

        $percent = $importJob->total_rows
            ? (int) floor($importJob->processed_rows / $importJob->total_rows * 100)
            : 0;

        return response()->json([
            'status' => $importJob->status,
            'processed_rows' => (int) $importJob->processed_rows,
            'total_rows' => (int) $importJob->total_rows,
            'percent' => $percent,
            'errors' => $errors,
        ]);
    }

    /* -----------------------------------------------------------------------
     |  Helpers
     |-----------------------------------------------------------------------*/
    private function missingHeaders(UploadedFile $file, array $requiredHeaders): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $firstRow = $sheet->rangeToArray(
            'A1:' . $sheet->getHighestColumn() . '1',
            null,
            true,
            false
        )[0];

        $normalize = fn($v) => Str::of($v)->trim()->lower()->replace(' ', '')->value();
        $present = array_map($normalize, $firstRow);

        $missing = [];
        foreach ($requiredHeaders as $h) {
            if (!in_array($normalize($h), $present, true)) {
                $missing[] = $h;
            }
        }

        return $missing;
    }

    public function index(Request $request)
    {
        $statuses = $request->query('status');

        $query = ImportJob::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->withCount('errors')
            ->with('user:id,name');

        if ($statuses) {
            $query->whereIn('status', explode(',', $statuses));
        }

        $jobs = $query->get([
            'id',
            'type',
            'file_name',
            'origin',
            'status',
            'total_rows',
            'processed_rows',
            'started_at',
            'finished_at',
        ]);

        return response()->json($jobs);
    }

    public function errors(ImportJob $importJob)
    {
        $errors = $importJob
            ->errors()
            ->get(['id', 'row_number', 'column_name', 'error_message']);

        return response()->json($errors);
    }

    public function exportErrors(ImportJob $importJob)
    {
        $filename = "import_job_{$importJob->id}_errors.csv";

        return response()->streamDownload(function () use ($importJob) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Linha', 'Coluna', 'Mensagem do Erro']);
            foreach ($importJob->errors()->cursor() as $err) {
                fputcsv($handle, [
                    $err->row_number,
                    $err->column_name,
                    $err->error_message,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}