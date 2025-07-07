<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ImportController; // 1. Importar o novo controller

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rotas Públicas
Route::post('/login', [AuthController::class, 'login']);

// Rotas Protegidas (requerem autenticação)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('leads', LeadController::class);

    // 2. Adicionar a rota para o upload dos arquivos
    Route::post('/import', [ImportController::class, 'store']);
});