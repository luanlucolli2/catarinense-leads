<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\LeadController; // <-- Adicione este 'use'

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rotas Públicas
Route::post('/login', [AuthController::class, 'login']);

// Rotas Protegidas (requerem autenticação)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // Nova Rota de Leads
    Route::apiResource('leads', LeadController::class);
});