# Documentação API V8 Digital — FGTS até Simulação

## 1. Objetivo

Esta documentação descreve o fluxo necessário para integrar com a API da V8 Digital no produto **Saque Aniversário FGTS**, cobrindo somente as etapas até a **simulação**:

1. Autenticar na API.
2. Iniciar consulta de saldo FGTS.
3. Consultar o resultado da consulta de saldo.
4. Obter a tabela de taxas.
5. Criar a simulação FGTS.

Esta documentação **não cobre criação de proposta, formalização, bancos, pendências, cancelamento ou pós-venda**, pois o escopo definido para a automação é chegar somente até a simulação.

Também foi definido que **não será utilizado webhook** neste fluxo. O resultado da consulta de saldo deverá ser obtido por consulta ativa via `GET /fgts/balance`, podendo usar filtros opcionais como `search`, `startDate`, `endDate`, `limit` e `page`.

---

## 2. URLs base

### Autenticação

```text
https://auth.v8sistema.com
```

### API FGTS

```text
https://bff.v8sistema.com
```

---

## 3. Autenticação

A autenticação da API da V8 Digital utiliza OAuth 2.0 com `grant_type=password`.

O token deve ser obtido antes de chamar os endpoints protegidos da API FGTS.

### Endpoint

```text
POST https://auth.v8sistema.com/oauth/token
```

### Content-Type

```text
application/x-www-form-urlencoded
```

### Parâmetros obrigatórios

| Campo | Tipo | Obrigatório | Descrição |
|---|---:|---:|---|
| `grant_type` | string | Sim | Deve ser enviado como `password`. |
| `username` | string | Sim | E-mail do usuário fornecido pela V8. |
| `password` | string | Sim | Senha do usuário fornecido pela V8. |
| `audience` | string | Sim | Audience fornecido pela V8. |
| `scope` | string | Sim | Enviar como `offline_access`. |
| `client_id` | string | Sim | Client ID fornecido pela V8. |

### Exemplo de requisição

```http
POST /oauth/token HTTP/1.1
Host: auth.v8sistema.com
Content-Type: application/x-www-form-urlencoded

grant_type=password&username=<email>&password=<senha>&audience=<audience>&scope=offline_access&client_id=<client_id>
```

### Exemplo usando cURL

```bash
curl --request POST 'https://auth.v8sistema.com/oauth/token' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'username=<email>' \
  --data-urlencode 'password=<senha>' \
  --data-urlencode 'audience=<audience>' \
  --data-urlencode 'scope=offline_access' \
  --data-urlencode 'client_id=<client_id>'
```

### Resposta de sucesso

Status HTTP:

```text
200 OK
```

Body:

```json
{
  "access_token": "<jwt>",
  "scope": "",
  "expires_in": 86400,
  "token_type": "Bearer"
}
```

### Uso do token

O valor de `access_token` deve ser enviado no header `Authorization` das chamadas seguintes:

```text
Authorization: Bearer <access_token>
```

### Regra de falha na autenticação

Para o endpoint de autenticação:

- `200 OK`: autenticação realizada com sucesso.
- Qualquer outro status HTTP ou erro de rede: considerar o job como **falhou**.

### Segurança

Nunca versionar, logar ou expor:

- `access_token`;
- `username`;
- `password`;
- `client_id`;
- `audience`.

Em logs de erro, mascarar valores sensíveis.

---

## 4. Headers padrão dos endpoints FGTS

Todos os endpoints da API FGTS devem receber:

```text
Authorization: Bearer <access_token>
Content-Type: application/json
```

---

## 5. Fluxo geral até a simulação

O fluxo correto para chegar até a simulação é:

```text
1. POST /oauth/token
   Obter access_token.

2. POST /fgts/balance
   Iniciar consulta de saldo para o CPF e provider.

3. GET /fgts/balance
   Consultar o resultado da consulta de saldo.
   Filtros opcionais:
   - search
   - startDate
   - endDate
   - limit
   - page

4. GET /fgts/simulations/fees
   Obter as tabelas de taxas disponíveis.

5. Selecionar tabela "normal"
   Usar o id_simulation_fees da tabela normal.

6. POST /fgts/simulations
   Criar simulação usando:
   - simulationFeesId
   - balanceId
   - documentNumber
   - desiredInstallments
   - provider
```

---

## 6. Consulta de saldo FGTS — iniciar consulta

Este endpoint inicia a consulta de saldo FGTS do cliente.

O processamento é assíncrono. No fluxo desta integração, não será utilizado webhook. Após iniciar a consulta, o resultado deverá ser obtido via `GET /fgts/balance`, com ou sem filtros opcionais.

### Endpoint

```text
POST https://bff.v8sistema.com/fgts/balance
```

### Body

```json
{
  "documentNumber": "12345678900",
  "provider": "bms"
}
```

### Campos do body

