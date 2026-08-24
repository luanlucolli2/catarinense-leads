<?php

namespace Database\Seeders;

use App\Support\Cpf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DisparosWhatsappVendeaiLeadsSeeder extends Seeder
{
    public const NAME_PREFIX = 'Lead Seed Disparos WhatsApp VendeAI - ';

    private const TOTAL_WITH_PHONE = 8000;

    private const TOTAL_WITHOUT_PHONE = 2000;

    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        $now = now();

        $this->seedGroup(
            namePrefix: self::NAME_PREFIX.'com telefone - ',
            total: self::TOTAL_WITH_PHONE,
            cpfOffset: 100000000,
            withPhone: true,
            now: $now,
        );

        $this->seedGroup(
            namePrefix: self::NAME_PREFIX.'sem telefone - ',
            total: self::TOTAL_WITHOUT_PHONE,
            cpfOffset: 200000000,
            withPhone: false,
            now: $now,
        );
    }

    private function seedGroup(
        string $namePrefix,
        int $total,
        int $cpfOffset,
        bool $withPhone,
        Carbon $now,
    ): void {
        $leads = DB::table('leads')->where('nome', 'like', $namePrefix.'%');
        $missing = $total - $leads->count();

        if ($missing <= 0) {
            return;
        }

        $lastName = $leads->orderByDesc('nome')->value('nome');
        $candidate = $lastName === null ? 1 : ((int) substr($lastName, -8)) + 1;
        $birthDateBase = $now->copy()->subYears(68)->startOfDay();

        while ($missing > 0) {
            $rows = [];
            $batchSize = min(self::CHUNK_SIZE, $missing);

            for ($i = 0; $i < $batchSize; $i++, $candidate++) {
                $rows[] = $this->makeLead(
                    namePrefix: $namePrefix,
                    candidate: $candidate,
                    cpfOffset: $cpfOffset,
                    withPhone: $withPhone,
                    birthDateBase: $birthDateBase,
                    now: $now,
                );
            }

            $missing -= DB::table('leads')->insertOrIgnore($rows);
        }
    }

    private function makeLead(
        string $namePrefix,
        int $candidate,
        int $cpfOffset,
        bool $withPhone,
        Carbon $birthDateBase,
        Carbon $now,
    ): array {
        $fone1 = $withPhone ? '11'.'9'.str_pad((string) $candidate, 8, '0', STR_PAD_LEFT) : null;

        return [
            'cpf' => $this->makeCpf($cpfOffset + $candidate),
            'nome' => $namePrefix.str_pad((string) $candidate, 8, '0', STR_PAD_LEFT),
            'data_nascimento' => $birthDateBase->copy()->addDays($candidate % 18250)->toDateString(),
            'fone1' => $fone1,
            'classe_fone1' => $fone1 === null ? null : 'CELULAR',
            'fone2' => null,
            'classe_fone2' => null,
            'fone3' => null,
            'classe_fone3' => null,
            'fone4' => null,
            'classe_fone4' => null,
            'consulta' => 'Seed Disparos WhatsApp VendeAI',
            'data_atualizacao' => $now,
            'saldo' => null,
            'libera' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function makeCpf(int $base): string
    {
        $cpf = str_pad((string) $base, 9, '0', STR_PAD_LEFT);

        foreach ([10, 11] as $initialWeight) {
            $sum = 0;

            foreach (str_split($cpf) as $index => $digit) {
                $sum += (int) $digit * ($initialWeight - $index);
            }

            $remainder = $sum % 11;
            $cpf .= $remainder < 2 ? '0' : (string) (11 - $remainder);
        }

        if (! Cpf::isValid($cpf)) {
            throw new \LogicException('Não foi possível gerar um CPF válido para o seed.');
        }

        return $cpf;
    }
}
