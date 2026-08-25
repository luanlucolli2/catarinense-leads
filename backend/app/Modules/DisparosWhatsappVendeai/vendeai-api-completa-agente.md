# VendeAI API — referência completa para agente

> Fonte: documentação de APIs da VendeAI fornecida em PDF.
>
> Objetivo deste arquivo: consolidar, em formato textual e estruturado, tudo o que a documentação fornecida descreve sobre credenciais server-side, disparo de mensagens, cadastro de base de campanha e webhook outbound.
>
> **Nota de segurança:** o PDF original exibe credenciais reais. O token CRM foi deliberadamente substituído por placeholders neste arquivo. Nunca versionar tokens reais em Git, collections compartilhadas, prompts ou documentação.

---

## 1. Visão geral

### Base API

```text
https://ia.vendeaitecnologia.com.br
```

### Credenciais server-side

A documentação exibe:

- `account_id`: identificador da conta na VendeAI.
- `crm_api_access_token`: token de API do Chatwoot salvo na conta.
- `account_id` e `crm_api_access_token` devem corresponder à mesma conta.
- Quando o token não corresponde ao cadastro, a API pode responder `403 Unauthorized`.

No PDF fornecido, o `account_id` exibido é:

```text
22734
```

O token CRM existente no PDF não é reproduzido neste arquivo.

### Convenção usada nos exemplos deste arquivo

```json
{
  "account_id": "{{account_id}}",
  "crm_api_access_token": "{{crm_api_access_token}}"
}
```

---

# 2. Disparo de mensagem

A documentação descreve dois modos de envio:

1. `whatsapp_oficial`
   - usa template aprovado na Meta;
   - pode receber variáveis posicionais;
   - pode receber cabeçalho de texto ou mídia, conforme o template.

2. `whatsapp_nao_oficial`
   - texto simples;
   - destinado a inbox de canal não oficial, como API Evolution;
   - não usa template Meta.

Fluxo documentado:

```text
POST /api/message-handler/mailing/inboxes/
        ↓
obter inbox_id + número + templates e metadados
        ↓
POST /api/message-handler/mailing/send/
        ↓
enviar usando whatsapp_oficial ou whatsapp_nao_oficial
```

---

## 2.1. Listar inboxes e templates

### Endpoint

```http
POST /api/message-handler/mailing/inboxes/
```

### Objetivo

Retorna as inboxes da conta no Chatwoot com templates Meta válidos para disparo.

A própria documentação orienta usar:

- o `id` da inbox;
- os metadados do template;

no fluxo posterior de envio.

### Request

#### Campos

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---:|---|
| `account_id` | `string` | Sim | Identificador da conta na VendeAI. Deve corresponder à conta dona do `crm_api_access_token`. |
| `crm_api_access_token` | `string` | Sim | Token de API do Chatwoot salvo na conta. Se não corresponder ao cadastro, a API responde `403 Unauthorized`. |

#### Exemplo

```json
{
  "account_id": "{{account_id}}",
  "crm_api_access_token": "{{crm_api_access_token}}"
}
```

#### cURL

```bash
curl -sS -X POST \
  'https://ia.vendeaitecnologia.com.br/api/message-handler/mailing/inboxes/' \
  -H 'Content-Type: application/json' \
  -d '{
    "account_id": "{{account_id}}",
    "crm_api_access_token": "{{crm_api_access_token}}"
  }'
```

### Response `200`

A resposta contém:

```text
inboxes[]
```

Cada item de `inboxes` inclui, segundo a documentação:

- `id`
- `name`
- `channel`
- `phone_number`
- `templates`

A documentação afirma que `templates` contém informações como:

- `id`
- nome
- categoria
- idioma
- variáveis
- outros metadados do template

### Exemplo ilustrativo da documentação

```json
{
  "inboxes": [
    {
      "id": "123",
      "name": "Atendimento",
      "channel": "whatsapp",
      "phone_number": "+5511999999999",
      "templates": [
        {
          "id": "456",
          "name": "meu_template",
          "category": "UTILITY",
          "language": "pt_BR",
          "variables": ["1"],
          "header_type": null,
          "body": "Olá {{1}}!"
        }
      ]
    }
  ]
}
```

### Interpretação dos campos exibidos