| Campo | Tipo | Obrigatório | Descrição |
|---|---:|---:|---|
| `documentNumber` | string | Sim | CPF do cliente, preferencialmente somente números. |
| `provider` | string | Sim | Provedor da consulta. Valores aceitos: `bms`, `qi` ou `cartos`. |

### Observação sobre provider

Apesar de alguns exemplos da documentação original apresentarem o provider em maiúsculo, recomenda-se padronizar o envio em minúsculo:

```text
bms
qi
cartos
```

Nos testes realizados, foi utilizado `bms`.

### Exemplo de requisição

```http
POST /fgts/balance HTTP/1.1
Host: bff.v8sistema.com
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "documentNumber": "12345678900",
  "provider": "bms"
}
```

### Resposta de sucesso

Status HTTP:

```text
200 OK
```

Body:

```json
null
```

A resposta `null` indica que a consulta foi aceita e está em processamento.

---

## 7. Tratamento de erros — POST /fgts/balance

O endpoint `POST /fgts/balance` pode retornar erros que devem ser classificados em dois grupos:

1. **Erros retentáveis**: indicam instabilidade temporária da API ou indisponibilidade momentânea da consulta. Podem ser tentados novamente.
2. **Erros não retentáveis**: indicam regra de negócio, impedimento do CPF ou condição definitiva naquele momento. Não devem ser tentados novamente para o mesmo CPF.

### 7.1 Sucesso ao iniciar consulta

Status HTTP:

```text
200 OK
```

Body:

```json
null
```

Regra:

```text
Consulta aceita.
Prosseguir para a consulta do resultado via GET /fgts/balance, usando os filtros necessários.
```

---

### 7.2 Erros retentáveis

#### 7.2.1 BadRequestError — Tente novamente

Status HTTP:

```text
400 Bad Request
```

Exemplo:

```json
{
  "type": "about:blank",
  "status": 400,
  "title": "BadRequestError",
  "detail": "Tente novamente",
  "instance": "/bff-app-error"
}
```

Classificação:

```text
Retentável.
```

Regra:

```text
Pode tentar novamente para o mesmo CPF.
```

---

#### 7.2.2 AppError — Não foi possível consultar o saldo no momento

Status HTTP:

```text
400 Bad Request
```

Exemplo:

```json
{
  "type": "about:blank",
  "status": 400,
  "title": "AppError",
  "detail": "CPF: 10064305554 | Não foi possível consultar o saldo no momento!",
  "instance": "/bff-app-error"
}
```

Classificação:

```text
Retentável.
```

Regra:

```text
Pode tentar novamente para o mesmo CPF.
```

Critério de identificação:

```text
status HTTP = 400
title = AppError
detail contém "Não foi possível consultar o saldo no momento!"
```

---

#### 7.2.3 AppError — Ocorreu um erro inesperado

Status HTTP:

```text
400 Bad Request
```

Exemplo:

```json
{
  "type": "about:blank",
  "status": 400,
  "title": "AppError",
  "detail": "Ocorreu um erro inesperado",
  "instance": "/bff-app-error"
}
```

Classificação:

```text
Retentável.
```

Regra:

```text
Pode tentar novamente para o mesmo CPF.
```

Critério de identificação:

```text
status HTTP = 400
title = AppError
detail = "Ocorreu um erro inesperado"
```

---

### 7.3 Erros não retentáveis

#### 7.3.1 Trabalhador sem adesão ao saque aniversário

Status HTTP:

```text
400 Bad Request
```

Exemplo:

```json
{
  "type": "about:blank",
  "status": 400,
  "title": "AppError",
  "detail": "CPF: 13194685945 | Trabalhador não possui adesão ao saque aniversário vigente na data corrente.",
  "instance": "/bff-app-error"
}
```

Classificação:

```text
Não retentável.
```

Regra:

```text
Não tentar novamente para o mesmo CPF.
Considerar falha de negócio.
```

Critério de identificação:

```text
detail contém "Trabalhador não possui adesão ao saque aniversário vigente na data corrente"
```

---

#### 7.3.2 Sem autorização do trabalhador para operação fiduciária

Status HTTP:

```text
400 Bad Request
```

Exemplo:

```json
{
  "type": "about:blank",
  "status": 400,
  "title": "AppError",
  "detail": "CPF: 02634598706 | Instituição Fiduciária não possui autorização do Trabalhador para Operação Fiduciária.",
  "instance": "/bff-app-error"
}
```

Classificação:

```text
Não retentável.
```

Regra:

```text
Não tentar novamente para o mesmo CPF.
Considerar falha de negócio.
```

Critério de identificação:

```text
detail contém "não possui autorização do Trabalhador"
```

---

#### 7.3.3 Existe operação fiduciária em andamento

Status HTTP:

```text
400 Bad Request
```

Exemplo:

