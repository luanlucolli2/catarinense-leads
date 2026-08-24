# Novo recurso: Disparos Catarinense via API VendeAI

## Objetivo

Realizar disparos a partir de:

- bases externas, copiando e colando uma lista de números; ou
- leads higienizados cadastrados no sistema, com filtro por banco, como no sistema
  de filtragem do módulo `backend/app/Modules/Lemit`.

Os disparos utilizarão a API da VendeAI.

## Requisitos

O fluxo do disparo deve ser dividido em etapas:

1. **Selecionar leads**

   - Subir uma lista de números de telefone; ou
   - filtrar leads higienizados cadastrados no sistema.
   - Ao filtrar leads cadastrados no sistema, considerar somente os que possuem
     número de telefone cadastrado.

2. **Configurar disparo**

   Configurar:

   - número utilizado;
   - templates;
   - ritmo;
   - horário; e
   - demais opções necessárias.

   Nesta etapa, deve ser possível visualizar o status e os metadados dos
   templates retornados pela VendeAI. A qualidade, o status e o nome verificado
   do número remetente dependerão de consulta à Meta vinculada à inbox.

3. **Confirmar disparo**

   Revisar a seleção e a configuração antes de criar o disparo.

Após a criação, o disparo ficará disponível no histórico para acompanhamento,
com opção de:

   - pausar;
   - cancelar; e
   - visualizar detalhes.

## Requisitos para a fase 2

- Permitir optar por não disparar para o mesmo telefone durante determinado
  período.
- Permitir selecionar somente templates Meta retornados como válidos pela
  VendeAI.
- Permitir limitar o número de envios por número.

## Limites das APIs integradas

- A VendeAI lista inboxes, números, templates, categoria, idioma e variáveis
  posicionais; o envio oficial recebe um template e `payload.params` por
  destinatário.
- Templates sem variáveis serão enviados com `params: {}`.
- Qualidade, status e nome verificado do número oficial não vêm da VendeAI.
  A consulta à Meta exigirá o relacionamento entre a inbox e o
  `phone_number_id` correspondente.
- Variáveis de header, botões/parâmetros de botões e mídia em header não serão
  implementados enquanto o formato de envio não estiver documentado pela
  VendeAI.
- Alternância de remetentes/templates, limites, intervalo, agendamento e
  proteção contra reenvio serão responsabilidades do backend, com fila,
  persistência e histórico próprios.

## Escopo inicial

Vamos trabalhar primeiro na etapa 1, usando como referência o que foi feito no
módulo abandonado Lemit.

A etapa 1 não utilizará as APIs da VendeAI — para números, templates e inbox —
nem a API da Meta — para obter a qualidade dos números oficiais —, pois consiste
somente na seleção dos leads que receberão os disparos.

## Decisões — protótipo frontend da etapa 1

- A página abrirá um modal com wizard de três passos: selecionar leads,
  configurar disparo e confirmar.
- O acompanhamento não fará parte do modal de criação. Depois de criado, o
  disparo será acessado pelo histórico para consulta de detalhes, pausa e
  cancelamento.
- Nesta entrega os três passos do wizard serão prototipados; a confirmação
  revisará a operação e exigirá ciência do operador antes da ação final.
- A origem poderá ser uma lista de números colada ou leads cadastrados.
- A lista colada aceitará quebras de linha, vírgulas e ponto e vírgula; aceitará
  celulares brasileiros de 11 dígitos com ou sem o prefixo `+55`, removerá
  duplicados e indicará entradas inválidas.
- A origem de leads cadastrados terá todos os filtros atuais do Lemit para
  Facta CLT, CLT Mercantil e CLT UY3. A seleção final será limitada a leads que
  possuem telefone.
- O protótipo não fará chamadas de API nem persistência de dados; a prévia de
  quantidade será simulada e o estado existirá somente enquanto o modal estiver
  aberto.

## Decisões — protótipo frontend da etapa 2

- O wizard liberará a configuração somente após uma seleção válida: a lista
  colada deverá ter ao menos um celular válido e nenhum inválido; bancos
  selecionados deverão ter ao menos um filtro próprio.
- O protótipo usará inboxes e templates simulados de WhatsApp oficial. A
  integração futura carregará da VendeAI id, nome, número, templates, categoria,
  idioma e variáveis posicionais.
- Nome verificado, status e qualidade do número seguirão os dados disponíveis
  na Cloud API da Meta, após mapeamento da inbox para o `phone_number_id`.
  Qualidade de template não fará parte da interface enquanto não for retornada
  por uma API integrada.
- Cada parâmetro de template poderá receber valor fixo ou dado do lead. Para
  lista colada, somente telefone estará disponível; para leads cadastrados,
  também nome, CPF e data de nascimento.
- O passo incluirá produto obrigatório, campanha opcional, intervalo em
  segundos e início imediato ou agendado. Será possível selecionar múltiplos
  números remetentes e definir o limite de envios individualmente para cada
  número. A soma desses limites deverá ser suficiente para atender todos os
  destinatários conhecidos do disparo.
- Cada inbox representa um único número remetente e possui seu próprio conjunto
  de templates. Ao selecionar múltiplos números, cada remetente exibirá apenas
  os seus templates disponíveis. Inboxes sem templates deverão exibir um estado
  vazio orientando a revisão da seleção.
- Será possível selecionar múltiplos templates para alternância entre os
  destinatários. No protótipo, a rotação seguirá a ordem de seleção e cada
  template terá sua própria configuração de parâmetros.
- A proteção contra reenvio ao mesmo destinatário iniciará desativada e, quando
  ativada, exigirá um período positivo.
- Não haverá envio, agendamento, persistência, cadastro de leads na VendeAI ou
  consulta à Meta nesta etapa. A ação final da confirmação permanecerá bloqueada
  até a integração do backend.