#### Inbox

```text
id
```

ID usado posteriormente como `inbox_id` no endpoint de envio.

```text
name
```

Nome da inbox no Chatwoot.

```text
channel
```

Canal da inbox.

```text
phone_number
```

Número associado à inbox.

```text
templates
```

Templates Meta retornados para aquela inbox.

#### Template

O exemplo da documentação expõe:

```text
id
name
category
language
variables
header_type
body
```

Exemplo de variável posicional:

```json
"variables": ["1"]
```

com:

```text
Olá {{1}}!
```

### Erros documentados

| HTTP | Situação |
|---:|---|
| `400` | payload/conta inválidos |
| `403` | token incorreto |
| `502` | falha na comunicação com a VendeAI |

---

## 2.2. Enviar mensagem

### Endpoint

```http
POST /api/message-handler/mailing/send/
```

### Objetivo

Envia uma mensagem para um número.

O campo:

```text
payload.type
```

define o modo:

```text
whatsapp_oficial
```

ou:

```text
whatsapp_nao_oficial
```

---

## 2.2.1. Campos raiz do envio

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---:|---|
| `account_id` | `string` | Sim | Identificador da conta VendeAI. Deve corresponder à conta dona do `crm_api_access_token`. |
| `crm_api_access_token` | `string` | Sim | Token de API do Chatwoot salvo na conta. |
| `inbox_id` | `string` | Sim | ID da inbox no Chatwoot de onde a mensagem será enviada. Obter em `inboxes[].id`. |
| `customer_phone` | `string` | Sim | Telefone do cliente em formato internacional, por exemplo `+5511999999999` (E.164). O CRM cria/abre a conversa nesse número. |
| `payload` | `object` | Sim | Conteúdo da mensagem. O formato depende de `payload.type`. |

### Observação importante

A documentação diz explicitamente que:

```text
customer_phone
```

faz com que o CRM crie/abra a conversa nesse número.

Isso é relevante para integrações que dependem do Chatwoot/VendeAI para continuar o atendimento após o disparo.

---

# 3. WhatsApp oficial — template Meta

## 3.1. `payload.type`

Valor fixo:

```json
"type": "whatsapp_oficial"
```

É o discriminador para envio usando template aprovado na Meta.

---

## 3.2. Campos do payload oficial

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---:|---|
| `type` | `"whatsapp_oficial"` | Sim | Discriminador fixo para template aprovado na Meta. |
| `message` | `string` | Sim | Texto de referência/espelho do corpo do template; útil para logs e consistência com o que foi aprovado. |
| `template_name` | `string` | Sim | Nome do template exatamente como na Meta/Chatwoot. |
| `template_category` | `string` | Sim | Categoria do template. Exemplos documentados: `UTILITY`, `MARKETING`. |
| `language` | `string` | Não | Código do idioma. Padrão informado pelo backend: `pt_BR`. |
| `params` | `object` | Não | Variáveis posicionais do template. Chaves `"1"`, `"2"`, etc., com valores `string`. Enviar `{}` quando o template não tiver variáveis. |
| `header_url` | `string` | Condicional | URL pública da mídia do cabeçalho. Obrigatória se `header_type` do template for `IMAGE`, `VIDEO` ou `DOCUMENT`. |
| `header_media_type` | `"image"` \| `"video"` \| `"document"` | Não | Tipo da mídia enviada em `header_url`. Deve corresponder ao `header_type` em minúsculas. Padrão: `image`. |
| `header_text` | `string` | Condicional | Valor da variável do cabeçalho quando `header_type` for `TEXT`. Não pode ser combinado com `header_url`. |

---

## 3.3. Variáveis do corpo

A documentação usa `params` para preencher variáveis posicionais.

Template:

```text
Olá {{1}}, valor disponível: {{2}}
```

Payload:

```json
"params": {
  "1": "João",
  "2": "4500,00"
}
```

Regras documentadas:

- as chaves são strings;
- usam posição `"1"`, `"2"`, etc.;
- os valores são strings;
- template sem variável deve usar:

```json
"params": {}
```

---

## 3.4. Campo `message`

Mesmo quando um template é usado, a API exige:

```json
"message": "..."
```

A documentação define esse campo como:

