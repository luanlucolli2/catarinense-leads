<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Login por sessão (SPA stateful)
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'Login por sessão indisponível para este cliente.',
                'hint' => 'Use /api/login-token para fluxo stateless ou configure SANCTUM_STATEFUL_DOMAINS.',
            ], 400);
        }

        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages([
                'credentials' => ['Credenciais inválidas.'],
            ]);
        }

        // Prevenção de fixation
        $request->session()->regenerate();

        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Emissão de Personal Access Token sem criar sessão
     * Fluxo 100% stateless para tools e apps móveis.
     */
    public function loginToken(Request $request)
    {
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        // Não cria sessão
        if (! Auth::validate(['email' => $data['email'], 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'credentials' => ['Credenciais inválidas.'],
            ]);
        }

        $user = \App\Models\User::where('email', $data['email'])->first();

        $name = $data['device_name'] ?? $request->header('User-Agent') ?? 'api';
        $name = Str::limit($name, 100);

        $abilities = ['api'];

        // ---- CORREÇÃO: tratar expiration como int|null, nunca string ----
        $expirationConfig = config('sanctum.expiration'); // pode ser string, int ou null

        if ($expirationConfig === null || $expirationConfig === '' || ! is_numeric($expirationConfig)) {
            // Sem expiração configurada → tokens sem expiração (ou trate como achar melhor)
            $expiresAt = null;
        } else {
            $minutes = (int) $expirationConfig;

            // Se por algum motivo vier 0 ou negativo, também trata como sem expiração
            $expiresAt = $minutes > 0
                ? Carbon::now()->addMinutes($minutes)
                : null;
        }
        // ---------------------------------------------------------------

        $token = $user
            ->createToken($name, $abilities, $expiresAt)
            ->plainTextToken;

        return response()->json([
            'token'      => $token,
            'user'       => $user,
            'expires_at' => $expiresAt?->toIso8601String(),
            'abilities'  => $abilities,
        ]);
    }

    /**
     * Logout híbrido:
     * - Se bearer token foi usado, revoga apenas o token atual (PersonalAccessToken).
     * - Se sessão web existir, finaliza.
     */
    public function logout(Request $request)
    {
        if ($request->user()) {
            $current = $request->user()->currentAccessToken();

            // Só PersonalAccessToken possui delete(); TransientToken NÃO.
            if ($current instanceof PersonalAccessToken) {
                $current->delete();
            }
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logout efetuado.']);
    }

    /**
     * Revogar todos os tokens do usuário autenticado e encerrar sessão se houver.
     */
    public function logoutAll(Request $request)
    {
        if ($request->user()) {
            $request->user()->tokens()->delete();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Todos os dispositivos desconectados.']);
    }
}
