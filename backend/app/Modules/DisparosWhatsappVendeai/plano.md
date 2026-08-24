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

   Nesta etapa, deve ser possível visualizar:

   - a reputação do template, obtida pela API da VendeAI; e
   - a reputação do número, obtida pela API da Meta.

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
- Permitir selecionar templates visualizando sua qualidade, obtida pela API da
  VendeAI.
- Permitir limitar o número de envios por número.

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
- Nesta entrega somente os passos de seleção e configuração serão trabalhados;
  a confirmação ficará bloqueada para uma etapa posterior.
- A origem poderá ser uma lista de números colada ou leads cadastrados.
- A lista colada aceitará quebras de linha, vírgulas e ponto e vírgula; aceitará
  celulares brasileiros de 11 dígitos com ou sem o prefixo `+55`, removerá
  duplicados e indicará entradas inválidas.
- A origem de leads cadastrados terá todos os filtros atuais do Lemit para
  Facta CLT, CLT Mercantil e CLT UY3. A seleção final será limitada a leads que
  possuem telefone.
- O protótipo não fará chamadas de API, prévia de quantidade nem persistência de
  dados; manterá apenas o estado enquanto o modal estiver aberto.

## Decisões — protótipo frontend da etapa 2

- O wizard liberará a configuração somente após uma seleção válida: a lista
  colada deverá ter ao menos um celular válido e nenhum inválido; bancos
  selecionados deverão ter ao menos um filtro próprio.
- O protótipo usará somente inboxes e templates simulados de WhatsApp oficial.
  Inboxes exibirão id, nome, número, nome verificado e qualidade do número.
- A qualidade do número seguirá os estados `GREEN`, `YELLOW`, `RED` e `NA` da
  Cloud API da Meta. A qualidade de template ficará identificada como simulada,
  pois a coleção da VendeAI não documenta esse campo na resposta.
- Cada parâmetro de template poderá receber valor fixo ou dado do lead. Para
  lista colada, somente telefone estará disponível; para leads cadastrados,
  também nome, CPF e data de nascimento.
- O passo incluirá produto obrigatório, campanha opcional, intervalo em
  segundos e início imediato ou agendado. Será possível selecionar múltiplos
  números remetentes e definir o limite de envios individualmente para cada
  número. A soma desses limites deverá ser suficiente para atender todos os
  destinatários conhecidos do disparo.
- Cada inbox representa um único número remetente e possui seu próprio conjunto
  de templates. Ao selecionar múltiplos números, somente templates disponíveis
  em todos os remetentes serão apresentados para a configuração do disparo.
  Inboxes sem templates deverão exibir um estado vazio orientando a revisão da
  seleção.
- Será possível selecionar múltiplos templates para alternância entre os
  destinatários. No protótipo, a rotação seguirá a ordem de seleção e cada
  template terá sua própria configuração de parâmetros.
- A proteção contra reenvio ao mesmo destinatário iniciará desativada e, quando
  ativada, exigirá um período positivo.
- Não haverá envio, agendamento, persistência, cadastro de leads na VendeAI ou
  consulta à Meta nesta etapa.