```json
{
    "type": "about:blank",
    "status": 400,
    "title": "AppError",
    "detail": "Não foi possível consultar o saldo no momento! - Existe uma Operação Fiduciária em andamento. Tente mais tarde.",
    "instance": "/bff-app-error"
}
```

Classificação:

```text
Não retentável.
```

Regra:

```text
Não tentar novamente para o mesmo CPF.
Considerar falha de negócio.
```

Critério de identificação:

```text
detail contém "Existe uma Operação Fiduciária em andamento"
```

---

### 7.4 Regra consolidada para classificação

#### Retentável

Considerar retentável quando ocorrer uma das condições abaixo:

```text
title = BadRequestError
detail = "Tente novamente"
```

Ou:

```text
status HTTP = 400
title = AppError
detail = "Ocorreu um erro inesperado"
```

Ou:

```text
status HTTP = 400
title = AppError
detail contém "Não foi possível consultar o saldo no momento!"
```

Desde que o `detail` não contenha nenhuma mensagem classificada como não retentável.

#### Não retentável

Considerar não retentável quando o `detail` contiver uma das mensagens abaixo:

```text
"Trabalhador não possui adesão ao saque aniversário vigente na data corrente"
"não possui autorização do Trabalhador"
"Existe uma Operação Fiduciária em andamento"
```

#### Demais erros

Para erros diferentes dos documentados:

```text
Considerar como falha.
Não assumir sucesso sem resposta válida.
Não seguir para simulação sem balanceId válido.
```

### 7.5 Resumo dos erros do POST /fgts/balance

| Tipo | Status HTTP | title | detail/Condição | Retentar? | Decisão |
|---|---:|---|---|---:|---|
| Sucesso | 200 | - | `null` | Não | Prosseguir para `GET /fgts/balance` |
| Temporário | 400 | `BadRequestError` | `Tente novamente` | Sim | Tentar novamente para o mesmo CPF |
| Temporário | 400 | `AppError` | `Ocorreu um erro inesperado` | Sim | Tentar novamente para o mesmo CPF |
| Temporário | 400 | `AppError` | Contém `Não foi possível consultar o saldo no momento!` | Sim | Tentar novamente para o mesmo CPF |
| Regra de negócio | 400 | `AppError` | Contém `Trabalhador não possui adesão ao saque aniversário vigente na data corrente` | Não | Falha de negócio |
| Regra de negócio | 400 | `AppError` | Contém `não possui autorização do Trabalhador` | Não | Falha de negócio |
| Regra de negócio | 400 | `AppError` | Contém `Existe uma Operação Fiduciária em andamento` | Não | Falha de negócio |
| Não documentado | Qualquer | Qualquer | Qualquer erro diferente dos acima | Não | Falha sem seguir fluxo |

---

## 8. Consulta do resultado do saldo FGTS

Após iniciar a consulta com `POST /fgts/balance`, o resultado deve ser consultado pelo endpoint `GET /fgts/balance`.

Neste fluxo, **não será usado webhook**. A consulta final deve ser feita de forma ativa.

A collection do Postman possui variações deste endpoint com:

- consulta sem `search`, usando filtros date-time e paginação;
- consulta com `search` por CPF;
- consulta combinando `search`, filtros date-time e paginação.

### Endpoint base

```text
GET https://bff.v8sistema.com/fgts/balance
```

### Endpoint — consulta com filtros date-time e paginação, sem CPF

```text
GET https://bff.v8sistema.com/fgts/balance?startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>
```

### Endpoint — consulta por CPF

```text
GET https://bff.v8sistema.com/fgts/balance?search=<CPF>
```

### Endpoint — consulta por CPF com filtros date-time e paginação

```text
GET https://bff.v8sistema.com/fgts/balance?search=<CPF>&startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>
```

### Query params

| Campo | Tipo | Obrigatório | Descrição |
|---|---:|---:|---|
| `search` | string | Não | Filtro textual. Pode ser usado para buscar por CPF. Deve ser enviado preferencialmente somente com números quando usado para CPF. |
| `startDate` | string | Não | Data/hora inicial do filtro. Deve ser enviada no formato date-time aceito pela API. |
| `endDate` | string | Não | Data/hora final do filtro. Deve ser enviada no formato date-time aceito pela API. |
| `limit` | number | Não | Quantidade de registros por página. |
| `page` | number | Não | Página desejada da consulta. |

### Observação sobre obrigatoriedade do search

O parâmetro `search` **não é obrigatório**.

É possível consultar o saldo final sem informar CPF, usando apenas filtros date-time e paginação:

```text
GET /fgts/balance?startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>
```

Para o fluxo automatizado por CPF, o uso de `search=<CPF>` continua sendo recomendado quando a intenção for localizar diretamente o retorno de um CPF específico, mas ele não deve ser tratado como obrigatório pela documentação.

### Observação sobre filtros de data

Na collection do Postman, os filtros são enviados como:

