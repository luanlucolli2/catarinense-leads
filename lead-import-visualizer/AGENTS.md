# Regras do frontend

Este diretório contém o frontend React/Vite/TypeScript.

## Leitura

Priorize somente:

- `src/`
- `package.json`
- `vite.config.ts`
- `tailwind.config.ts`

Evite:

- `package-lock.json`, salvo tarefa de dependência
- `node_modules/`
- `dist-codex/`
- assets compilados
- builds gerados

## Arquitetura frontend

- Seguir a estrutura atual do projeto.
- Não adicionar biblioteca nova sem autorização.
- Não alterar componentes compartilhados sem necessidade.
- Não mexer em `components/ui/` salvo se o problema estiver ali.
- Não mexer em telas não relacionadas.
- Preferir alterações locais e pequenas.
- Manter tipagem TypeScript consistente.

## Validação

Prefira validações mínimas:

- `npm run lint` somente se necessário
- checagem TypeScript somente se for rápida e relevante
- `npm run build` somente com autorização

Não rode build completo automaticamente.