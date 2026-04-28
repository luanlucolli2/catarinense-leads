# Regras do repositório

Este repositório possui dois projetos principais:

- `backend/`: API Laravel 12
- `lead-import-visualizer/`: frontend React/Vite/TypeScript

## Objetivo principal

Economizar contexto, leitura de arquivos, comandos e output.

Trabalhe sempre com alteração mínima, focada e segura.

## Regras gerais

- Não altere backend e frontend na mesma tarefa, salvo se eu pedir explicitamente.
- Não faça varredura ampla no repositório sem necessidade.
- Não leia arquivos grandes sem necessidade.
- Não leia builds, logs, lockfiles, assets compilados ou arquivos gerados salvo se forem essenciais.
- Não faça refatoração ampla salvo se eu pedir explicitamente.
- Não altere contrato de API sem autorização explícita.
- Não adicione dependências sem autorização explícita.
- Não rode comandos demorados sem autorização explícita.
- Não rode suíte completa de testes sem autorização explícita.
- Não execute build de produção sem autorização explícita.
- Não modifique arquivos fora do escopo da tarefa.
- Não sugira melhorias fora do escopo solicitado.
- Não explique conceitos básicos se eu não pedir.
- Não gere documentação longa após alterar código.
- Não mostre diff completo salvo se eu pedir.

## Estratégia de leitura

Antes de editar, leia somente os arquivos diretamente relacionados ao problema.

Se a tarefa mencionar backend, priorize:

- `backend/app/`
- `backend/config/`
- `backend/routes/`
- `backend/database/migrations/`

Se a tarefa mencionar frontend, priorize:

- `lead-import-visualizer/src/`
- `lead-import-visualizer/package.json`
- `lead-import-visualizer/vite.config.ts`
- `lead-import-visualizer/tailwind.config.ts`

Não abrir backend e frontend juntos, salvo quando a tarefa envolver integração de API, contrato de request/response ou ajuste fullstack explicitamente solicitado.

## Arquivos e pastas que devem ser evitados

Evite ler ou modificar:

- `backend/logsclt2602.txt`
- `backend/composer.lock`
- `lead-import-visualizer/package-lock.json`
- `lead-import-visualizer/dist-codex/`
- `lead-import-visualizer/dist-codex/assets/index-Cs_Lmez4.js`
- `lead-import-visualizer/dist-codex/assets/index-CzdhLvgk.css`
- `backend/public/vendor/`
- `backend/storage/`
- `backend/bootstrap/cache/`
- `backend/vendor/`
- `backend/node_modules/`
- `lead-import-visualizer/node_modules/`
- qualquer arquivo de log grande
- qualquer build gerado
- qualquer lockfile, salvo quando a tarefa envolver dependências

Se precisar ler algum desses arquivos, explique em uma linha o motivo antes.

## Regras para backend Laravel

- Seguir padrões Laravel 12.
- Controllers devem permanecer enxutos.
- Regras de integração externa devem ficar em Services.
- Processamento assíncrono deve ficar em Jobs.
- Validações de entrada devem usar FormRequest quando fizer sentido.
- Não alterar migrations antigas salvo se eu pedir explicitamente.
- Para alteração de banco, criar nova migration.
- Não alterar nomes de filas sem autorização.
- Não alterar contratos de endpoints sem autorização.
- Não remover logs operacionais importantes sem autorização.
- Não aumentar consumo de CPU/memória sem necessidade.
- Considerar que o servidor é limitado: 1 vCPU e 2 GB RAM.

## Regras para frontend React/Vite/TypeScript

- Seguir a estrutura atual do projeto.
- Não adicionar biblioteca nova sem autorização.
- Não alterar componentes compartilhados sem necessidade.
- Não mexer em `components/ui/` salvo se o problema estiver ali.
- Não mexer em telas não relacionadas.
- Não rodar build completo salvo se eu pedir.
- Preferir alterações locais e pequenas.
- Manter tipagem TypeScript consistente.

## Validação

Quando alterar backend, prefira validações mínimas, por exemplo:

- `php -l caminho/do/arquivo.php`
- teste específico, se existir
- comando artisan específico, se necessário

Quando alterar frontend, prefira validações mínimas, por exemplo:

- `npm run lint` somente se necessário
- `npm run build` somente com autorização
- checagem TypeScript somente se for rápida e relevante

Não rode comandos longos automaticamente.

## Comportamento durante a tarefa

- Não narrar cada passo.
- Não escrever explicações longas.
- Não listar alternativas se a solução já estiver clara.
- Não fazer perguntas se o escopo estiver suficiente.
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