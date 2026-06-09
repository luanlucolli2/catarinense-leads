#!/bin/bash
# deploy-no-storage-perms.sh – STAGING (PROD READY) - SUPERVISOR EDITION

set -Eeuo pipefail

# --- Config ---
COMPOSE_FILE="backend/docker-compose.staging.yml"
IMAGE_PRUNE_UNTIL="${IMAGE_PRUNE_UNTIL:-168h}"          # imagens não usadas há 7 dias
BUILDER_PRUNE_UNTIL="${BUILDER_PRUNE_UNTIL:-72h}"       # cache de build antigo (3 dias)
BUILDER_CACHE_KEEP="${BUILDER_CACHE_KEEP:-2GB}"         # mantém até 2GB de cache de build

# Nomes dos serviços/containers
LARAVEL_SERVICE="laravel"
WORKER_SERVICE="leads-workers"
MYSQL_CONTAINER="leads-mysql"
REDIS_CONTAINER="leads-redis"
GIT_BRANCH="staging"

echo "🚀 Iniciando deploy OTIMIZADO (SUPERVISOR) para STAGING..."

# 0) git pull
echo ">>> 1/10: git pull origin ${GIT_BRANCH}"
git reset --hard
git pull origin "${GIT_BRANCH}"

# 1) Build (usa cache inteligente + atualiza base)
echo ">>> 2/10: Build das imagens (cache inteligente)..."
docker compose -f "${COMPOSE_FILE}" build --pull

# 2) Subir/atualizar containers sem derrubar tudo
echo ">>> 3/10: Subindo/atualizando base (mysql/redis)..."
docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans mysql redis

echo ">>> 3.1/10: Recriando serviços da aplicação (evita versão antiga)..."
docker compose -f "${COMPOSE_FILE}" up -d --remove-orphans --force-recreate laravel leads-workers frontend

sleep 3 # Pequena pausa para garantir que os containers estejam estáveis antes de rodar comandos dentro deles

# 3) Migrations
echo ">>> 4/10: Rodando migrações..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan migrate --force

# 4) Otimização Laravel
echo ">>> 5/10: Otimizando caches..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan optimize:clear
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan config:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan route:clear
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan view:cache

# Reinicia sinalizadores de fila (embora o container tenha sido recriado, boa prática)
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan queue:restart

# 5) Limpeza inteligente (economiza espaço sem destruir todo cache)
echo ">>> 6/10: Limpando imagens/cache antigos..."
docker image prune -f --filter "until=${IMAGE_PRUNE_UNTIL}" || true
docker builder prune -f --filter "until=${BUILDER_PRUNE_UNTIL}" --keep-storage "${BUILDER_CACHE_KEEP}" || \
docker builder prune -f --filter "until=${BUILDER_PRUNE_UNTIL}" || true

echo "✅ Deploy finalizado com Sucesso!"
