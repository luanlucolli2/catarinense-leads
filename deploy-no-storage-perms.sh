#!/bin/bash
# deploy-no-storage-perms.sh – STAGING (PROD READY) - SUPERVISOR EDITION

set -Eeuo pipefail

# --- Config ---
COMPOSE_FILE="backend/docker-compose.staging.yml"
ENV_FILE="backend/.env.staging"
IMAGE_PRUNE_UNTIL="${IMAGE_PRUNE_UNTIL:-168h}"          # imagens não usadas há 7 dias
BUILDER_PRUNE_UNTIL="${BUILDER_PRUNE_UNTIL:-72h}"       # cache de build antigo (3 dias)
BUILDER_CACHE_KEEP="${BUILDER_CACHE_KEEP:-2GB}"         # mantém até 2GB de cache de build

# Nomes dos serviços/containers
LARAVEL_SERVICE="laravel"
WORKER_SERVICE="leads-workers"
MYSQL_CONTAINER="leads-mysql"
REDIS_CONTAINER="leads-redis"
GIT_BRANCH="staging"

compose() {
  docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

echo "🚀 Iniciando deploy OTIMIZADO (SUPERVISOR) para STAGING..."

# 0) git pull
echo ">>> 1/10: git pull origin ${GIT_BRANCH}"
git reset --hard
git pull origin "${GIT_BRANCH}"

# 1) Build (usa cache inteligente + atualiza base)
echo ">>> 2/10: Build das imagens (cache inteligente)..."
compose build --pull

# 2) Subir/atualizar containers sem derrubar tudo
echo ">>> 3/10: Subindo/atualizando base (mysql/redis)..."
compose up -d --remove-orphans mysql redis

echo ">>> 3.1/10: Recriando serviços da aplicação (evita versão antiga)..."
compose up -d --remove-orphans --force-recreate laravel leads-workers frontend

sleep 3 # Pequena pausa para garantir que os containers estejam estáveis antes de rodar comandos dentro deles

# 3) Migrations
echo ">>> 4/10: Rodando migrações..."
compose exec -T "${LARAVEL_SERVICE}" php artisan migrate --force

# 4) Otimização Laravel
echo ">>> 5/10: Otimizando caches..."
compose exec -T "${LARAVEL_SERVICE}" php artisan optimize:clear
compose exec -T "${LARAVEL_SERVICE}" php artisan config:cache
compose exec -T "${LARAVEL_SERVICE}" php artisan route:clear
compose exec -T "${LARAVEL_SERVICE}" php artisan view:cache

# Reinicia sinalizadores de fila (embora o container tenha sido recriado, boa prática)
compose exec -T "${LARAVEL_SERVICE}" php artisan queue:restart

# Religa automaticamente jobs ativos que ficaram órfãos durante o recreate
echo ">>> 5.1/10: Religando jobs ativos..."
compose exec -T "${LARAVEL_SERVICE}" php artisan consult-jobs:resume-active-after-deploy

# 5) Limpeza inteligente (economiza espaço sem destruir todo cache)
echo ">>> 6/10: Limpando imagens/cache antigos..."
docker image prune -f --filter "until=${IMAGE_PRUNE_UNTIL}" || true
docker builder prune -f --filter "until=${BUILDER_PRUNE_UNTIL}" --keep-storage "${BUILDER_CACHE_KEEP}" || \
docker builder prune -f --filter "until=${BUILDER_PRUNE_UNTIL}" || true

echo "✅ Deploy finalizado com Sucesso!"