```text
startDate={{start_date_time}}
endDate={{end_date_time}}
limit={{limit}}
page={{page}}
```

Exemplo recomendado de formato para date-time:

```text
2026-04-24T00:00:00.000Z
2026-04-24T23:59:59.999Z
```

Usar timezone de forma consistente para evitar divergência entre o horário local e o horário armazenado pela API.

### Exemplo de requisição com filtros date-time e paginação, sem CPF

```http
GET /fgts/balance?startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=1 HTTP/1.1
Host: bff.v8sistema.com
Authorization: Bearer <access_token>
Content-Type: application/json
```

### Exemplo de requisição por CPF

```http
GET /fgts/balance?search=00089909216 HTTP/1.1
Host: bff.v8sistema.com
Authorization: Bearer <access_token>
Content-Type: application/json
```

### Exemplo de requisição por CPF com filtros date-time e paginação

```http
GET /fgts/balance?search=00089909216&startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=1 HTTP/1.1
Host: bff.v8sistema.com
Authorization: Bearer <access_token>
Content-Type: application/json
```

### Exemplo usando cURL — filtros date-time e paginação, sem CPF

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/balance?startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=1' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

### Exemplo usando cURL — consulta por CPF

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/balance?search=00089909216' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

### Exemplo usando cURL — consulta por CPF com filtros date-time e paginação

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/balance?search=00089909216&startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=1' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

### Exemplo de resposta de sucesso

```json
{
  "data": [
    {
      "id": "15d20e59-4b60-4e43-b4bf-741bd4a70302",
      "documentNumber": "00089909216",
      "partnerId": "7439_D019",
      "status": "success",
      "statusInfo": null,
      "createdAt": "2026-04-24T15:00:02.640Z",
      "updatedAt": "2026-04-24T15:00:02.640Z",
      "amount": 100.87,
      "provider": "bms",
      "periods": [
        {
          "amount": 100.87,
          "dueDate": "2031-04-01"
        }
      ]
    }
  ],
  "pages": {
    "limit": 10,
    "total": 1,
    "current": 1,
    "hasNext": false,
    "hasPrev": false,
    "totalPages": 1
  }
}
```

### Campos importantes da resposta

| Campo | Tipo | Descrição |
|---|---:|---|
| `data` | array | Lista de consultas encontradas conforme os filtros informados. |
| `data[].id` | string | Identificador da consulta de saldo. Este valor será usado como `balanceId` na simulação. |
| `data[].documentNumber` | string | CPF consultado. |
| `data[].partnerId` | string | Código do parceiro/usuário que iniciou a consulta. |
| `data[].status` | string | Status da consulta. Exemplo: `success` ou `fail`. |
| `data[].statusInfo` | string/null | Detalhe de erro quando houver falha. |
| `data[].createdAt` | string | Data/hora de criação da consulta. |
| `data[].updatedAt` | string | Data/hora de atualização/retorno da consulta. |
| `data[].amount` | number | Valor total disponível retornado pela consulta. |
| `data[].provider` | string | Provider usado na consulta. |
| `data[].periods` | array | Lista de períodos disponíveis para simulação. |
| `data[].periods[].amount` | number | Valor disponível no período. |
| `data[].periods[].dueDate` | string | Data do período no formato `YYYY-MM-DD`. |
| `pages.limit` | number | Limite de registros por página. |
| `pages.total` | number | Total de registros encontrados. |
| `pages.current` | number | Página atual. |
| `pages.hasNext` | boolean | Indica se existe próxima página. |
| `pages.hasPrev` | boolean | Indica se existe página anterior. |
| `pages.totalPages` | number | Total de páginas. |

### Seleção do resultado

Para seguir para a simulação, é necessário obter um item em `data` com:

```text
status = success
```

E com os campos:

```text
id
periods
provider
documentNumber
```

O campo `id` será usado como:

```text
balanceId
```

O campo `periods` será usado para montar:

```text
desiredInstallments
```

### Critério recomendado quando houver múltiplos resultados

Quando a resposta retornar mais de um registro em `data`, selecionar preferencialmente o registro que atenda a todos os critérios disponíveis.

Se a consulta foi feita por CPF, usar:

```text
documentNumber = CPF consultado
status = success
provider = provider usado no POST /fgts/balance
```

Se a consulta foi feita sem `search`, usando apenas filtros date-time e paginação, usar:

```text
status = success
provider = provider esperado
```

Quando houver múltiplos registros válidos, usar o registro mais recente pelo campo:

```text
updatedAt
```

Caso `updatedAt` esteja ausente ou inválido, usar `createdAt`.

### Paginação

Quando `pages.hasNext` for `true`, existem mais registros disponíveis.

Para consultar a próxima página, incrementar o parâmetro:

```text
page
```

Exemplo sem `search`:

```text
GET /fgts/balance?startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=2
```

Exemplo com `search`:

