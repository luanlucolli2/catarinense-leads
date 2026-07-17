<?php

namespace Tests\Unit\Leads;

use App\Modules\Leads\Filters\LeadFilter;
use Illuminate\Http\Request;
use Tests\TestCase;

class LeadFilter360Test extends TestCase
{
    public function test_unfiltered_id_query_does_not_join_snapshot_tables(): void
    {
        $sql = $this->sql(['mode' => '360'], true);

        $this->assertStringNotContainsString('facta_clt_snapshots', $sql);
        $this->assertStringNotContainsString('mercantil_snapshots', $sql);
        $this->assertStringNotContainsString('uy3_snapshots', $sql);
        $this->assertStringNotContainsString('fgts_off_snapshots', $sql);
        $this->assertStringContainsString('order by leads.updated_at desc, leads.id desc', $sql);
    }

    public function test_id_query_joins_only_selected_banks(): void
    {
        $sql = $this->sql([
            'mode' => '360',
            'selected_banks' => ['facta', 'uy3'],
            'bank_combination_mode' => 'any',
        ], true);

        $this->assertStringContainsString('facta_clt_snapshots', $sql);
        $this->assertStringContainsString('uy3_snapshots', $sql);
        $this->assertStringNotContainsString('mercantil_snapshots', $sql);
        $this->assertStringNotContainsString('fgts_off_snapshots', $sql);
        $this->assertStringContainsString('(cs.cpf is not null) or (us.cpf is not null)', $sql);
    }

    public function test_all_bank_combination_uses_and(): void
    {
        $sql = $this->sql([
            'mode' => '360',
            'selected_banks' => ['facta', 'mercantil'],
            'bank_combination_mode' => 'all',
        ], true);

        $this->assertStringContainsString('(cs.cpf is not null) and (ms.cpf is not null)', $sql);
    }

    public function test_hydration_joins_all_sources_without_reapplying_filters(): void
    {
        $request = new Request([
            'mode' => '360',
            'selected_banks' => ['facta'],
            'search' => 'Maria',
        ]);
        $query = LeadFilter::apply($request, null, false, false);
        $sql = $this->normalize($query->toSql());

        $this->assertStringContainsString('facta_clt_snapshots', $sql);
        $this->assertStringContainsString('mercantil_snapshots', $sql);
        $this->assertStringContainsString('uy3_snapshots', $sql);
        $this->assertStringContainsString('fgts_off_snapshots', $sql);
        $this->assertNotContains('Maria%', $query->getBindings());
    }

    public function test_360_search_uses_exact_numeric_and_name_prefix_bindings(): void
    {
        $numeric = LeadFilter::apply(new Request([
            'mode' => '360',
            'search' => '(48) 99999-9999',
        ]), null, true);
        $name = LeadFilter::apply(new Request([
            'mode' => '360',
            'search' => 'Maria Silva',
        ]), null, true);

        $this->assertContains('48999999999', $numeric->getBindings());
        $this->assertNotContains('%48999999999%', $numeric->getBindings());
        $this->assertContains('Maria Silva%', $name->getBindings());
        $this->assertNotContains('%Maria Silva%', $name->getBindings());
    }

    public function test_360_phone_presence_uses_generated_indexed_column(): void
    {
        $withPhones = $this->sql(['mode' => '360', 'with_phones' => true], true);
        $withoutPhones = $this->sql(['mode' => '360', 'without_phones' => true], true);

        $this->assertStringContainsString('leads.has_phone = ?', $withPhones);
        $this->assertStringContainsString('leads.has_phone = ?', $withoutPhones);
        $this->assertStringNotContainsString('trim(leads.fone1)', $withPhones);
    }

    private function sql(array $input, bool $idsOnly): string
    {
        return $this->normalize(LeadFilter::apply(new Request($input), null, $idsOnly)->toSql());
    }

    private function normalize(string $sql): string
    {
        return strtolower(str_replace(['`', '"'], '', $sql));
    }
}