```text
Texto de referência (espelho do corpo do template);
útil para logs e consistência com o que foi aprovado.
```

Portanto, no contrato documentado:

```text
template_name
```

identifica o template;

```text
params
```

fornece os valores posicionais;

e:

```text
message
```

é o texto de referência/espelho exigido pela VendeAI.

---

# 4. Header/cabeçalho em template oficial

A documentação completa confirma suporte explícito a cabeçalhos.

---

## 4.1. Header de imagem

Quando o template retornado pela listagem de inboxes tiver:

```text
header_type = IMAGE
```

o envio exige:

```json
"header_url": "https://seu-dominio.com/imagem.jpg",
"header_media_type": "image"
```

A URL:

- precisa ser pública;
- precisa ser acessível sem autenticação.

Se o template exige mídia no header e `header_url` não é enviado, a documentação informa que a Meta pode recusar com:

```text
(#132012) Parameter format does not match
```

---

## 4.2. Header de vídeo

Para:

```text
header_type = VIDEO
```

usar:

```json
"header_url": "https://seu-dominio.com/video.mp4",
"header_media_type": "video"
```

A URL precisa ser pública e sem autenticação.

---

## 4.3. Header de documento

Para:

```text
header_type = DOCUMENT
```

usar:

```json
"header_url": "https://seu-dominio.com/documento.pdf",
"header_media_type": "document"
```

A URL precisa ser pública e sem autenticação.

---

## 4.4. Header de texto

Quando:

```text
header_type = TEXT
```

usar:

```json
"header_text": "valor do cabeçalho"
```

A documentação diz que `header_text`:

- é condicional;
- representa o valor da variável do cabeçalho;
- não pode ser combinado com `header_url`;
- um template tem no máximo um cabeçalho.

---

## 4.5. Exemplo oficial com mídia no header

Exemplo consolidado a partir do JSON apresentado na documentação:

```json
{
  "account_id": "{{account_id}}",
  "crm_api_access_token": "{{crm_api_access_token}}",
  "inbox_id": "ID_DA_INBOX_NO_CHATWOOT",
  "customer_phone": "+5511999999999",
  "payload": {
    "type": "whatsapp_oficial",
    "message": "Texto de referência / espelho do corpo do template",
    "template_name": "nome_do_template_aprovado_meta",
    "template_category": "UTILITY",
    "language": "pt_BR",
    "params": {
      "1": "João",
      "2": "4500,00"
    },
    "header_url": "https://seu-dominio.com/imagem.jpg",
    "header_media_type": "image"
  }
}
```

---

# 5. Botões de template

## Situação na documentação fornecida

A documentação fornecida **não descreve um campo de envio específico para botões**.

Não há contrato documentado para campos como:

```text
buttons
button_params
button_url
button_payload
components
```

no `POST /api/message-handler/mailing/send/`.

Portanto, com base exclusivamente no PDF:

- corpo com variáveis: documentado;
- header de texto: documentado;
- header de imagem: documentado;
- header de vídeo: documentado;
- header de documento: documentado;
- botões: **formato de envio não documentado**.

Um agente **não deve inventar** um payload de botão sem documentação adicional ou teste validado.

---

# 6. WhatsApp não oficial

## 6.1. Quando usar

A documentação afirma:

```text
Somente quando a inbox for de canal não oficial
(API Evolution / sem template Meta).
Caso contrário use whatsapp_oficial.
```

---

## 6.2. Payload

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---:|---|
| `type` | `"whatsapp_nao_oficial"` | Sim | Discriminador para envio em texto simples em canal não oficial. |
| `message` | `string` | Sim | Corpo da mensagem em texto livre. |

### Exemplo

```json
{
  "account_id": "{{account_id}}",
  "crm_api_access_token": "{{crm_api_access_token}}",
  "inbox_id": "ID_DA_INBOX",
  "customer_phone": "+5511999999999",
  "payload": {
    "type": "whatsapp_nao_oficial",
    "message": "Olá! Mensagem em texto livre (canal não oficial)."
  }
}
```

---

# 7. Response do envio

## Sucesso

HTTP:

```text
200
```

Formato documentado:

```json
{
  "status": "success",
  "message_id": "..."
}
```

### Interpretação estrita

A documentação somente define esse retorno como sucesso da chamada.