```text
GET /fgts/balance?search=00089909216&startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=2
```

---

## 9. Montagem das parcelas para simulação

A simulação exige o campo `desiredInstallments`.

A partir da resposta do saldo, cada item de `periods` deve ser convertido para o formato esperado pela simulação.

### Origem — periods

```json
"periods": [
  {
    "amount": 180.16,
    "dueDate": "2030-06-01"
  },
  {
    "amount": 124.50,
    "dueDate": "2031-06-01"
  }
]
```

### Destino — desiredInstallments

```json
"desiredInstallments": [
  {
    "totalAmount": 180.16,
    "dueDate": "2030-06-01"
  },
  {
    "totalAmount": 124.50,
    "dueDate": "2031-06-01"
  }
]
```

### Regra de mapeamento

```text
periods[].amount   -> desiredInstallments[].totalAmount
periods[].dueDate  -> desiredInstallments[].dueDate
```

### Observação importante sobre quantidade de parcelas

A documentação original informa que `desiredInstallments` deve conter pelo menos 2 parcelas.

Porém, em teste real de consulta de saldo com provider `bms`, foi retornado um CPF com apenas 1 período disponível:

```json
"periods": [
  {
    "amount": 100.87,
    "dueDate": "2031-04-01"
  }
]
```

Portanto, antes de chamar a simulação, validar se a API aceitará o caso com apenas 1 período para o provider utilizado. Se a API exigir 2 parcelas e houver apenas 1 período disponível, a simulação pode falhar por regra de negócio.

---

## 10. Consulta de tabelas de taxas

Este endpoint retorna as tabelas de taxas disponíveis para simulação.

### Endpoint

```text
GET https://bff.v8sistema.com/fgts/simulations/fees
```

### Exemplo de requisição

```http
GET /fgts/simulations/fees HTTP/1.1
Host: bff.v8sistema.com
Authorization: Bearer <access_token>
Content-Type: application/json
```

### Exemplo de resposta

```json
[
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "13c4c16a-0ace-4cca-af52-323ddf2c0894",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "milhas"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "cb563029-ba93-4b53-8d53-4ac145087212",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "normal"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "61c9fb2f-c902-4992-b8f5-b0ee368c45b0",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "cometa"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "8beaa78b-b7ba-4f48-853e-de9f322be42f",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "turbo"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "cd67cb02-c49d-457e-bfa9-a99316eb9dfe",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "pitstop"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "f6d779ed-52bf-42f2-9dbc-3125fe6491ba",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "acelera"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "755a9628-9438-4573-a44d-8bbb00e2d105",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "podium"
    }
  },
  {
    "active": true,
    "default": false,
    "simulation_fees": {
      "id_simulation_fees": "8da03168-a146-472d-b0c1-df2ff0e8a743",
      "spread_value": 0,
      "annual_interest_fees": 0.23872053,
      "monthly_interest_rates": 1.8,
      "label": "grid iPhone"
    }
  }
]
```

### Campos da resposta

| Campo | Tipo | Descrição |
|---|---:|---|
| `active` | boolean | Indica se a tabela está ativa. |
| `default` | boolean | Indica se é a tabela padrão. |
| `simulation_fees.id_simulation_fees` | string | Identificador da tabela de taxas. |
| `simulation_fees.spread_value` | number | Spread aplicado. |
| `simulation_fees.annual_interest_fees` | number | Taxa anual. |
| `simulation_fees.monthly_interest_rates` | number | Taxa mensal. |
| `simulation_fees.label` | string | Nome da tabela. |

### Regra definida para esta integração

Na simulação, utilizar somente a tabela com:

```text
label = normal
```

No teste documentado, o ID da tabela `normal` era:

```text
cb563029-ba93-4b53-8d53-4ac145087212
```

Apesar disso, o ID deve ser obtido dinamicamente pelo endpoint de taxas, pois pode mudar conforme ambiente, credencial ou configuração da V8.

### Critério para seleção da tabela

Selecionar o item onde:

```text
active = true
simulation_fees.label = normal
```

Usar o campo:

```text
simulation_fees.id_simulation_fees
```

como:

```text
simulationFeesId
```

---

## 11. Simulação FGTS

Este endpoint cria uma simulação FGTS com base no saldo consultado, na tabela de taxas e nos períodos selecionados.

### Endpoint

```text
POST https://bff.v8sistema.com/fgts/simulations
```

### Body

```json
{
  "simulationFeesId": "cb563029-ba93-4b53-8d53-4ac145087212",
  "balanceId": "15d20e59-4b60-4e43-b4bf-741bd4a70302",
  "targetAmount": 0,
  "documentNumber": "00089909216",
  "desiredInstallments": [
    {
      "totalAmount": 180.16,
      "dueDate": "2030-06-01"
    },
    {
      "totalAmount": 124.50,
      "dueDate": "2031-06-01"
    }
  ],
  "provider": "bms"
}
```

