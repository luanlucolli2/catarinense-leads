<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle a login request to the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas estão incorretas.'],
            ]);
        }

        // A linha abaixo é para criar tokens para clientes de API (como mobile),
        // mas o login via SPA já cria a sessão automaticamente.
        // Não precisamos retornar um token para a SPA.
        $user = $request->user();

        return response()->json([
            'user' => $user,
            
        ]);
    }

    /**
     * Log the user out of the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // 1. Usar o "guard" de autenticação web para invalidar a sessão.
        Auth::guard('web')->logout();

        // 2. Invalidar a sessão atual para evitar que seja usada novamente.
        $request->session()->invalidate();

        // 3. Regenerar o token CSRF como medida de segurança.
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }
}