Ela **não documenta**, neste endpoint:

- `sent`;
- `delivered`;
- `read`;
- `failed`;
- motivo de falha posterior;
- webhook de delivery específico para esse `message_id`.

Portanto, um agente não deve tratar `200 + status=success` como prova documentada de entrega no aparelho do destinatário.

### Erro documentado

| HTTP | Situação |
|---:|---|
| `502` | falha na comunicação com a VendeAI |

### cURL oficial documentado, sanitizado

```bash
curl -sS -X POST \
  'https://ia.vendeaitecnologia.com.br/api/message-handler/mailing/send/' \
  -H 'Content-Type: application/json' \
  -d '{
    "account_id": "{{account_id}}",
    "crm_api_access_token": "{{crm_api_access_token}}",
    "inbox_id": "ID_DA_INBOX",
    "customer_phone": "+5511999999999",
    "payload": {
      "type": "whatsapp_oficial",
      "message": "Texto de referência do template",
      "template_name": "nome_do_template_meta",
      "template_category": "UTILITY",
      "language": "pt_BR",
      "params": {
        "1": "NomeCliente"
      }
    }
  }'
```

---

# 8. Cadastro de base de campanha

## Finalidade

A documentação descreve esse recurso para cadastrar dados já conhecidos de leads por telefone.

Dados citados:

- CPF;
- nome;
- nascimento;
- e-mail;
- produto;
- campanha.

O objetivo é permitir que, quando o telefone falar com a IA pela primeira vez:

- dados já conhecidos possam ser reutilizados;
- perguntas já respondidas possam ser puladas;
- para alguns produtos, o fluxo possa ir direto para a simulação.

A documentação menciona explicitamente uso por parceiros que realizam disparos fora da VendeAI.

---

## 8.1. Regras de retenção e limites

### Rate limit

```text
10/min
```

e:

```text
1000/dia
```

por:

```text
account_id
```

### Vida útil do lead

Cada lead vive por:

```text
7 dias
```

Depois é removido automaticamente.

### Upsert

Reenviar o mesmo telefone:

- renova o prazo de 7 dias;
- atualiza os dados.

A documentação recomenda manter a base sincronizada reenviando periodicamente enquanto a campanha estiver ativa.

---

## 8.2. Endpoint

```http
POST /api/message-handler/mailing/leads/
```

### Comportamento

Faz upsert em lote por:

```text
(account_id, phone)
```

Itens inválidos:

- não derrubam o lote inteiro;
- itens válidos continuam sendo salvos;
- itens inválidos são retornados em `failing_reasons`.

---

## 8.3. Campos raiz

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---:|---|
| `account_id` | `string` | Sim | Identificador da conta na VendeAI. |
| `crm_api_access_token` | `string` | Sim | Token de API do Chatwoot. Se não corresponder ao cadastro, retorna `403 Unauthorized`. |
| `leads` | `array` | Sim | Lista de leads a cadastrar/atualizar. Máximo de `20000` itens por chamada. |

---

## 8.4. Campos de `leads[]`

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---:|---|
| `phone` | `string` | Sim | Celular brasileiro, DDD + 9 dígitos. Aceita com ou sem `+55`. |
| `product` | `string` | Sim | Produto VendeAI para o qual o contato é lead. Exemplos: `clt`, `fgts`. |
| `campaign` | `string` | Não | Nome da campanha. É atribuído ao contato por telefone quando ele fala com a IA, salvo se um link de WhatsApp ou a mensagem inicial definir outra campanha. |
| `cpf` | `string` | Não | Com ou sem pontuação. Validado por checksum. |
| `name` | `string` | Não | Nome completo do lead. |
| `birth_date` | `string` | Não | Formato `DD/MM/AAAA`. |
| `email` | `string` | Não | E-mail do lead. |

---

## 8.5. Exemplo de request

```json
{
  "account_id": "{{account_id}}",
  "crm_api_access_token": "{{crm_api_access_token}}",
  "leads": [
    {
      "phone": "11987654321",
      "product": "clt",
      "campaign": "black_friday_clt",
      "cpf": "11144477735",
      "name": "Nome Completo",
      "birth_date": "01/01/1990",
      "email": "cliente@example.com"
    },
    {
      "phone": "11987654322",
      "product": "fgts"
    }
  ]
}
```