### Campos do body

| Campo | Tipo | Obrigatório | Descrição |
|---|---:|---:|---|
| `simulationFeesId` | string | Sim | ID da tabela de taxas obtido em `/fgts/simulations/fees`. |
| `balanceId` | string | Sim | ID da consulta de saldo obtido em `GET /fgts/balance`. |
| `targetAmount` | number | Sim | Valor desejado. Enviar `0` quando não houver valor específico. |
| `documentNumber` | string | Sim | CPF do cliente. |
| `desiredInstallments` | array | Sim | Parcelas desejadas montadas a partir de `periods`. |
| `desiredInstallments[].totalAmount` | number | Sim | Valor do período. |
| `desiredInstallments[].dueDate` | string | Sim | Data de vencimento no formato `YYYY-MM-DD`. |
| `provider` | string | Sim | Provider usado no fluxo. Valores: `bms`, `qi` ou `cartos`. |

### Exemplo de requisição

```http
POST /fgts/simulations HTTP/1.1
Host: bff.v8sistema.com
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "simulationFeesId": "cb563029-ba93-4b53-8d53-4ac145087212",
  "balanceId": "15d20e59-4b60-4e43-b4bf-741bd4a70302",
  "targetAmount": 0,
  "documentNumber": "00089909216",
  "desiredInstallments": [
    {
      "totalAmount": 180.16,
      "dueDate": "2030-06-01"
    },
    {
      "totalAmount": 124.50,
      "dueDate": "2031-06-01"
    }
  ],
  "provider": "bms"
}
```

### Exemplo de resposta de sucesso

Status HTTP:

```text
200 OK
```

Body:

```json
{
  "cet": 0.0242,
  "tacType": "percentual",
  "provider": "bms",
  "annualCet": 33.23,
  "availableBalance": 85.45,
  "emissionAmount": 117.67,
  "iof": 3.98,
  "tax": 1.8,
  "tc": 24,
  "totalBalance": 304.65999999999997,
  "totalInstallments": 2,
  "installments": [
    {
      "dueDate": "2030-06-01",
      "amount": 180.16
    },
    {
      "dueDate": "2031-06-01",
      "amount": 124.5
    }
  ],
  "insuranceAmount": 0,
  "id": "019e89d6-1997-7141-b010-ad54b90a00b8",
  "fixedInsurance": false
}
```

### Campos da resposta

| Campo | Tipo | Descrição |
|---|---:|---|
| `id` | string | Identificador único da simulação. |
| `provider` | string | Provider usado na simulação. |
| `availableBalance` | number | Valor líquido disponível para o cliente. |
| `emissionAmount` | number | Valor de emissão da operação. |
| `totalBalance` | number | Valor total considerado/bloqueado na operação. |
| `totalInstallments` | number | Quantidade de parcelas usadas na simulação. |
| `installments` | array | Parcelas utilizadas/retornadas pela simulação. |
| `installments[].dueDate` | string | Data da parcela. |
| `installments[].amount` | number | Valor da parcela. |
| `tax` | number | Taxa mensal aplicada. |
| `cet` | number | Custo efetivo total mensal. |
| `annualCet` | number | Custo efetivo total anual. |
| `iof` | number | Valor do IOF. |
| `tc` | number | Tarifa/custo da operação. |
| `tacType` | string | Tipo da TAC. |
| `insuranceAmount` | number | Valor de seguro, se houver. |
| `fixedInsurance` | boolean | Indica se há seguro fixo. |

---

## 12. Resumo dos campos obrigatórios por etapa

### Autenticação

```text
grant_type
username
password
audience
scope
client_id
```

### POST /fgts/balance

```text
documentNumber
provider
```

### GET /fgts/balance

```text
Nenhum query param obrigatório.
```

Filtros opcionais:

```text
search
startDate
endDate
limit
page
```

O parâmetro `search` não é obrigatório. Ele pode ser usado quando a consulta precisar localizar diretamente um CPF específico.

### GET /fgts/simulations/fees

```text
Nenhum parâmetro obrigatório além do token.
```

### POST /fgts/simulations

```text
simulationFeesId
balanceId
targetAmount
documentNumber
desiredInstallments
provider
```

---

## 13. Resumo de dependência entre endpoints

| Etapa | Endpoint | Saída necessária | Usada em |
|---:|---|---|---|
| 1 | `POST /oauth/token` | `access_token` | Todos os endpoints protegidos |
| 2 | `POST /fgts/balance` | Aceite da consulta (`null`) | Libera consulta posterior por CPF |
| 3 | `GET /fgts/balance` com filtros opcionais (`search`, `startDate`, `endDate`, `limit`, `page`) | `data[].id` | `balanceId` da simulação |
| 3 | `GET /fgts/balance` com filtros opcionais (`search`, `startDate`, `endDate`, `limit`, `page`) | `data[].periods` | `desiredInstallments` da simulação |
| 4 | `GET /fgts/simulations/fees` | `id_simulation_fees` da tabela `normal` | `simulationFeesId` da simulação |
| 5 | `POST /fgts/simulations` | `id`, `availableBalance`, `installments` | Resultado final da simulação |

