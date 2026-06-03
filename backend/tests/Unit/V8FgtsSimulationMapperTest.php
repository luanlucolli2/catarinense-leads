<?php

namespace Tests\Unit;

use App\Modules\V8Fgts\Support\V8FgtsSimulationMapper;
use PHPUnit\Framework\TestCase;

class V8FgtsSimulationMapperTest extends TestCase
{
    public function test_it_maps_periods_and_selects_normal_fee(): void
    {
        $desiredInstallments = V8FgtsSimulationMapper::mapDesiredInstallments([
            ['amount' => 180.16, 'dueDate' => '2030-06-01'],
            ['amount' => 124.50, 'dueDate' => '2031-06-01'],
        ]);

        $this->assertSame([
            ['totalAmount' => 180.16, 'dueDate' => '2030-06-01'],
            ['totalAmount' => 124.5, 'dueDate' => '2031-06-01'],
        ], $desiredInstallments);

        $fee = V8FgtsSimulationMapper::selectNormalFee([
            ['active' => true, 'simulation_fees' => ['label' => 'milhas', 'id_simulation_fees' => '1']],
            ['active' => true, 'simulation_fees' => ['label' => 'normal', 'id_simulation_fees' => '2']],
        ]);

        $this->assertSame(['label' => 'normal', 'id' => '2'], $fee);
    }
}
