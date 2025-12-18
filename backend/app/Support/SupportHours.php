<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class SupportHours
{
    /**
     * Horário de atendimento (Joinville/SC - America/Sao_Paulo):
     * - Seg a Qui: 08:00 às 18:00
     * - Sex:       08:00 às 17:00
     */
    public static function isWithinSupportHours(?Carbon $at = null): bool
    {
        $now = ($at ?: Carbon::now())->copy()->setTimezone('America/Sao_Paulo');

        $dow = (int) $now->dayOfWeekIso; // 1=Mon ... 7=Sun

        // Seg a Qui
        if ($dow >= 1 && $dow <= 4) {
            $start = $now->copy()->setTime(8, 0, 0);
            $end   = $now->copy()->setTime(18, 0, 0);

            return $now->betweenIncluded($start, $end);
        }

        // Sex
        if ($dow === 5) {
            $start = $now->copy()->setTime(8, 0, 0);
            $end   = $now->copy()->setTime(17, 0, 0);

            return $now->betweenIncluded($start, $end);
        }

        // Sáb/Dom
        return false;
    }

    public static function supportHoursText(): string
    {
        return 'Horário de atendimento (Joinville/SC): segunda a quinta, 08:00 às 18:00; sexta, 08:00 às 17:00.';
    }
}