---

## 8.6. cURL, sanitizado

```bash
curl -sS -X POST \
  'https://ia.vendeaitecnologia.com.br/api/message-handler/mailing/leads/' \
  -H 'Content-Type: application/json' \
  -d '{
    "account_id": "{{account_id}}",
    "crm_api_access_token": "{{crm_api_access_token}}",
    "leads": [
      {
        "phone": "11987654321",
        "product": "clt",
        "cpf": "11144477735",
        "name": "Nome Completo",
        "birth_date": "01/01/1990",
        "email": "cliente@example.com"
      }
    ]
  }'
```

---

## 8.7. Response

HTTP:

```text
200
```

A documentação informa que:

```text
success_count_mailings
```

e:

```text
fail_count_mailings
```

somam o total de itens enviados no lote.

### Exemplo

```json
{
  "success": true,
  "success_count_mailings": 1,
  "fail_count_mailings": 1,
  "failing_reasons": [
    {
      "index": 1,
      "reason": "MISSING_PRODUCT"
    }
  ]
}
```

---

## 8.8. Erros HTTP documentados

| HTTP | Situação |
|---:|---|
| `400` | payload inválido ou mais de `20000` itens |
| `403` | token incorreto |
| `429` | rate limit |

---

## 8.9. Valores de `failing_reasons[].reason`

| Valor | Significado |
|---|---|
| `INVALID_CPF` | CPF presente, mas com formato/checksum inválido. |
| `INVALID_PHONE` | Telefone ausente ou fora do formato de celular brasileiro. |
| `INVALID_EMAIL` | E-mail presente, mas com formato inválido. |
| `INVALID_BIRTH_DATE` | Data de nascimento fora do formato `DD/MM/AAAA`, no futuro ou com idade implausível. |
| `MISSING_PHONE` | Telefone não informado no item. |
| `MISSING_PRODUCT` | Produto não informado ou não é um produto válido da VendeAI. |

---

# 9. Webhook outbound — VendeAI → seu servidor

## 9.1. Configuração

A documentação orienta configurar a URL em:

```text
Configuração de produtos → Webhook
```

A VendeAI faz:

```http
POST
```

com JSON quando ocorrem eventos relacionados a:

- estágio;
- tags;
- proposta;
- status de proposta;
- simulação;
- criação de conversa inbound.

---

## 9.2. Requisitos do endpoint receptor

### HTTP obrigatório

Seu endpoint precisa responder:

```text
HTTP 200
```

na linha de status.

O corpo da resposta:

```text
não é validado
```

### Falhas

Outros códigos HTTP:

- contam como falha;
- incrementam o contador de falhas.

### Desativação automática

Após:

```text
10 falhas consecutivas
```

a VendeAI remove a URL do webhook.

Para voltar a usar, é necessário reconfigurá-la em:

```text
Configuração de produtos
```

### Content-Type

```http
Content-Type: application/json
```

### Assinatura

A documentação afirma explicitamente:

```text
Não enviamos cabeçalho de assinatura.
```

Recomendação presente na própria documentação:

- usar HTTPS;
- se necessário, usar um token na própria URL.

---

# 10. Eventos de webhook

## 10.1. `stage_updated`

Significado:

```text
O estágio do funil da conversa foi alterado.
```

---

## 10.2. `tag_updated`

Significado:

```text
A lista de tags da conversa foi alterada.
```

---

## 10.3. `proposal_created`

Significado:

```text
Uma proposta foi criada.
```

O campo:

```text
proposal
```

traz:

- valores financeiros;
- link de formalização;
- outros dados documentados na seção de `proposal`.

---

## 10.4. `proposal_status_updated`

Significado:

```text
O status persistido da proposta mudou.
```

O corpo inclui:

```text
proposal_status
```

com:

- `proposal_id`
- `proposal_number`
- novo `proposal_status`
- `previous_proposal_status`
- `table_name`
- `table_id`

A documentação destaca que esse evento não duplica os dados financeiros de:

```text
proposal_created
```

---

## 10.5. `simulation_offered`

Significado:

```text
Oferta gerada (tag ofertado).
```

O campo:

```text
simulation
```

traz a simulação para produtos como:

```text
CLT
FGTS
```