---

## 14. Resumo dos status e decisões conhecidas

| Endpoint | Situação | Status HTTP | Body/Condição | Decisão |
|---|---|---:|---|---|
| `POST /oauth/token` | Sucesso | 200 | `access_token` presente | Prosseguir |
| `POST /oauth/token` | Falha | Qualquer outro | Qualquer erro | Job falhou |
| `POST /fgts/balance` | Sucesso | 200 | `null` | Consultar resultado depois via GET |
| `POST /fgts/balance` | Temporário | 400 | `title = BadRequestError` e `detail = Tente novamente` | Pode tentar novamente |
| `POST /fgts/balance` | Temporário | 400 | `title = AppError` e `detail = Ocorreu um erro inesperado` | Pode tentar novamente |
| `POST /fgts/balance` | Temporário | 400 | `title = AppError` e `detail` contém `Não foi possível consultar o saldo no momento!` | Pode tentar novamente |
| `POST /fgts/balance` | Regra de negócio | 400 | `detail` contém `Trabalhador não possui adesão ao saque aniversário vigente na data corrente` | Não tentar novamente |
| `POST /fgts/balance` | Regra de negócio | 400 | `detail` contém `não possui autorização do Trabalhador` | Não tentar novamente |
| `POST /fgts/balance` | Regra de negócio | 400 | `detail` contém `Existe uma Operação Fiduciária em andamento` | Não tentar novamente |
| `GET /fgts/balance` | Sucesso | 200 | `data[].status = success` | Usar `id` e `periods` |
| `GET /fgts/balance?startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>` | Sucesso | 200 | `data[].status = success` | Usar `id` e `periods` |
| `GET /fgts/balance` | Sucesso | 200 | `data[].status = success` | Usar `id` e `periods` |
| `GET /fgts/balance?startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>` | Sucesso | 200 | `data[].status = success` | Usar `id` e `periods` |
| `GET /fgts/balance?search=<CPF>` | Sucesso | 200 | `data[].status = success` | Usar `id` e `periods` |
| `GET /fgts/simulations/fees` | Sucesso | 200 | Lista de tabelas | Selecionar `label = normal` |
| `POST /fgts/simulations` | Sucesso | 200 | `id` da simulação presente | Simulação concluída |

---

## 15. Exemplo do fluxo completo com dados fictícios

### 1. Autenticar

```bash
curl --request POST 'https://auth.v8sistema.com/oauth/token' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'username=<email>' \
  --data-urlencode 'password=<senha>' \
  --data-urlencode 'audience=<audience>' \
  --data-urlencode 'scope=offline_access' \
  --data-urlencode 'client_id=<client_id>'
```

Resposta:

```json
{
  "access_token": "<jwt>",
  "scope": "",
  "expires_in": 86400,
  "token_type": "Bearer"
}
```

### 2. Iniciar consulta de saldo

```bash
curl --request POST 'https://bff.v8sistema.com/fgts/balance' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json' \
  --data '{
    "documentNumber": "00089909216",
    "provider": "bms"
  }'
```

Resposta:

```json
null
```

### 3. Consultar resultado do saldo

Consulta com filtros date-time e paginação, sem CPF:

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/balance?startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=1' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

Consulta por CPF:

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/balance?search=00089909216' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

Consulta por CPF com filtros date-time e paginação:

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/balance?search=00089909216&startDate=2026-04-24T00:00:00.000Z&endDate=2026-04-24T23:59:59.999Z&limit=10&page=1' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

Resposta:

```json
{
  "data": [
    {
      "id": "15d20e59-4b60-4e43-b4bf-741bd4a70302",
      "documentNumber": "00089909216",
      "partnerId": "7439_D019",
      "status": "success",
      "statusInfo": null,
      "createdAt": "2026-04-24T15:00:02.640Z",
      "updatedAt": "2026-04-24T15:00:02.640Z",
      "amount": 304.66,
      "provider": "bms",
      "periods": [
        {
          "amount": 180.16,
          "dueDate": "2030-06-01"
        },
        {
          "amount": 124.50,
          "dueDate": "2031-06-01"
        }
      ]
    }
  ],
  "pages": {
    "limit": 10,
    "total": 1,
    "current": 1,
    "hasNext": false,
    "hasPrev": false,
    "totalPages": 1
  }
}
```

Dados extraídos:

```text
balanceId = 15d20e59-4b60-4e43-b4bf-741bd4a70302
provider = bms
documentNumber = 00089909216
periods = lista de parcelas retornadas
```

### 4. Consultar tabelas de taxas

