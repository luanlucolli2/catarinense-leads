<?php

namespace Tests\Feature\DisparosWhatsappVendeai;

use App\Support\Cpf;
use Database\Seeders\DisparosWhatsappVendeaiLeadsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DisparosWhatsappVendeaiLeadsSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_creates_the_expected_leads_and_is_idempotent(): void
    {
        $seeder = app(DisparosWhatsappVendeaiLeadsSeeder::class);
        $seeder->run();

        $leads = DB::table('leads')
            ->where('nome', 'like', DisparosWhatsappVendeaiLeadsSeeder::NAME_PREFIX.'%');

        $this->assertSame(10000, $leads->count());
        $this->assertSame(8000, (clone $leads)->whereNotNull('fone1')->count());
        $this->assertSame(2000, (clone $leads)->whereNull('fone1')->count());
        $this->assertSame(10000, (clone $leads)->distinct()->count('cpf'));

        foreach ((clone $leads)->orderBy('id')->cursor() as $lead) {
            $this->assertTrue(Cpf::isValid($lead->cpf));
        }

        $seeder->run();

        $this->assertSame(10000, $leads->count());
    }
}