Pode ser:

```text
null
```

se não houver simulação.

---

## 10.6. `conversation_created`

Significado:

```text
Uma nova conversa inbound foi iniciada por um cliente.
```

O campo:

```text
first_message
```

traz o texto da primeira mensagem enviada pelo cliente.

Pode ser:

```text
null
```

---

# 11. Objeto `chat_summary`

Campos documentados:

## `account_id`

```text
Identificador da conta na VendeAI.
```

## `chat_id`

```text
ID da conversa no CRM integrado.
```

## `product`

Produto.

Exemplos:

```text
fgts
clt
```

## `stage`

Estágio atual.

Pode ser:

```text
null
```

## `details`

Contém os blocos:

```text
contact
session
referral
```

A documentação especifica que as chaves são em inglês.

### `details.referral`

Pode conter dados de origem do clique em anúncio CTWA:

```text
ctwa_clid
referral_body
ctwa_source_id
ctwa_source_type
```

## `tags`

Tags após a mudança.

---

# 12. Campo `proposal`

É enviado somente em:

```text
proposal_created
```

A documentação descreve:

| Campo | Descrição |
|---|---|
| `proposal_id` | ID interno da proposta. |
| `proposal_number` | Número legível. |
| `proposal_status` | Status, por exemplo `FORMALIZATION_REQUESTED`. |
| `bank` | Código do banco. |
| `liquid_value` | Valor líquido. |
| `formalization_link` | Link de assinatura/formalização. |

---

# 13. Campo `proposal_status`

É enviado somente em:

```text
proposal_status_updated
```

Campos:

| Campo | Descrição |
|---|---|
| `proposal_id` | ID interno da proposta. |
| `proposal_number` | Número legível; pode ser `null`. |
| `proposal_status` | Novo status após o save. |
| `previous_proposal_status` | Status que estava no banco antes da mudança. |
| `table_name` | Nome da tabela da proposta. |
| `table_id` | ID da tabela da proposta. |

---

# 14. Campo `simulation`

É enviado em:

```text
simulation_offered
```

Pode ser:

```text
null
```

se não houver simulação.

Campos:

| Campo | Descrição |
|---|---|
| `product` | Produto da simulação. |
| `bank` | Banco. |
| `liquid_value` | Valor líquido. |
| `table_name` | Nome da tabela. |
| `table_id` | ID da tabela. |

---

# 15. Campo `first_message`

É enviado somente em:

```text
conversation_created
```

Contém:

```text
texto da primeira mensagem enviada pelo cliente
```

Pode ser:

```text
null
```

---

# 16. Exemplo documentado — `stage_updated`

```json
{
  "event": "stage_updated",
  "chat_summary": {
    "account_id": "22734",
    "chat_id": "12345",
    "product": "fgts",
    "stage": "simulation",
    "details": {
      "contact": {
        "name": "Maria Silva",
        "phone": "5547999998888",
        "email": null,
        "cpf": "123.456.789-00",
        "birth_date": "1990-05-10",
        "mother_name": null
      },
      "session": {
        "campaign": null,
        "inbox_phone_number": "+5547888777666",
        "product_being_processed": "fgts"
      },
      "referral": {
        "ctwa_clid": null,
        "referral_body": null,
        "ctwa_source_id": null,
        "ctwa_source_type": null
      }
    },
    "tags": [
      "fgts",
      "simulado"
    ]
  }
}
```

---

# 17. Resumo dos endpoints

| Método | Endpoint | Finalidade |
|---|---|---|
| `POST` | `/api/message-handler/mailing/inboxes/` | Listar inboxes e templates Meta disponíveis para disparo. |
| `POST` | `/api/message-handler/mailing/send/` | Enviar mensagem por WhatsApp oficial ou não oficial. |
| `POST` | `/api/message-handler/mailing/leads/` | Cadastrar/atualizar base de leads por upsert. |
| `POST` outbound | URL configurada pelo cliente | Receber eventos da VendeAI via webhook. |

---

# 18. Regras operacionais consolidadas para um agente

## 18.1. Antes de enviar WhatsApp oficial

1. Chamar:

```http
POST /api/message-handler/mailing/inboxes/
```

2. Selecionar a inbox desejada.

3. Usar:

```text
inboxes[].id
```

