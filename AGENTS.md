# Regras do repositório

Este repositório possui dois projetos principais:

- `backend/`: API Laravel 12
- `lead-import-visualizer/`: frontend React/Vite/TypeScript

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

## Resposta final

Após concluir, responda somente neste formato:

Arquivos:
- `caminho/do/arquivo`

Validação:
- `comando mínimo ou "não executado"`

Observações:
- `máximo 1 linha, somente se houver risco ou pendência`

Não explique a implementação.
Não mostre diff.
Não repita código.
Não sugira próximos passos.
Não ultrapasse 6 linhas na resposta final.