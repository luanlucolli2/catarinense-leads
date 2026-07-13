# Regras do backend

Este diretório contém a API Laravel 12.

## Leitura

Priorize somente:

- `app/`
- `config/`
- `routes/`
- `database/migrations/`

Evite:

- `composer.lock`, salvo tarefa de dependência
- `storage/`
- `bootstrap/cache/`
- `vendor/`
- logs grandes
- arquivos gerados

## Arquitetura Laravel

- Seguir padrões Laravel 12.
- Controllers devem permanecer enxutos.
- Regras de integração externa devem ficar em Services.
- Processamento assíncrono deve ficar em Jobs.
- Validações de entrada devem usar FormRequest quando fizer sentido.
- Não alterar migrations antigas salvo pedido explícito.
- Para alteração de banco, criar nova migration.
- Não alterar nomes de filas sem autorização.
- Não alterar contratos de endpoints sem autorização.
- Não remover logs operacionais importantes sem autorização.
- Não aumentar consumo de CPU/memória sem necessidade.
- Considerar servidor limitado: 1 vCPU e 2 GB RAM.

## Validação

Prefira validações mínimas:

- `php -l caminho/do/arquivo.php`
- teste específico, se existir
- comando artisan específico, se necessário

Não rode suíte completa automaticamente.
Não rode comandos longos automaticamente.