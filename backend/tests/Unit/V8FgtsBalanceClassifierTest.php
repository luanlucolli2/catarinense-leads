<?php

namespace Tests\Unit;

use App\Modules\V8Fgts\Support\V8FgtsBalanceClassifier;
use PHPUnit\Framework\TestCase;

class V8FgtsBalanceClassifierTest extends TestCase
{
    public function test_it_marks_documented_retryable_errors(): void
    {
        $this->assertSame(
            V8FgtsBalanceClassifier::RETRYABLE,
            V8FgtsBalanceClassifier::classify(400, 'BadRequestError', 'Tente novamente')
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::RETRYABLE,
            V8FgtsBalanceClassifier::classify(400, 'AppError', 'CPF: 1 | Não foi possível consultar o saldo no momento!')
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::RETRYABLE,
            V8FgtsBalanceClassifier::classify(400, null, 'Limite de requisições excedido, tente novamente mais tarde')
        );
    }

    public function test_it_marks_documented_business_errors_as_nao_elegivel(): void
    {
        $this->assertSame(
            V8FgtsBalanceClassifier::NAO_ELEGIVEL,
            V8FgtsBalanceClassifier::classify(400, 'AppError', 'CPF: 1 | Trabalhador não possui adesão ao saque aniversário vigente na data corrente.')
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::NAO_ELEGIVEL,
            V8FgtsBalanceClassifier::classify(
                400,
                'AppError',
                'Não foi possível consultar o saldo no momento! - Mudanças cadastrais na conta do FGTS foram realizadas, que impedem a contratação. Entre em contato com o setor de FGTS da CAIXA.'
            )
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::NAO_ELEGIVEL,
            V8FgtsBalanceClassifier::classify(400, 'BadRequestError', 'Valor da emissão inferior ao mínimo permitido')
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::NAO_ELEGIVEL,
            V8FgtsBalanceClassifier::classify(400, 'BadRequestError', 'Saldo insuficiente, parcelas menores R$100,00')
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::NAO_ELEGIVEL,
            V8FgtsBalanceClassifier::classify(400, 'AppError', 'Erro ao buscar saldo disponível no provedor')
        );

        $this->assertSame(
            V8FgtsBalanceClassifier::NAO_ELEGIVEL,
            V8FgtsBalanceClassifier::classifyPollingStatus('fail', 'Instituição Fiduciária não possui autorização do Trabalhador para Operação Fiduciária.')
        );
    }
}
