<?php

namespace Tests\Unit\Leads;

use App\Modules\Leads\Imports\CadastralImport;
use App\Modules\Leads\Imports\HigienizacaoImport;
use PHPUnit\Framework\TestCase;

class LeadImportHeadersTest extends TestCase
{
    public function test_cadastral_accepts_supported_header_aliases(): void
    {
        $headers = [
            'CPF Cliente', 'Nome Cliente', 'Data Nascimento', 'Fone1', 'Classe Fone 1',
            'Fone2', 'Classe Fone 2', 'Fone3', 'Classe Fone 3', 'Fone4', 'Classe Fone 4',
            'Data Contrato', 'Vendedor',
        ];

        $this->assertSame([], CadastralImport::missingRequiredHeaders($headers));
    }

    public function test_higienizacao_reports_missing_headers(): void
    {
        $this->assertSame(
            ['dataatualizacao', 'saldo', 'libera'],
            HigienizacaoImport::missingRequiredHeaders(['CPF Cliente', 'Consulta'])
        );
    }
}
