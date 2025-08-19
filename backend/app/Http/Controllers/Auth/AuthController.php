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
    $data = $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    if (! Auth::attempt($data)) {
        throw ValidationException::withMessages([
            'email' => ['Credenciais incorretas.'],
        ]);
    }

    // invalida antiga e cria nova
    $request->session()->regenerate();

    return response()->json([
        'user' => $request->user(),
    ]);
}

public function loginToken(Request $request)
{
    $data = $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    if (! Auth::attempt($data)) {
        throw ValidationException::withMessages([
            'email' => ['Credenciais incorretas.'],
        ]);
    }

    $user  = $request->user();
    $token = $user->createToken('postman')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user'  => $user,
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
