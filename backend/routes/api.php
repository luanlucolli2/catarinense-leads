<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ImportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*--------------------------------------------------
| Rotas Públicas
|--------------------------------------------------*/
Route::post('/login', [AuthController::class, 'login']);

/*--------------------------------------------------
| Rotas Protegidas (token Sanctum obrigatório)
|--------------------------------------------------*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', fn(Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    /* CRUD de Leads */
    Route::apiResource('leads', LeadController::class);

    /* Importação de planilhas */
    Route::post('/import', [ImportController::class, 'store']); // envia arquivo
    Route::get('/import/{id}', [ImportController::class, 'show'])   // consulta progresso
        ->whereNumber('id');
});
