<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportJob;
use App\Jobs\ProcessLeadImportJob; // Vamos criar este Job logo em seguida
use Illuminate\Support\Facades\Auth;

class ImportController extends Controller
{
    /**
     * Armazena o arquivo enviado e despacha o job para processamento.
     */
    public function store(Request $request)
    {
        // 1. Validação da requisição
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'], // Garante que é um arquivo Excel
            'type' => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'], // A origem textual é opcional
        ]);

        // 2. Salva o arquivo em um local temporário com um nome único
        $path = $validated['file']->store('imports');

        // 3. Cria o registro do Job no banco de dados
        $importJob = ImportJob::create([
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'origin' => $validated['origin'] ?? 'Upload Padrão', // Uma origem padrão
            'file_name' => $validated['file']->getClientOriginalName(),
            'file_path' => $path, // Guardamos o caminho do arquivo
            'status' => 'pendente',
        ]);

        // 4. Despacha o Job para a fila para ser processado em segundo plano
        ProcessLeadImportJob::dispatch($importJob);

        // 5. Retorna uma resposta imediata para o usuário
        return response()->json([
            'message' => 'Arquivo recebido. A importação foi iniciada e será processada em segundo plano.',
            'job_id' => $importJob->id,
        ], 202); // 202 Accepted é o código HTTP ideal para ações assíncronas
    }
}