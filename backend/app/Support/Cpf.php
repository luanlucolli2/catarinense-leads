<?php

namespace App\Support;

class Cpf
{
    /**
     * Normaliza um CPF:
     * - mantém apenas dígitos
     * - se tiver entre 8 e 10 dígitos, completa com zeros à esquerda até 11
     * - se tiver 11 dígitos, mantém
     * - caso contrário, retorna null
     */
    public static function normalize(?string $input): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $input);
        $len = strlen($d);

        if ($len < 8 || $len > 11) {
            return null;
        }

        if ($len < 11) {
            $d = str_pad($d, 11, '0', STR_PAD_LEFT);
        }

        return $d;
    }

    /**
     * Valida CPF pelos dígitos verificadores.
     */
    public static function isValid(string $cpf): bool
    {
        $cpf = preg_replace('/\D+/', '', $cpf);
        if (strlen($cpf) !== 11) {
            return false;
        }

        // rejeita sequências
        if (preg_match('/^(\\d)\\1{10}$/', $cpf)) {
            return false;
        }

        // DV1
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cpf[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $dv1 = $r < 2 ? 0 : 11 - $r;
        if ($dv1 !== (int) $cpf[9]) {
            return false;
        }

        // DV2
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cpf[$i] * (11 - $i);
        }
        $r = $sum % 11;
        $dv2 = $r < 2 ? 0 : 11 - $r;
        return $dv2 === (int) $cpf[10];
    }
}
