# Regras do repositório

Este repositório possui dois projetos principais:

- `backend/`: API Laravel 12
- `frontend/`: frontend React/Vite/TypeScript

## Objetivo

Economizar contexto, leitura de arquivos, comandos e output.

Trabalhe sempre com alteração mínima, focada e segura.

## Escopo

- Não altere backend e frontend na mesma tarefa, salvo pedido explícito.
- Não faça varredura ampla no repositório sem necessidade.
- Não modifique arquivos fora do escopo da tarefa.
- Não faça refatoração ampla salvo pedido explícito.
- Não altere contrato de API sem autorização explícita.
- Não adicione dependências sem autorização explícita.
- Não rode comandos demorados, suíte completa de testes ou build de produção sem autorização explícita.
- Não sugira melhorias fora do escopo solicitado.
- Não explique conceitos básicos se eu não pedir.

## Arquivos a evitar

Evite ler ou modificar:

- lockfiles, salvo quando a tarefa envolver dependências
- builds gerados
- logs grandes
- `vendor/`
- `node_modules/`
- `storage/`
- `bootstrap/cache/`
- `dist-codex/`
- assets compilados

Se precisar acessar algum desses arquivos, explique o motivo em uma linha antes.

## Comportamento

- Leia somente arquivos diretamente relacionados ao problema.
- Use buscas focadas em vez de explorar o projeto inteiro.
- Não narrar cada passo.
- Só pedir confirmação quando precisar sair do escopo, alterar contrato, adicionar dependência ou rodar comando pesado.

## Verificação antes de responder

- Dúvidas sobre comportamento, arquitetura, contratos, fluxos, campos, validações,
  erros ou implementação devem ser verificadas no código antes de serem respondidas.
- Não responda com base apenas em suposições, padrões comuns ou memória do contexto.
- Localize primeiro os símbolos, arquivos e testes diretamente relacionados à dúvida.
- Quando o código não permitir uma conclusão segura, informe explicitamente o que foi
  verificado, o que permanece incerto e quais informações estão faltando.

## Resposta final

Após concluir uma alteração, informe somente:

- resumo do que foi feito;
- arquivos alterados;
- validações ou testes executados;
- riscos, limitações ou pendências.

Para dúvidas, diagnósticos ou explicações sem alteração de arquivos, responda
diretamente com contexto suficiente, evidências verificadas e comandos relevantes
quando ajudarem a esclarecer a resposta.

Não mostre diff nem repita código completo, salvo solicitação explícita.