```bash
curl --request GET 'https://bff.v8sistema.com/fgts/simulations/fees' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json'
```

Selecionar a tabela:

```text
simulation_fees.label = normal
```

Exemplo:

```json
{
  "active": true,
  "default": false,
  "simulation_fees": {
    "id_simulation_fees": "cb563029-ba93-4b53-8d53-4ac145087212",
    "spread_value": 0,
    "annual_interest_fees": 0.23872053,
    "monthly_interest_rates": 1.8,
    "label": "normal"
  }
}
```

Dados extraídos:

```text
simulationFeesId = cb563029-ba93-4b53-8d53-4ac145087212
```

### 5. Criar simulação

```bash
curl --request POST 'https://bff.v8sistema.com/fgts/simulations' \
  --header 'Authorization: Bearer <access_token>' \
  --header 'Content-Type: application/json' \
  --data '{
    "simulationFeesId": "cb563029-ba93-4b53-8d53-4ac145087212",
    "balanceId": "15d20e59-4b60-4e43-b4bf-741bd4a70302",
    "targetAmount": 0,
    "documentNumber": "00089909216",
    "desiredInstallments": [
      {
        "totalAmount": 180.16,
        "dueDate": "2030-06-01"
      },
      {
        "totalAmount": 124.50,
        "dueDate": "2031-06-01"
      }
    ],
    "provider": "bms"
  }'
```

Resposta:

```json
{
  "cet": 0.0242,
  "tacType": "percentual",
  "provider": "bms",
  "annualCet": 33.23,
  "availableBalance": 85.45,
  "emissionAmount": 117.67,
  "iof": 3.98,
  "tax": 1.8,
  "tc": 24,
  "totalBalance": 304.65999999999997,
  "totalInstallments": 2,
  "installments": [
    {
      "dueDate": "2030-06-01",
      "amount": 180.16
    },
    {
      "dueDate": "2031-06-01",
      "amount": 124.5
    }
  ],
  "insuranceAmount": 0,
  "id": "019e89d6-1997-7141-b010-ad54b90a00b8",
  "fixedInsurance": false
}
```

Resultado principal da simulação:

```text
simulationId = 019e89d6-1997-7141-b010-ad54b90a00b8
availableBalance = 85.45
totalBalance = 304.66
totalInstallments = 2
provider = bms
```

---

## 16. Pontos de atenção

### 16.1 Não usar webhook

Embora a documentação da V8 mencione webhook para retorno de consulta de saldo, neste fluxo a integração deve consultar o resultado pelo endpoint:

```text
GET /fgts/balance
```

O endpoint aceita filtros opcionais. Exemplos:

```text
GET /fgts/balance?startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>
GET /fgts/balance?search=<CPF>
GET /fgts/balance?search=<CPF>&startDate=<START_DATE_TIME>&endDate=<END_DATE_TIME>&limit=<LIMIT>&page=<PAGE>
```

O parâmetro `search` não é obrigatório.

### 16.2 Não repetir POST de saldo sem necessidade

Após receber `200 OK` com `null` em `POST /fgts/balance`, a consulta foi aceita. O próximo passo é consultar o resultado com `GET /fgts/balance`, podendo usar `search`, `startDate`, `endDate`, `limit` e `page` conforme a necessidade.

### 16.3 Tabela normal

A simulação deve usar somente a tabela:

```text
normal
```

Não fixar o ID da tabela no código sem antes confirmar pelo endpoint de taxas, pois o ID pode variar.

### 16.4 Provider

Manter o mesmo provider entre as etapas:

```text
POST /fgts/balance
GET /fgts/balance
POST /fgts/simulations
```

Exemplo de provider usado nos testes:

```text
bms
```

### 16.5 CPF

Enviar CPF preferencialmente somente com números:

```text
00089909216
```

Evitar pontuação:

```text
000.899.092-16
```

### 16.6 Token

O token tem validade informada por:

```text
expires_in
```

No teste documentado, o valor retornado foi:

```text
86400 segundos
```

### 16.7 Logs

Evitar registrar em log:

```text
access_token
password
client_id
audience
```

Quando necessário, registrar apenas informações operacionais:

```text
CPF mascarado
endpoint
status HTTP
detail do erro
provider
status da consulta
id da consulta
id da simulação
```

---

## 17. Escopo fora desta documentação

Os endpoints abaixo existem na documentação original, mas estão fora do escopo desta automação porque o fluxo termina na simulação:

- `POST /fgts/proposal`
- `GET /banks`
- `POST /fgts/proposal/{id}/solvePendency`
- `PATCH /fgts/proposal/{id}/cancel`
- `DELETE /fgts/balance/cache/{documentNumber}`
- `GET /fgts/proposal`
- `GET /fgts/proposal/{id}`

Caso futuramente o escopo avance para criação de proposta, estes endpoints devem ser documentados em uma especificação separada.
