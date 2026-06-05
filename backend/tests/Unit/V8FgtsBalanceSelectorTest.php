<?php

namespace Tests\Unit;

use App\Modules\V8Fgts\Support\V8FgtsBalanceSelector;
use PHPUnit\Framework\TestCase;

class V8FgtsBalanceSelectorTest extends TestCase
{
    public function test_it_selects_the_latest_relevant_balance_after_acceptance_timestamp(): void
    {
        $items = [
            [
                'id' => 'old-success',
                'documentNumber' => '12345678901',
                'provider' => 'bms',
                'status' => 'success',
                'createdAt' => '2026-06-03T09:59:00Z',
                'updatedAt' => '2026-06-03T09:59:00Z',
            ],
            [
                'id' => 'new-fail',
                'documentNumber' => '12345678901',
                'provider' => 'bms',
                'status' => 'fail',
                'createdAt' => '2026-06-03T10:01:00Z',
                'updatedAt' => '2026-06-03T10:01:00Z',
            ],
            [
                'id' => 'new-success',
                'documentNumber' => '12345678901',
                'provider' => 'bms',
                'status' => 'success',
                'createdAt' => '2026-06-03T10:02:00Z',
                'updatedAt' => '2026-06-03T10:02:00Z',
            ],
        ];

        $selected = V8FgtsBalanceSelector::selectLatestRelevant(
            $items,
            '12345678901',
            'bms',
            '2026-06-03T10:00:00Z',
            5
        );

        $this->assertNotNull($selected);
        $this->assertSame('new-success', $selected['id']);
    }
}
