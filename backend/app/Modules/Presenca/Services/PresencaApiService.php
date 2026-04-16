<?php

namespace App\Modules\Presenca\Services;

use App\Modules\Presenca\Support\PresencaLog;
use App\Modules\Presenca\Support\PresencaSchema;
use App\Support\Cpf;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class PresencaApiService
{
    private const VALID_BR_DDDS = [
        '11', '12', '13', '14', '15', '16', '17', '18', '19',
        '21', '22', '24',
        '27', '28',
        '31', '32', '33', '34', '35', '37', '38',
        '41', '42', '43', '44', '45', '46',
        '47', '48', '49',
        '51', '53', '54', '55',
        '61', '62', '63', '64', '65', '66', '67', '68', '69',
        '71', '73', '74', '75', '77', '79',
        '81', '82', '83', '84', '85', '86', '87', '88', '89',
        '91', '92', '93', '94', '95', '96', '97', '98', '99',
    ];

    private string $baseUrl;
    private ?string $login;
    private ?string $senha;
    private string $tenantId;
    private int $produtoId;
    private int $tokenTtlFallbackSeconds;
    private int $tokenTtlSkewSeconds;
    private int $tokenLockTtl;
    private int $tokenLockWait;

    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetryAttempts;
    private int $httpRetryBaseDelayMs;
    private int $httpRetryMaxDelayMs;
    private int $default429DelaySeconds;

    private bool $rateLimitEnabled;
    private int $rateMinIntervalMs;
    private int $rateMaxRequestsPerMinute;
    private int $rateLockTtl;
    private int $rateLockWait;

    private int $loginRequestAttempts;
    private int $termoRequestAttempts;
    private int $authorizationRequestAttempts;
    private int $vinculosRequestAttempts;
    private int $margemRequestAttempts;
    private int $simulacaoRequestAttempts;

    private int $simRetryAttempts;
    private int $simRetryDelaySeconds;
    private string $simEmailDomain;

    private int $termoPhoneRetryAttempts;
    private int $authorizationReuseTtlSeconds;
    private int $authorizationLocalCacheMax;
    private int $authorizationWarmupBatchSize;

    private bool $logApiResponses;
    private bool $logApiSuccessResponses;
    private bool $logApi429;

    private ?int $jobId = null;
    private ?string $currentCpf = null;
    private ?string $fatalAuthMessage = null;
    private ?string $lastRequestFailureType = null;
    private ?string $lastRequestFailurePath = null;

    /** @var array<string,float> cpf => expires_ts (0.0 para miss conhecido) */
    private array $authorizationLocalState = [];
    /** @var array<string,bool> LRU keys (ordem de inserção/acesso) */
    private array $authorizationLocalOrder = [];

    public function __construct()
    {
        $api = (array) config('presenca.api', []);
        $http = (array) config('presenca.http', []);
        $rate = (array) config('presenca.rate_limit', []);
        $requestRetries = (array) config('presenca.request_retries', []);
        $simulacao = (array) config('presenca.simulacao', []);
        $termo = (array) config('presenca.termo', []);
        $authorization = (array) config('presenca.authorization', []);
        $logging = (array) config('presenca.logging', []);

        $this->baseUrl = rtrim((string) ($api['base_url'] ?? ''), '/');
        $this->login = isset($api['login']) ? (string) $api['login'] : null;
        $this->senha = isset($api['senha']) ? (string) $api['senha'] : null;
        $this->tenantId = (string) ($api['tenant_id'] ?? 'superuser');
        $this->produtoId = max(1, (int) ($api['produto_id'] ?? 28));
        $this->tokenTtlFallbackSeconds = max(60, (int) ($api['token_ttl_fallback_seconds'] ?? 3300));
        $this->tokenTtlSkewSeconds = max(0, (int) ($api['token_ttl_skew_seconds'] ?? 30));
        $this->tokenLockTtl = max(1, (int) ($api['token_lock_ttl'] ?? 10));
        $this->tokenLockWait = max(1, (int) ($api['token_lock_wait'] ?? 5));

        $this->httpTimeout = max(1, (int) ($http['timeout'] ?? 30));
        $this->httpConnectTimeout = max(1, (int) ($http['connect_timeout'] ?? 10));
        $this->httpRetryAttempts = max(1, min(2, (int) ($http['retry_attempts'] ?? 2)));
        $this->httpRetryBaseDelayMs = max(200, (int) ($http['retry_base_delay_ms'] ?? 1000));
        $this->httpRetryMaxDelayMs = max($this->httpRetryBaseDelayMs, (int) ($http['retry_max_delay_ms'] ?? 12000));
        $this->default429DelaySeconds = max(1, (int) ($http['default_429_delay_seconds'] ?? 3));

        $this->rateLimitEnabled = (bool) ($rate['enabled'] ?? true);
        $this->rateMinIntervalMs = max(0, (int) ($rate['min_interval_ms'] ?? 2000));
        $this->rateMaxRequestsPerMinute = max(0, (int) ($rate['max_requests_per_minute'] ?? 30));
        $this->rateLockTtl = max(1, (int) ($rate['lock_ttl'] ?? 10));
        $this->rateLockWait = max(1, (int) ($rate['lock_wait'] ?? 5));

        $this->loginRequestAttempts = max(1, (int) ($requestRetries['login_attempts'] ?? 2));
        $this->termoRequestAttempts = max(1, (int) ($requestRetries['termo_attempts'] ?? 3));
        $this->authorizationRequestAttempts = max(1, (int) ($requestRetries['authorization_attempts'] ?? 3));
        $this->vinculosRequestAttempts = max(1, (int) ($requestRetries['vinculos_attempts'] ?? 3));
        $this->margemRequestAttempts = max(1, (int) ($requestRetries['margem_attempts'] ?? 3));
        $this->simulacaoRequestAttempts = max(1, (int) ($requestRetries['simulacao_attempts'] ?? 3));

        $this->simRetryAttempts = max(1, (int) ($simulacao['retry_attempts'] ?? 12));
        $this->simRetryDelaySeconds = max(1, (int) ($simulacao['retry_delay_seconds'] ?? 3));
        $this->simEmailDomain = (string) ($simulacao['email_domain'] ?? 'example.com');

        $this->termoPhoneRetryAttempts = max(1, (int) ($termo['phone_retry_attempts'] ?? 5));
        $this->authorizationReuseTtlSeconds = max(0, (int) ($authorization['reuse_ttl_seconds'] ?? 172800));
        $this->authorizationLocalCacheMax = max(0, (int) ($authorization['local_cache_max'] ?? 5000));
        $this->authorizationWarmupBatchSize = max(1, (int) ($authorization['warmup_batch_size'] ?? 500));

        $this->logApiResponses = (bool) ($logging['api_log_responses'] ?? true);
        $this->logApiSuccessResponses = (bool) ($logging['api_log_success_responses'] ?? false);
        $this->logApi429 = (bool) ($logging['api_log_429'] ?? true);
    }

    public function setJobId(?int $jobId): void
    {
        $this->jobId = $jobId;
    }

    /** @param array<int,string> $cpfs */
    public function warmReusableAuthorizations(array $cpfs): void
    {
        if ($this->authorizationReuseTtlSeconds <= 0 || $this->authorizationLocalCacheMax <= 0 || empty($cpfs)) {
            return;
        }

        $normalized = [];
        foreach ($cpfs as $cpf) {
            $digits = Cpf::normalize((string) $cpf);
            if ($digits !== null && $digits !== '') {
                $normalized[] = $digits;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if (empty($normalized)) {
            return;
        }

        $nowTs = microtime(true);
        $toLookup = [];
        foreach ($normalized as $cpf) {
            $cached = $this->getAuthorizationLocalState($cpf, $nowTs);
            if ($cached === null) {
                $toLookup[] = $cpf;
            }
        }

        if (empty($toLookup)) {
            return;
        }

        $nowUtc = now('UTC')->format('Y-m-d H:i:s');
        $batchSize = max(1, $this->authorizationWarmupBatchSize);

        try {
            for ($offset = 0, $total = count($toLookup); $offset < $total; $offset += $batchSize) {
                $chunk = array_slice($toLookup, $offset, $batchSize);
                if (empty($chunk)) {
                    continue;
                }

                $rows = DB::table('presenca_authorizations')
                    ->whereIn('cpf', $chunk)
                    ->where('expires_at', '>', $nowUtc)
                    ->select('cpf', 'expires_at')
                    ->get();

                $found = [];
                foreach ($rows as $row) {
                    $cpf = (string) ($row->cpf ?? '');
                    if ($cpf === '') {
                        continue;
                    }

                    $expiresAtTs = strtotime((string) ($row->expires_at ?? ''));
                    if (!is_int($expiresAtTs) || $expiresAtTs <= 0) {
                        continue;
                    }

                    $found[$cpf] = true;
                    $this->putAuthorizationLocalState($cpf, (float) $expiresAtTs);
                }

                foreach ($chunk as $cpf) {
                    if (!isset($found[$cpf])) {
                        $this->putAuthorizationLocalState($cpf, 0.0);
                    }
                }
            }
        } catch (Throwable $e) {
            PresencaLog::warning('[PRESENCA] Falha ao aquecer cache de autorização.', $this->logContext([
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * @return array{outcome:'success'|'policy_declined'|'failed',row:array<string,mixed>}
     */
    public function consultarCpf(string $cpf, string $nome): array
    {
        $cpf = Cpf::normalize($cpf);
        $nome = $this->normalizeName($nome);
        $this->currentCpf = $cpf;

        $row = $this->baseRow($cpf ?? '', $nome);

        if (!$cpf || $nome === '') {
            $row['status'] = 'FALHA';
            $row['status_code'] = 'INPUT';
            $row['mensagem'] = 'CPF ou nome inválido para processamento.';
            return ['outcome' => 'failed', 'row' => $row];
        }

        if ($this->fatalAuthMessage !== null) {
            $row['status'] = 'FALHA';
            $row['status_code'] = 'LOGIN_401';
            $row['mensagem'] = $this->fatalAuthMessage;
            return ['outcome' => 'failed', 'row' => $row];
        }

        $phoneContext = $this->generatePhoneContext();
        $authorizationReused = $this->hasReusableAuthorization($cpf);

        if (!$authorizationReused) {
            $termoData = null;
            $lastTermoMessage = null;

            for ($attempt = 1; $attempt <= $this->termoPhoneRetryAttempts; $attempt++) {
                if ($attempt > 1) {
                    $phoneContext = $this->generatePhoneContext();
                }

                $termoResp = $this->requestJsonWithStageRetries(
                    'POST',
                    '/consultas/termo-inss',
                    [
                        'cpf' => $cpf,
                        'nome' => $nome,
                        'telefone' => $phoneContext['telefone'],
                        'produtoId' => $this->produtoId,
                    ],
                    [],
                    true,
                    $this->termoRequestAttempts,
                    static fn (HttpResponse $response): bool => $response->status() >= 500,
                    '[PRESENCA] Geração de termo com falha temporária; nova tentativa via throttle global.'
                );

                if (!$termoResp) {
                    $failure = $this->describeLastRequestFailure(
                        'TERMO_NO_RESPONSE',
                        'Sem resposta ao gerar termo de autorização.'
                    );
                    $row['status'] = 'FALHA';
                    $row['status_code'] = $failure['status_code'];
                    $row['mensagem'] = $failure['message'];
                    return ['outcome' => 'failed', 'row' => $row];
                }

                if ($termoResp->status() === 200) {
                    $json = $termoResp->json();
                    if (!is_array($json) || empty($json['autorizacaoId'])) {
                        $row['status'] = 'FALHA';
                        $row['status_code'] = 'TERMO_INVALID_BODY';
                        $row['mensagem'] = 'Resposta inválida ao gerar termo.';
                        return ['outcome' => 'failed', 'row' => $row];
                    }

                    $termoData = [
                        'autorizacaoId' => (string) $json['autorizacaoId'],
                        'shortUrl' => isset($json['shortUrl']) ? (string) $json['shortUrl'] : null,
                    ];
                    break;
                }

                if ($termoResp->status() === 400) {
                    $messages = $this->extractMessages($termoResp);
                    if ($this->containsTelefoneJaUtilizado($messages)) {
                        $lastTermoMessage = $this->joinMessages($messages, 'Telefone já utilizado em termo para outro cliente.');
                        continue;
                    }

                    $row['status'] = 'FALHA';
                    $row['status_code'] = 'TERMO_400';
                    $row['mensagem'] = $this->joinMessages($messages, 'Erro ao gerar termo de autorização.');
                    return ['outcome' => 'failed', 'row' => $row];
                }

                $row['status'] = 'FALHA';
                $row['status_code'] = 'TERMO_' . $termoResp->status();
                $row['mensagem'] = $this->joinMessages($this->extractMessages($termoResp), 'Erro ao gerar termo de autorização.');
                return ['outcome' => 'failed', 'row' => $row];
            }

            if (!$termoData) {
                $row['status'] = 'FALHA';
                $row['status_code'] = 'TERMO_PHONE_CONFLICT';
                $row['mensagem'] = $lastTermoMessage ?: 'Não foi possível gerar termo após troca de telefone.';
                return ['outcome' => 'failed', 'row' => $row];
            }

            $autorizacaoResp = $this->requestJsonWithStageRetries(
                'PUT',
                '/consultas/termo-inss/' . rawurlencode($termoData['autorizacaoId']),
                $this->authorizationPayload($phoneContext),
                ['tenant-id' => $this->tenantId],
                true,
                $this->authorizationRequestAttempts,
                fn (HttpResponse $response): bool => $response->status() >= 500
                    && !($response->status() === 500
                        && $this->isKnownAuthorization500($this->extractMessages($response))),
                '[PRESENCA] Autorização com falha temporária; nova tentativa via throttle global.'
            );

            if (!$autorizacaoResp) {
                $failure = $this->describeLastRequestFailure(
                    'AUTORIZACAO_NO_RESPONSE',
                    'Sem resposta na autorização do termo.'
                );
                $row['status'] = 'FALHA';
                $row['status_code'] = $failure['status_code'];
                $row['mensagem'] = $failure['message'];
                return ['outcome' => 'failed', 'row' => $row];
            }

            if ($autorizacaoResp->status() !== 200) {
                $messages = $this->extractMessages($autorizacaoResp);

                if ($autorizacaoResp->status() === 500 && $this->isKnownAuthorization500($messages)) {
                    // fluxo segue normalmente
                } elseif ($autorizacaoResp->status() === 400 && $this->isInvalidTermId($messages)) {
                    $row['status'] = 'FALHA';
                    $row['status_code'] = 'AUTORIZACAO_TERMO_INVALIDO';
                    $row['mensagem'] = $this->joinMessages($messages, 'Termo inválido para autorização.');
                    return ['outcome' => 'failed', 'row' => $row];
                } else {
                    $row['status'] = 'FALHA';
                    $row['status_code'] = 'AUTORIZACAO_' . $autorizacaoResp->status();
                    $row['mensagem'] = $this->joinMessages($messages, 'Erro ao autorizar termo.');
                    return ['outcome' => 'failed', 'row' => $row];
                }
            }

            $this->markAuthorizationGrant($cpf);
        } else {
            PresencaLog::warning(
                '[PRESENCA] Autorização reaproveitada do banco; pulando termo/autorização.',
                $this->logContext()
            );
        }

        $vinculosResp = $this->requestJsonWithStageRetries(
            'POST',
            '/v3/operacoes/consignado-privado/consultar-vinculos',
            ['cpf' => $cpf],
            [],
            true,
            $this->vinculosRequestAttempts,
            static fn (HttpResponse $response): bool => $response->status() >= 500,
            '[PRESENCA] Consulta de vínculos com falha temporária; nova tentativa via throttle global.'
        );

        if (!$vinculosResp) {
            $failure = $this->describeLastRequestFailure(
                'VINCULOS_NO_RESPONSE',
                'Sem resposta na consulta de vínculos.'
            );
            $row['status'] = 'FALHA';
            $row['status_code'] = $failure['status_code'];
            $row['mensagem'] = $failure['message'];
            return ['outcome' => 'failed', 'row' => $row];
        }

        if ($vinculosResp->status() !== 200) {
            $messages = $this->extractMessages($vinculosResp);
            $row['status'] = 'FALHA';
            $row['status_code'] = 'VINCULOS_' . $vinculosResp->status();
            $row['mensagem'] = $this->joinMessages($messages, 'Erro ao consultar vínculos empregatícios.');
            return ['outcome' => 'failed', 'row' => $row];
        }

        $vinculos = $this->extractVinculos($vinculosResp->json());
        if (empty($vinculos)) {
            $row['status'] = 'FALHA';
            $row['status_code'] = 'VINCULOS_EMPTY';
            $row['mensagem'] = 'Nenhum vínculo retornado para o CPF.';
            return ['outcome' => 'failed', 'row' => $row];
        }

        $selectedVinculo = $this->selectVinculo($vinculos);
        $matricula = (string) ($selectedVinculo['matricula'] ?? '');
        $numeroInscricaoEmpregador = (string) ($selectedVinculo['numeroInscricaoEmpregador'] ?? '');

        if ($matricula === '' || $numeroInscricaoEmpregador === '') {
            $row['status'] = 'FALHA';
            $row['status_code'] = 'VINCULOS_INVALID';
            $row['mensagem'] = 'Vínculo retornado sem matrícula ou inscrição do empregador.';
            return ['outcome' => 'failed', 'row' => $row];
        }

        $row['vinculo_elegivel'] = $this->toBoolString($selectedVinculo['elegivel'] ?? null);

        $margemResp = $this->consultarMargemComRetry($cpf, $matricula, $numeroInscricaoEmpregador);

        if (!$margemResp) {
            $failure = $this->describeLastRequestFailure(
                'MARGEM_NO_RESPONSE',
                'Sem resposta na consulta de margem.'
            );
            $row['status'] = 'FALHA';
            $row['status_code'] = $failure['status_code'];
            $row['mensagem'] = $failure['message'];
            return ['outcome' => 'failed', 'row' => $row];
        }

        if ($margemResp->status() !== 200) {
            $messages = $this->extractMessages($margemResp);
            $row['status'] = 'FALHA';
            $row['status_code'] = 'MARGEM_' . $margemResp->status();
            $row['mensagem'] = $this->joinMessages($messages, 'Erro ao consultar margem.');
            return ['outcome' => 'failed', 'row' => $row];
        }

        $margem = $margemResp->json();
        if (!is_array($margem)) {
            $row['status'] = 'FALHA';
            $row['status_code'] = 'MARGEM_INVALID_BODY';
            $row['mensagem'] = 'Resposta inválida na consulta de margem.';
            return ['outcome' => 'failed', 'row' => $row];
        }

        $row['margem_valor_disponivel'] = $this->toMoneyString($margem['valorMargemDisponivel'] ?? null);
        $row['margem_valor_base'] = $this->toMoneyString($margem['valorMargemBase'] ?? null);
        $row['margem_valor_total_devido'] = $this->toMoneyString($margem['valorTotalDevido'] ?? null);
        $row['margem_registro_empregaticio'] = isset($margem['registroEmpregaticio']) ? (string) $margem['registroEmpregaticio'] : null;
        $row['margem_cnpj_empregador'] = isset($margem['cnpjEmpregador']) ? (string) $margem['cnpjEmpregador'] : null;
        $row['margem_data_admissao'] = isset($margem['dataAdmissao']) ? (string) $margem['dataAdmissao'] : null;
        $row['margem_data_nascimento'] = isset($margem['dataNascimento']) ? (string) $margem['dataNascimento'] : null;
        $row['margem_nome_mae'] = isset($margem['nomeMae']) ? (string) $margem['nomeMae'] : null;
        $row['margem_sexo'] = isset($margem['sexo']) ? strtoupper((string) $margem['sexo']) : null;

        $simulacaoResult = $this->consultarSimulacaoDisponivel($cpf, $nome, $phoneContext, $margem);
        if ($simulacaoResult['outcome'] === 'success') {
            $simulacao = $simulacaoResult['simulacao'];
            $row['simulacao_id'] = isset($simulacao['id']) ? (string) $simulacao['id'] : null;
            $row['simulacao_nome'] = isset($simulacao['nome']) ? (string) $simulacao['nome'] : null;
            $row['simulacao_prazo'] = isset($simulacao['prazo']) ? (string) $simulacao['prazo'] : null;
            $row['simulacao_taxa_juros'] = $this->toMoneyString($simulacao['taxaJuros'] ?? null);
            $row['simulacao_valor_liberado'] = $this->toMoneyString($simulacao['valorLiberado'] ?? null);
            $row['simulacao_valor_parcela'] = $this->toMoneyString($simulacao['valorParcela'] ?? null);
            $row['simulacao_tipo_credito'] = isset($simulacao['tipoCredito']['name']) ? (string) $simulacao['tipoCredito']['name'] : null;
            $row['simulacao_type'] = isset($simulacao['type']) ? (string) $simulacao['type'] : null;
            $row['simulacao_taxa_seguro'] = $this->toMoneyString($simulacao['taxaSeguro'] ?? null);
            $row['simulacao_valor_seguro'] = $this->toMoneyString($simulacao['valorSeguro'] ?? null);

            $row['status'] = 'SUCESSO';
            $row['status_code'] = '200';
            $row['mensagem'] = 'Simulação disponível.';
            return ['outcome' => 'success', 'row' => $row];
        }

        if ($simulacaoResult['outcome'] === 'policy_declined') {
            $row['status'] = 'RECUSA_POLITICA';
            $row['status_code'] = (string) ($simulacaoResult['status_code'] ?? '400');
            $row['mensagem'] = (string) ($simulacaoResult['message'] ?? 'Recusa por política de crédito.');
            return ['outcome' => 'policy_declined', 'row' => $row];
        }

        $row['status'] = 'FALHA';
        $row['status_code'] = (string) ($simulacaoResult['status_code'] ?? 'SIMULACAO_ERROR');
        $row['mensagem'] = (string) ($simulacaoResult['message'] ?? 'Falha na simulação.');
        return ['outcome' => 'failed', 'row' => $row];
    }

    private function baseRow(string $cpf, string $nome): array
    {
        $row = array_fill_keys(PresencaSchema::COLS, null);
        $row['cpf'] = $cpf;
        $row['nome'] = $nome;
        $row['consulted_at'] = now('America/Sao_Paulo')->format('d/m/Y H:i:s');

        return $row;
    }

    private function authorizationPayload(array $phoneContext): array
    {
        return [
            'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'OperationalSystem' => 'Linux',
            'DeviceModel' => 'Server Worker',
            'DeviceName' => 'presenca-integration',
            'DeviceType' => 'Desktop',
            'GeoLocation' => [
                'Latitude' => (string) $phoneContext['latitude'],
                'Longitude' => (string) $phoneContext['longitude'],
            ],
        ];
    }

    /**
     * @return array{outcome:'success'|'policy_declined'|'failed',simulacao?:array<string,mixed>,status_code?:int|string,message?:string}
     */
    private function consultarSimulacaoDisponivel(string $cpf, string $nome, array $phoneContext, array $margem): array
    {
        $email = 'cliente' . $cpf . '@' . ltrim($this->simEmailDomain, '@');
        $valorParcela = $this->toFloat($margem['valorMargemDisponivel'] ?? null) ?? 0.0;

        $payload = [
            'tomador' => [
                'telefone' => [
                    'ddd' => $phoneContext['ddd'],
                    'numero' => $phoneContext['numero'],
                ],
                'cpf' => $cpf,
                'nome' => $nome,
                'dataNascimento' => isset($margem['dataNascimento']) ? (string) $margem['dataNascimento'] : null,
                'nomeMae' => isset($margem['nomeMae']) ? (string) $margem['nomeMae'] : null,
                'email' => $email,
                'sexo' => isset($margem['sexo']) ? strtoupper((string) $margem['sexo']) : 'M',
                'vinculoEmpregaticio' => [
                    'cnpjEmpregador' => isset($margem['cnpjEmpregador']) ? (string) $margem['cnpjEmpregador'] : null,
                    'registroEmpregaticio' => isset($margem['registroEmpregaticio']) ? (string) $margem['registroEmpregaticio'] : null,
                ],
                'dadosBancarios' => [
                    'codigoBanco' => null,
                    'agencia' => null,
                    'conta' => null,
                    'digitoConta' => null,
                    'formaCredito' => null,
                ],
                'endereco' => [
                    'cep' => '',
                    'rua' => '',
                    'numero' => '',
                    'complemento' => '',
                    'cidade' => '',
                    'estado' => '',
                    'bairro' => '',
                ],
            ],
            'proposta' => [
                'valorSolicitado' => 0,
                'quantidadeParcelas' => 0,
                'produtoId' => $this->produtoId,
                'valorParcela' => $valorParcela,
            ],
            'documentos' => [],
        ];

        for ($attempt = 1; $attempt <= $this->simRetryAttempts; $attempt++) {
            $resp = $this->requestJsonWithStageRetries(
                'POST',
                '/v5/operacoes/simulacao/disponiveis',
                $payload,
                [],
                true,
                $this->simulacaoRequestAttempts,
                static fn (HttpResponse $response): bool => $response->status() >= 500,
                '[PRESENCA] Simulação com falha temporária de transporte; nova tentativa via throttle global.'
            );

            if (!$resp) {
                $failure = $this->describeLastRequestFailure(
                    'SIMULACAO_NO_RESPONSE',
                    'Sem resposta na consulta de simulação.'
                );
                return [
                    'outcome' => 'failed',
                    'status_code' => $failure['status_code'],
                    'message' => $failure['message'],
                ];
            }

            if ($resp->status() === 200) {
                $first = $this->extractFirstSimulation($resp->json());
                if (!$first) {
                    return [
                        'outcome' => 'failed',
                        'status_code' => 'SIMULACAO_EMPTY',
                        'message' => 'Simulação retornou sem itens disponíveis.',
                    ];
                }

                return [
                    'outcome' => 'success',
                    'simulacao' => $first,
                ];
            }

            if ($resp->status() === 400) {
                $messages = $this->extractMessages($resp);
                if ($this->containsFalhaSimulacaoTemporaria($messages)) {
                    if ($attempt < $this->simRetryAttempts) {
                        PresencaLog::warning(
                            '[PRESENCA] Simulação com falha temporária; nova tentativa via throttle global.',
                            $this->logContext([
                                'attempt' => $attempt,
                                'max_attempts' => $this->simRetryAttempts,
                                'messages' => $messages,
                            ])
                        );
                        continue;
                    }

                    return [
                        'outcome' => 'failed',
                        'status_code' => 400,
                        'message' => $this->joinMessages($messages, 'Falha temporária ao realizar simulação.'),
                    ];
                }

                return [
                    'outcome' => 'policy_declined',
                    'status_code' => 400,
                    'message' => $this->joinMessages($messages, 'Recusa por política de crédito.'),
                ];
            }

            return [
                'outcome' => 'failed',
                'status_code' => $resp->status(),
                'message' => $this->joinMessages($this->extractMessages($resp), 'Erro na consulta de simulação.'),
            ];
        }

        return [
            'outcome' => 'failed',
            'status_code' => 'SIMULACAO_MAX_ATTEMPTS',
            'message' => 'Limite de tentativas da simulação atingido.',
        ];
    }

    private function consultarMargemComRetry(string $cpf, string $matricula, string $cnpj): ?HttpResponse
    {
        for ($attempt = 1; $attempt <= $this->margemRequestAttempts; $attempt++) {
            $resp = $this->requestJson('POST', '/v3/operacoes/consignado-privado/consultar-margem', [
                'cpf' => $cpf,
                'matricula' => $matricula,
                'cnpj' => $cnpj,
            ]);

            if ($resp && $resp->status() < 500) {
                return $resp;
            }

            if ($attempt < $this->margemRequestAttempts) {
                PresencaLog::warning(
                    '[PRESENCA] Consulta de margem com falha temporária; nova tentativa via throttle global.',
                    $this->logContext([
                        'attempt' => $attempt,
                        'max_attempts' => $this->margemRequestAttempts,
                        'status' => $resp?->status(),
                        'messages' => $resp ? $this->extractMessages($resp) : null,
                        'failure_type' => $resp ? null : $this->lastRequestFailureType,
                        'failure_path' => $resp ? null : $this->lastRequestFailurePath,
                    ])
                );
            }

            if ($resp && $resp->status() >= 500 && $attempt >= $this->margemRequestAttempts) {
                return $resp;
            }
        }

        return null;
    }

    private function generatePhoneContext(): array
    {
        $ddd = self::VALID_BR_DDDS[array_rand(self::VALID_BR_DDDS)];
        $numero = '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        [$baseLat, $baseLon] = $this->baseGeoForDdd($ddd);

        $latitude = $baseLat + $this->randomFloat(-0.35, 0.35);
        $longitude = $baseLon + $this->randomFloat(-0.35, 0.35);

        return [
            'ddd' => $ddd,
            'numero' => $numero,
            'telefone' => $ddd . $numero,
            'latitude' => number_format($latitude, 6, '.', ''),
            'longitude' => number_format($longitude, 6, '.', ''),
        ];
    }

    /** @return array{0:float,1:float} */
    private function baseGeoForDdd(string $ddd): array
    {
        if (in_array($ddd, ['11', '12', '13', '14', '15', '16', '17', '18', '19'], true)) {
            return [-23.550520, -46.633308]; // SP
        }

        if (in_array($ddd, ['21', '22', '24'], true)) {
            return [-22.906847, -43.172896]; // RJ
        }

        if (in_array($ddd, ['27', '28'], true)) {
            return [-20.315500, -40.312800]; // ES
        }

        if (in_array($ddd, ['31', '32', '33', '34', '35', '37', '38'], true)) {
            return [-19.916681, -43.934493]; // MG
        }

        if (in_array($ddd, ['41', '42', '43', '44', '45', '46'], true)) {
            return [-25.428954, -49.267137]; // PR
        }

        if (in_array($ddd, ['47', '48', '49'], true)) {
            return [-27.594870, -48.548220]; // SC
        }

        if (in_array($ddd, ['51', '53', '54', '55'], true)) {
            return [-30.034647, -51.217658]; // RS
        }

        if ($ddd === '61') {
            return [-15.793889, -47.882778]; // DF
        }

        if (in_array($ddd, ['62', '63', '64'], true)) {
            return [-16.686882, -49.264790]; // GO/TO
        }

        if (in_array($ddd, ['65', '66'], true)) {
            return [-15.601000, -56.097400]; // MT
        }

        if ($ddd === '67') {
            return [-20.469710, -54.620121]; // MS
        }

        if ($ddd === '68') {
            return [-9.975377, -67.824897]; // AC
        }

        if ($ddd === '69') {
            return [-8.760773, -63.899900]; // RO
        }

        if (in_array($ddd, ['71', '73', '74', '75', '77'], true)) {
            return [-12.971400, -38.501400]; // BA
        }

        if ($ddd === '79') {
            return [-10.947200, -37.073100]; // SE
        }

        if (in_array($ddd, ['81', '87'], true)) {
            return [-8.047560, -34.877000]; // PE
        }

        if ($ddd === '82') {
            return [-9.665990, -35.735000]; // AL
        }

        if (in_array($ddd, ['83'], true)) {
            return [-7.119500, -34.845000]; // PB
        }

        if (in_array($ddd, ['84'], true)) {
            return [-5.779300, -35.200900]; // RN
        }

        if (in_array($ddd, ['85', '88'], true)) {
            return [-3.731900, -38.526700]; // CE
        }

        if (in_array($ddd, ['86', '89'], true)) {
            return [-5.091900, -42.803400]; // PI
        }

        if (in_array($ddd, ['91', '93', '94'], true)) {
            return [-1.455830, -48.490178]; // PA
        }

        if ($ddd === '92' || $ddd === '97') {
            return [-3.119028, -60.021731]; // AM
        }

        if ($ddd === '95') {
            return [2.823500, -60.675800]; // RR
        }

        if ($ddd === '96') {
            return [0.034934, -51.069420]; // AP
        }

        if ($ddd === '98' || $ddd === '99') {
            return [-2.538740, -44.282500]; // MA
        }

        return [-15.793889, -47.882778];
    }

    private function randomFloat(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function normalizeName(string $nome): string
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        return $nome;
    }

    private function getToken(): ?string
    {
        if ($this->fatalAuthMessage !== null) {
            return null;
        }

        $cached = Cache::get('presenca_api_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->login || !$this->senha) {
            $this->fatalAuthMessage = 'Credenciais da API Presença não configuradas.';
            return null;
        }

        $lock = Cache::lock('presenca_api_token_lock', $this->tokenLockTtl);

        try {
            $lock->block($this->tokenLockWait);
        } catch (LockTimeoutException $e) {
            PresencaLog::warning('[PRESENCA] Timeout ao aguardar lock do token.', $this->logContext([
                'error' => $e->getMessage(),
            ]));
            return null;
        }

        try {
            $cached = Cache::get('presenca_api_token');
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $resp = $this->requestJsonWithStageRetries(
                'POST',
                '/login',
                [
                    'login' => $this->login,
                    'senha' => $this->senha,
                ],
                [],
                false,
                $this->loginRequestAttempts,
                static fn (HttpResponse $response): bool => $response->status() >= 500,
                '[PRESENCA] Login com falha temporária; nova tentativa via throttle global.'
            );

            if (!$resp) {
                return null;
            }

            if ($resp->status() === 401) {
                $this->fatalAuthMessage = $this->joinMessages($this->extractMessages($resp), 'Usuário ou senha inválidos.');
                return null;
            }

            if ($resp->status() !== 200) {
                PresencaLog::warning('[PRESENCA] Falha ao obter token.', $this->logContext([
                    'status' => $resp->status(),
                    'messages' => $this->extractMessages($resp),
                ]));
                return null;
            }

            $json = $resp->json();
            if (!is_array($json) || empty($json['token'])) {
                PresencaLog::warning('[PRESENCA] Resposta de login sem token válido.', $this->logContext([
                    'status' => $resp->status(),
                ]));
                return null;
            }

            $token = (string) $json['token'];
            $ttlSeconds = $this->resolveTokenTtlSeconds($json['expireAt'] ?? null);
            Cache::put('presenca_api_token', $token, $ttlSeconds);

            return $token;
        } finally {
            optional($lock)->release();
        }
    }

    private function resolveTokenTtlSeconds(mixed $expireAt): int
    {
        if (is_string($expireAt) && $expireAt !== '') {
            try {
                $expiresAt = Carbon::parse($expireAt);
                $seconds = now()->diffInSeconds($expiresAt, false) - $this->tokenTtlSkewSeconds;
                if ($seconds > 0) {
                    return max(30, $seconds);
                }
            } catch (Throwable) {
                // fallback abaixo
            }
        }

        return $this->tokenTtlFallbackSeconds;
    }

    private function invalidateToken(): void
    {
        Cache::forget('presenca_api_token');
    }

    private function requestJson(
        string $method,
        string $path,
        array $payload = [],
        array $headers = [],
        bool $auth = true
    ): ?HttpResponse {
        $attempts = max(1, $this->httpRetryAttempts);

        for ($attempt = 1; ; $attempt++) {
            if ($auth && $this->fatalAuthMessage !== null) {
                return null;
            }

            $response = null;

            try {
                $this->clearLastRequestFailure();

                $client = Http::acceptJson()
                    ->timeout($this->httpTimeout)
                    ->connectTimeout($this->httpConnectTimeout);

                if ($auth) {
                    $token = $this->getToken();
                    if (!$token) {
                        return null;
                    }

                    $client = $client->withToken($token);
                }

                if (!empty($headers)) {
                    $client = $client->withHeaders($headers);
                }

                // Rate limit global aplicado imediatamente antes do envio efetivo.
                // Evita encostar login + próxima chamada em caso de refresh de token.
                $this->throttleRequests();

                $methodLower = strtolower($method);
                $url = $this->baseUrl . $path;

                if (in_array($methodLower, ['post', 'put', 'patch'], true)) {
                    $response = $client->asJson()->{$methodLower}($url, $payload);
                } elseif ($methodLower === 'delete') {
                    $response = $client->asJson()->delete($url, $payload);
                } else {
                    $response = $client->get($url, $payload);
                }

                $this->logHttpResponse($method, $path, $response);
                $this->clearLastRequestFailure();

                if ($response->status() === 401 && $auth) {
                    $this->invalidateToken();
                    if ($attempt < $attempts) {
                        continue;
                    }
                }

                if ($response->status() === 429) {
                    if ($this->logApi429) {
                        PresencaLog::warning('[PRESENCA] HTTP 429 recebido.', $this->logContext([
                            'method' => strtoupper($method),
                            'path' => $path,
                            'attempt' => $attempt,
                        ]));
                    }

                    // 429 não consome orçamento de retry: o fluxo aguarda e tenta novamente.
                    $this->sleepFor429($response);
                    $attempt = max(0, $attempt - 1);
                    continue;
                }

                return $response;
            } catch (ConnectionException $e) {
                $this->rememberRequestFailure(
                    $path,
                    $this->isTimeoutException($e) ? 'timeout' : 'connection'
                );
                PresencaLog::warning('[PRESENCA] Erro de conexão na requisição.', $this->logContext([
                    'method' => strtoupper($method),
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]));

                return null;
            } catch (Throwable $e) {
                $this->rememberRequestFailure($path, 'exception');
                PresencaLog::warning('[PRESENCA] Exceção na requisição.', $this->logContext([
                    'method' => strtoupper($method),
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]));

                return $response;
            }
        }

        return null;
    }

    private function requestJsonWithStageRetries(
        string $method,
        string $path,
        array $payload = [],
        array $headers = [],
        bool $auth = true,
        int $attempts = 1,
        ?callable $shouldRetryResponse = null,
        string $retryLogMessage = '[PRESENCA] Falha temporária na requisição; nova tentativa via throttle global.'
    ): ?HttpResponse {
        $attempts = max(1, $attempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->requestJson($method, $path, $payload, $headers, $auth);

            if ($response === null) {
                if ($attempt < $attempts) {
                    $this->logStageRetry($retryLogMessage, $attempt, $attempts);
                    continue;
                }

                return null;
            }

            $retryResponse = $shouldRetryResponse !== null
                ? (bool) $shouldRetryResponse($response)
                : false;

            if ($retryResponse && $attempt < $attempts) {
                $this->logStageRetry($retryLogMessage, $attempt, $attempts, $response);
                continue;
            }

            return $response;
        }

        return null;
    }

    private function sleepWithBackoff(int $attempt): void
    {
        $delayMs = min(
            $this->httpRetryMaxDelayMs,
            (int) ($this->httpRetryBaseDelayMs * (2 ** max(0, $attempt - 1)))
        );

        usleep(max(1, $delayMs) * 1000);
    }

    private function sleepFor429(HttpResponse $response): void
    {
        $retryAfter = $this->extractRetryAfterSeconds($response);
        $sleep = $retryAfter ?? $this->default429DelaySeconds;
        sleep(max(1, $sleep));
    }

    private function extractRetryAfterSeconds(HttpResponse $response): ?int
    {
        $header = $response->header('Retry-After');
        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        $header = trim($header);
        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        try {
            $when = Carbon::parse($header);
            $seconds = now()->diffInSeconds($when, false);
            return $seconds > 0 ? $seconds : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function clearLastRequestFailure(): void
    {
        $this->lastRequestFailureType = null;
        $this->lastRequestFailurePath = null;
    }

    private function rememberRequestFailure(string $path, string $type): void
    {
        $this->lastRequestFailureType = $type;
        $this->lastRequestFailurePath = $path;
    }

    /** @return array{status_code:string,message:string} */
    private function describeLastRequestFailure(string $defaultStatusCode, string $defaultMessage): array
    {
        if ($this->fatalAuthMessage !== null) {
            return [
                'status_code' => 'LOGIN_401',
                'message' => $this->fatalAuthMessage,
            ];
        }

        $path = $this->lastRequestFailurePath;
        $isTimeout = $this->lastRequestFailureType === 'timeout';

        if ($path === '/login') {
            return [
                'status_code' => $isTimeout ? 'LOGIN_TIMEOUT' : 'LOGIN_NO_RESPONSE',
                'message' => $isTimeout ? 'Timeout ao obter token da API.' : 'Sem resposta ao obter token da API.',
            ];
        }

        if ($path === '/consultas/termo-inss') {
            return [
                'status_code' => $isTimeout ? 'TERMO_TIMEOUT' : 'TERMO_NO_RESPONSE',
                'message' => $isTimeout ? 'Timeout ao gerar termo de autorização.' : 'Sem resposta ao gerar termo de autorização.',
            ];
        }

        if (is_string($path) && str_starts_with($path, '/consultas/termo-inss/')) {
            return [
                'status_code' => $isTimeout ? 'AUTORIZACAO_TIMEOUT' : 'AUTORIZACAO_NO_RESPONSE',
                'message' => $isTimeout ? 'Timeout na autorização do termo.' : 'Sem resposta na autorização do termo.',
            ];
        }

        if ($path === '/v3/operacoes/consignado-privado/consultar-vinculos') {
            return [
                'status_code' => $isTimeout ? 'VINCULOS_TIMEOUT' : 'VINCULOS_NO_RESPONSE',
                'message' => $isTimeout ? 'Timeout na consulta de vínculos.' : 'Sem resposta na consulta de vínculos.',
            ];
        }

        if ($path === '/v3/operacoes/consignado-privado/consultar-margem') {
            return [
                'status_code' => $isTimeout ? 'MARGEM_TIMEOUT' : 'MARGEM_NO_RESPONSE',
                'message' => $isTimeout ? 'Timeout na consulta de margem.' : 'Sem resposta na consulta de margem.',
            ];
        }

        if ($path === '/v5/operacoes/simulacao/disponiveis') {
            return [
                'status_code' => $isTimeout ? 'SIMULACAO_TIMEOUT' : 'SIMULACAO_NO_RESPONSE',
                'message' => $isTimeout ? 'Timeout na consulta de simulação.' : 'Sem resposta na consulta de simulação.',
            ];
        }

        if ($isTimeout) {
            return [
                'status_code' => $this->timeoutStatusCodeFrom($defaultStatusCode),
                'message' => $this->timeoutMessageFrom($defaultMessage),
            ];
        }

        return [
            'status_code' => $defaultStatusCode,
            'message' => $defaultMessage,
        ];
    }

    private function timeoutStatusCodeFrom(string $statusCode): string
    {
        if (str_ends_with($statusCode, '_NO_RESPONSE')) {
            return substr($statusCode, 0, -strlen('_NO_RESPONSE')) . '_TIMEOUT';
        }

        return $statusCode . '_TIMEOUT';
    }

    private function timeoutMessageFrom(string $message): string
    {
        if (str_starts_with($message, 'Sem resposta')) {
            return 'Timeout' . substr($message, strlen('Sem resposta'));
        }

        return $message;
    }

    private function isTimeoutException(Throwable $e): bool
    {
        $message = $this->normalizeText($e->getMessage());

        return str_contains($message, 'curl error 28')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout');
    }

    private function logStageRetry(
        string $message,
        int $attempt,
        int $maxAttempts,
        ?HttpResponse $response = null
    ): void {
        $context = [
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
        ];

        if ($response !== null) {
            $context['status'] = $response->status();
            $context['messages'] = $this->extractMessages($response);
        } else {
            $context['failure_type'] = $this->lastRequestFailureType;
            $context['failure_path'] = $this->lastRequestFailurePath;
        }

        PresencaLog::warning($message, $this->logContext($context));
    }

    private function hasReusableAuthorization(string $cpf): bool
    {
        if ($cpf === '' || $this->authorizationReuseTtlSeconds <= 0) {
            return false;
        }

        $nowTs = microtime(true);
        $cached = $this->getAuthorizationLocalState($cpf, $nowTs);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $nowUtc = now('UTC')->format('Y-m-d H:i:s');
            $row = DB::table('presenca_authorizations')
                ->where('cpf', $cpf)
                ->where('expires_at', '>', $nowUtc)
                ->select('expires_at')
                ->first();

            if ($row === null) {
                $this->putAuthorizationLocalState($cpf, 0.0);
                return false;
            }

            $expiresAtTs = strtotime((string) ($row->expires_at ?? ''));
            if (!is_int($expiresAtTs) || $expiresAtTs <= 0) {
                $this->putAuthorizationLocalState($cpf, 0.0);
                return false;
            }

            $this->putAuthorizationLocalState($cpf, (float) $expiresAtTs);
            return true;
        } catch (Throwable $e) {
            PresencaLog::warning('[PRESENCA] Falha ao consultar cache persistente de autorização.', $this->logContext([
                'error' => $e->getMessage(),
            ]));
            return false;
        }
    }

    private function markAuthorizationGrant(string $cpf): void
    {
        if ($cpf === '' || $this->authorizationReuseTtlSeconds <= 0) {
            return;
        }

        $nowUtc = now('UTC');
        $nowStr = $nowUtc->format('Y-m-d H:i:s');
        $expiresUtc = $nowUtc->copy()->addSeconds($this->authorizationReuseTtlSeconds);
        $expiresStr = $expiresUtc->format('Y-m-d H:i:s');
        $this->putAuthorizationLocalState($cpf, (float) $expiresUtc->getTimestamp());

        try {
            DB::table('presenca_authorizations')->upsert(
                [[
                    'cpf' => $cpf,
                    'authorized_at' => $nowStr,
                    'expires_at' => $expiresStr,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]],
                ['cpf'],
                ['authorized_at', 'expires_at', 'updated_at']
            );
        } catch (Throwable $e) {
            PresencaLog::warning('[PRESENCA] Falha ao persistir cache de autorização.', $this->logContext([
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function getAuthorizationLocalState(string $cpf, float $nowTs): ?bool
    {
        if (!array_key_exists($cpf, $this->authorizationLocalState)) {
            return null;
        }

        $expiresAtTs = (float) $this->authorizationLocalState[$cpf];

        if ($expiresAtTs > 0.0 && $expiresAtTs < $nowTs) {
            unset($this->authorizationLocalState[$cpf], $this->authorizationLocalOrder[$cpf]);
            return null;
        }

        $this->touchAuthorizationLocalOrder($cpf);

        if ($expiresAtTs <= 0.0) {
            return false;
        }

        return true;
    }

    private function putAuthorizationLocalState(string $cpf, float $expiresAtTs): void
    {
        if ($this->authorizationLocalCacheMax <= 0) {
            return;
        }

        $this->authorizationLocalState[$cpf] = $expiresAtTs;
        $this->touchAuthorizationLocalOrder($cpf);
        $this->trimAuthorizationLocalCache();
    }

    private function touchAuthorizationLocalOrder(string $cpf): void
    {
        if ($this->authorizationLocalCacheMax <= 0) {
            return;
        }

        if (array_key_exists($cpf, $this->authorizationLocalOrder)) {
            unset($this->authorizationLocalOrder[$cpf]);
        }

        $this->authorizationLocalOrder[$cpf] = true;
    }

    private function trimAuthorizationLocalCache(): void
    {
        if ($this->authorizationLocalCacheMax <= 0) {
            $this->authorizationLocalState = [];
            $this->authorizationLocalOrder = [];
            return;
        }

        while (count($this->authorizationLocalOrder) > $this->authorizationLocalCacheMax) {
            $oldestCpf = array_key_first($this->authorizationLocalOrder);
            if (!is_string($oldestCpf) || $oldestCpf === '') {
                break;
            }

            unset($this->authorizationLocalOrder[$oldestCpf], $this->authorizationLocalState[$oldestCpf]);
        }
    }

    private function throttleRequests(): void
    {
        if (!$this->rateLimitEnabled) {
            return;
        }

        $lockTimeoutCount = 0;
        while (true) {
            $lock = Cache::lock('presenca_http_rate_lock', $this->rateLockTtl);

            try {
                $lock->block($this->rateLockWait);
            } catch (LockTimeoutException $e) {
                $lockTimeoutCount++;
                PresencaLog::warning('[PRESENCA] Timeout ao aguardar lock de rate limit.', $this->logContext([
                    'error' => $e->getMessage(),
                    'timeout_count' => $lockTimeoutCount,
                ]));

                if ($lockTimeoutCount >= 5) {
                    throw new \RuntimeException(
                        'Não foi possível adquirir lock de rate limit após múltiplas tentativas.',
                        0,
                        $e
                    );
                }

                usleep(200000);
                continue;
            }

            $waitMs = 0;

            try {
                $nowMs = (int) floor(microtime(true) * 1000);

                $lastAtMs = (int) Cache::get('presenca_http_last_at_ms', 0);
                if ($this->rateMinIntervalMs > 0 && $lastAtMs > 0) {
                    $elapsedMs = $nowMs - $lastAtMs;
                    if ($elapsedMs < $this->rateMinIntervalMs) {
                        $waitMs = max($waitMs, $this->rateMinIntervalMs - $elapsedMs);
                    }
                }

                $windowStartMs = (int) Cache::get('presenca_http_rpm_window_start_ms', $nowMs);
                $windowCount = (int) Cache::get('presenca_http_rpm_window_count', 0);

                if (($nowMs - $windowStartMs) >= 60000) {
                    $windowStartMs = $nowMs;
                    $windowCount = 0;
                }

                if ($this->rateMaxRequestsPerMinute > 0 && $windowCount >= $this->rateMaxRequestsPerMinute) {
                    $waitMs = max($waitMs, ($windowStartMs + 60000) - $nowMs);
                }

                if ($waitMs <= 0) {
                    $nowMs = (int) floor(microtime(true) * 1000);

                    if (($nowMs - $windowStartMs) >= 60000) {
                        $windowStartMs = $nowMs;
                        $windowCount = 0;
                    }

                    $windowCount++;

                    Cache::put('presenca_http_last_at_ms', $nowMs, 120);
                    Cache::put('presenca_http_rpm_window_start_ms', $windowStartMs, 120);
                    Cache::put('presenca_http_rpm_window_count', $windowCount, 120);

                    return;
                }
            } finally {
                optional($lock)->release();
            }

            usleep(max(1, $waitMs) * 1000);
        }
    }

    private function isKnownAuthorization500(array $messages): bool
    {
        foreach ($messages as $message) {
            $norm = $this->normalizeText($message);
            if (str_contains($norm, 'an error ocurred') || str_contains($norm, 'an error occurred')) {
                return true;
            }
        }

        return false;
    }

    private function isInvalidTermId(array $messages): bool
    {
        foreach ($messages as $message) {
            $norm = $this->normalizeText($message);
            if (str_contains($norm, 'termoid') && str_contains($norm, 'not valid')) {
                return true;
            }
            if (str_contains($norm, 'nao valido') && str_contains($norm, 'termoid')) {
                return true;
            }
        }

        return false;
    }

    private function containsFalhaSimulacaoTemporaria(array $messages): bool
    {
        foreach ($messages as $message) {
            $norm = $this->normalizeText($message);
            if (str_contains($norm, 'falha ao realizar simulacao')) {
                return true;
            }
        }

        return false;
    }

    private function containsTelefoneJaUtilizado(array $messages): bool
    {
        foreach ($messages as $message) {
            $norm = $this->normalizeText($message);
            if (str_contains($norm, 'telefone ja utilizado') && str_contains($norm, 'outro cliente')) {
                return true;
            }
        }

        return false;
    }

    private function toBoolString(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'sim' : 'nao';
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            if ($normalized !== '' && is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return null;
    }

    private function toMoneyString(mixed $value): ?string
    {
        $float = $this->toFloat($value);
        if ($float === null) {
            return null;
        }

        return number_format($float, 2, '.', '');
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = mb_strtolower($value, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if (is_string($ascii) && $ascii !== '') {
            $lower = $ascii;
        }

        return preg_replace('/\s+/', ' ', $lower) ?? $lower;
    }

    /** @return array<int,string> */
    private function extractMessages(HttpResponse $response): array
    {
        $out = [];

        try {
            $json = $response->json();
        } catch (Throwable) {
            $json = null;
        }

        $append = static function (mixed $value) use (&$out): void {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
                return;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $trimmed = trim($item);
                        if ($trimmed !== '') {
                            $out[] = $trimmed;
                        }
                    }
                }
            }
        };

        if (is_array($json)) {
            $append($json['errors'] ?? null);
            $append($json['erros'] ?? null);
            $append($json['messages'] ?? null);
            $append($json['message'] ?? null);
            $append($json['result'] ?? null);
            $append($json['detail'] ?? null);
            $append($json['title'] ?? null);

            if (isset($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $errorValue) {
                    $append($errorValue);
                }
            }
        }

        if (empty($out)) {
            $body = trim((string) $response->body());
            if ($body !== '') {
                $out[] = mb_substr($body, 0, 500);
            }
        }

        return array_values(array_unique($out));
    }

    private function joinMessages(array $messages, string $fallback): string
    {
        if (empty($messages)) {
            return $fallback;
        }

        return implode(' | ', $messages);
    }

    /** @return array<int,array<string,mixed>> */
    private function extractVinculos(mixed $payload): array
    {
        if (is_array($payload)) {
            if ($this->isList($payload)) {
                $out = [];
                foreach ($payload as $item) {
                    if (is_array($item)) {
                        $out[] = $item;
                    }
                }
                return $out;
            }

            foreach (['id', 'data', 'result', 'vinculos'] as $key) {
                if (!isset($payload[$key]) || !is_array($payload[$key])) {
                    continue;
                }

                if ($this->isList($payload[$key])) {
                    $out = [];
                    foreach ($payload[$key] as $item) {
                        if (is_array($item)) {
                            $out[] = $item;
                        }
                    }
                    return $out;
                }
            }
        }

        return [];
    }

    /** @param array<int,array<string,mixed>> $vinculos */
    private function selectVinculo(array $vinculos): array
    {
        foreach ($vinculos as $vinculo) {
            if (($vinculo['elegivel'] ?? false) === true) {
                return $vinculo;
            }
        }

        return $vinculos[0];
    }

    /** @return array<string,mixed>|null */
    private function extractFirstSimulation(mixed $payload): ?array
    {
        if (is_array($payload) && $this->isList($payload)) {
            foreach ($payload as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $i = 0;
        foreach ($value as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }

        return true;
    }

    private function logHttpResponse(string $method, string $path, HttpResponse $response): void
    {
        if (!$this->logApiResponses) {
            return;
        }

        $status = $response->status();
        if (!$this->logApiSuccessResponses && $status < 400) {
            return;
        }

        PresencaLog::warning('[PRESENCA] HTTP response', $this->logContext([
            'method' => strtoupper($method),
            'path' => $path,
            'status' => $status,
            'messages' => $status >= 400 ? $this->extractMessages($response) : null,
        ]));
    }

    /** @param array<string,mixed> $context */
    private function logContext(array $context = []): array
    {
        if (!array_key_exists('job_id', $context) && $this->jobId !== null) {
            $context['job_id'] = $this->jobId;
        }

        if (!array_key_exists('cpf', $context) && $this->currentCpf !== null && $this->currentCpf !== '') {
            $context['cpf'] = $this->currentCpf;
        }

        return $context;
    }
}