como:

```text
inbox_id
```

4. Selecionar um template retornado para a inbox.

5. Preservar os metadados do template usados no envio:

```text
template_name
template_category
language
variables
header_type
```

6. Preencher `params` conforme as variáveis posicionais.

7. Se não houver variáveis:

```json
"params": {}
```

8. Se o template tiver header de mídia, enviar `header_url` e `header_media_type`.

9. Se o template tiver header de texto, enviar `header_text`.

10. Não combinar:

```text
header_text
```

com:

```text
header_url
```

---

## 18.2. Antes de enviar WhatsApp não oficial

Confirmar que a inbox é de canal não oficial.

A documentação cita:

```text
API Evolution / sem template Meta
```

Usar:

```json
{
  "type": "whatsapp_nao_oficial",
  "message": "texto livre"
}
```

---

## 18.3. Ao cadastrar leads

O agente deve:

- respeitar máximo de `20000` itens por chamada;
- respeitar `10/min`;
- respeitar `1000/dia` por `account_id`;
- considerar validade de `7 dias`;
- reenviar telefone para renovar/atualizar o lead;
- tratar falhas item a item em `failing_reasons`;
- não considerar o lote inteiro inválido apenas porque alguns itens falharam.

---

## 18.4. Ao receber webhook

O endpoint receptor deve:

- aceitar `POST`;
- aceitar JSON;
- responder HTTP `200`;
- não depender de assinatura em header, pois a documentação informa que ela não existe;
- considerar autenticação própria por HTTPS/token na URL se necessário;
- evitar 10 falhas consecutivas para não ter a URL removida.

---

# 19. Matriz de suporte documentado

| Capacidade | Situação na documentação |
|---|---|
| Listar inboxes | Suportado |
| Obter número de telefone da inbox | Suportado |
| Listar templates Meta da inbox | Suportado |
| Template oficial | Suportado |
| Categoria do template | Suportado |
| Idioma do template | Suportado |
| Variáveis posicionais no corpo | Suportado via `params` |
| Template sem variável | Suportado com `params: {}` |
| Header de texto | Suportado via `header_text` |
| Header de imagem | Suportado via `header_url` + `header_media_type=image` |
| Header de vídeo | Suportado via `header_url` + `header_media_type=video` |
| Header de documento | Suportado via `header_url` + `header_media_type=document` |
| Botões de template | **Não documentado no payload de envio** |
| Texto livre em canal não oficial | Suportado |
| Texto livre em canal oficial | **Não documentado neste endpoint** |
| Cadastro de leads em lote | Suportado |
| Upsert por `(account_id, phone)` | Suportado |
| Rate limit da base de leads | Documentado |
| TTL de leads | 7 dias |
| Webhook outbound | Suportado |
| Assinatura criptográfica no webhook | Não existe segundo a documentação |
| Evento de mudança de estágio | Suportado |
| Evento de mudança de tags | Suportado |
| Evento de criação de proposta | Suportado |
| Evento de mudança de status da proposta | Suportado |
| Evento de simulação ofertada | Suportado |
| Evento de criação de conversa inbound | Suportado |

---

# 20. Pontos não documentados ou não garantidos pela fonte

Os itens abaixo **não devem ser presumidos por um agente** porque o PDF fornecido não define o comportamento necessário.

## 20.1. Botões

Não existe formato documentado para:

```text
buttons
button_params
quick_reply
CTA
URL dinâmica de botão
```

no endpoint de envio.

---

## 20.2. Qualidade do número

A documentação da VendeAI fornecida não expõe campo equivalente a:

```text
quality_rating
```

para o número de WhatsApp.

---

## 20.3. Identificação explícita de inbox oficial vs. não oficial na listagem

O endpoint documenta:

```text
id
name
channel
phone_number
templates
```

mas a fonte não descreve um campo explícito como:

```text
is_official
provider
channel_type
```

para diferenciar todas as inboxes de forma inequívoca.

A regra de envio, entretanto, diferencia:

```text
whatsapp_oficial
```

e:

```text
whatsapp_nao_oficial
```

e diz que `whatsapp_nao_oficial` é somente para canal não oficial.

---

## 20.4. Rate limit de `/mailing/send/`

O PDF fornecido não informa um rate limit específico para:

