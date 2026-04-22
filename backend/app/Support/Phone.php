<?php

namespace App\Support;

final class Phone
{
    /**
     * Normaliza telefone para o formato "DDI+DDD+NUMERO" somente dígitos.
     * Ex.: (47) 99999-0000 -> 5547999990000
     */
    public static function normalize(?string $input): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $input);
        if (!is_string($d) || $d === '') {
            return null;
        }

        // Já vem com 55?
        if (str_starts_with($d, '55')) {
            // 55 + 10/11 dígitos (DDD+numero)
            if (strlen($d) === 12 || strlen($d) === 13) {
                return $d;
            }
            // Se vier maior/menor demais, rejeita
            return null;
        }

        // Sem DDI: espera 10 ou 11 (DDD + número)
        if (strlen($d) === 10 || strlen($d) === 11) {
            return '55' . $d;
        }

        return null;
    }

    /**
     * Retorna apenas o "local" (DDD+NUMERO), sem 55.
     */
    public static function stripCountry(?string $normalized55): ?string
    {
        $d = self::normalize($normalized55);
        if (!$d) return null;

        return substr($d, 2);
    }
}