```http
POST /api/message-handler/mailing/send/
```

O rate limit explícito de:

```text
10/min
1000/dia
```

é apresentado na seção de cadastro de base de campanha/leads.

---

## 20.5. Confirmação de entrega da mensagem

O `/mailing/send/` documenta:

```json
{
  "status": "success",
  "message_id": "..."
}
```

mas não documenta estados posteriores de entrega/leitura.

---

## 20.6. Autenticação do webhook por assinatura

A documentação afirma explicitamente que:

```text
não envia cabeçalho de assinatura
```

Portanto, não inventar validação HMAC/assinatura baseada em header sem contrato adicional.

---

# 21. Formatos importantes

## Telefone para envio

```text
E.164
```

Exemplo:

```text
+5511999999999
```

## Telefone em leads

Celular brasileiro:

```text
DDD + 9 dígitos
```

Aceita:

```text
11987654321
```

ou:

```text
+5511987654321
```

conforme a regra descrita.

## Data de nascimento em leads

```text
DD/MM/AAAA
```

## Idioma padrão do envio oficial

```text
pt_BR
```

## Categoria de template — exemplos

```text
UTILITY
MARKETING
```

## Tipos de header de mídia

Template:

```text
IMAGE
VIDEO
DOCUMENT
```

Payload:

```text
image
video
document
```

---

# 22. Sugestão de modelo interno de integração

> Esta seção apenas organiza os campos documentados; não adiciona endpoints novos.

Um agente pode representar uma inbox como:

```json
{
  "id": "123",
  "name": "Atendimento",
  "channel": "whatsapp",
  "phone_number": "+5511999999999",
  "templates": []
}
```

Um template oficial pode ser tratado internamente com os campos documentados:

```json
{
  "id": "456",
  "name": "meu_template",
  "category": "UTILITY",
  "language": "pt_BR",
  "variables": ["1"],
  "header_type": null,
  "body": "Olá {{1}}!"
}
```

Um lead pode ser tratado como:

```json
{
  "phone": "11987654321",
  "product": "clt",
  "campaign": "campanha",
  "cpf": "11144477735",
  "name": "Nome Completo",
  "birth_date": "01/01/1990",
  "email": "cliente@example.com"
}
```

---

# 23. Checklist para implementação por agente

- [ ] Usar `https://ia.vendeaitecnologia.com.br` como base.
- [ ] Nunca expor `crm_api_access_token` no frontend.
- [ ] Garantir que `account_id` e token pertençam à mesma conta.
- [ ] Consultar `/mailing/inboxes/` antes de depender de inbox/template.
- [ ] Usar `inboxes[].id` como `inbox_id`.
- [ ] Formatar `customer_phone` em E.164.
- [ ] Usar `whatsapp_oficial` somente com template Meta.
- [ ] Manter `message` no payload oficial porque é obrigatório no contrato.
- [ ] Preencher `params` com chaves posicionais em string.
- [ ] Enviar `{}` quando o template não tiver variáveis.
- [ ] Respeitar `header_type`.
- [ ] Para mídia, usar URL pública sem autenticação.
- [ ] Não combinar `header_text` e `header_url`.
- [ ] Não inventar suporte a botões.
- [ ] Usar `whatsapp_nao_oficial` apenas em inbox não oficial.
- [ ] No cadastro de leads, respeitar `20000` itens por chamada.
- [ ] Respeitar `10/min` e `1000/dia` no cadastro de leads.
- [ ] Considerar TTL de 7 dias.
- [ ] Tratar `failing_reasons` por item.
- [ ] Webhook deve responder HTTP 200.
- [ ] Considerar remoção do webhook após 10 falhas consecutivas.
- [ ] Não esperar assinatura em header do webhook.
- [ ] Não assumir que `message_id` significa entrega confirmada.

---

# 24. Fonte e escopo

Este arquivo foi construído exclusivamente a partir do conteúdo da documentação VendeAI fornecida no PDF.

Não foram adicionados endpoints da Meta, Chatwoot, Evolution, Gupshup ou outras APIs externas.

Quando a documentação não especifica um comportamento, este arquivo o marca como **não documentado**, em vez de inferir um contrato.

Credenciais reais presentes no PDF foram sanitizadas.
