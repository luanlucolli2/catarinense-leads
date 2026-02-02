#!/bin/bash
# deploy.sh – STAGING (PROD READY) - SUPERVISOR EDITION

set -Eeuo pipefail

# --- Config ---
COMPOSE_FILE="backend/docker-compose.staging.yml"

# Nomes dos serviços/containers
LARAVEL_SERVICE="laravel"
WORKER_SERVICE="leads-workers"
MYSQL_CONTAINER="leads-mysql"
REDIS_CONTAINER="leads-redis"
GIT_BRANCH="staging"

echo "🚀 Iniciando deploy OTIMIZADO (SUPERVISOR) para STAGING..."

# 0) git pull
echo ">>> 1/11: git pull origin ${GIT_BRANCH}"
git reset --hard
git pull origin "${GIT_BRANCH}"

# 1) Build (Recria as imagens)
echo ">>> 2/11: Build das imagens (--no-cache)..."
docker compose -f "${COMPOSE_FILE}" build --no-cache

# 2) Manutenção
if [ "$(docker ps -q -f name=leads-backend)" ]; then
    echo ">>> 3/11: Habilitando manutenção..."
    docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan down || true
fi

# 3) Limpeza de Containers Antigos (Importante na migração)
echo ">>> 4/11: Removendo containers órfãos (antigas filas separadas)..."
docker compose -f "${COMPOSE_FILE}" down --remove-orphans

# 4) Subir Containers
echo ">>> 5/11: Subindo novos containers..."
docker compose -f "${COMPOSE_FILE}" up -d --force-recreate

# 5) Aguarda MySQL
echo ">>> 6/11: Aguardando MySQL saudável..."
for i in {1..24}; do
  status="$(docker inspect --format='{{.State.Health.Status}}' "${MYSQL_CONTAINER}" 2>/dev/null || echo "unknown")"
  [ "${status}" = "healthy" ] && echo "✅ MySQL OK!" && break
  echo "⏳ MySQL... (status: ${status})"; sleep 5
done

# 6) Aguarda Redis
echo ">>> 7/11: Aguardando Redis saudável..."
for i in {1..24}; do
  status="$(docker inspect --format='{{.State.Health.Status}}' "${REDIS_CONTAINER}" 2>/dev/null || echo "unknown")"
  [ "${status}" = "healthy" ] && echo "✅ Redis OK!" && break
  echo "⏳ Redis... (status: ${status})"; sleep 5
done

# 7) Migrations
echo ">>> 8/11: Rodando migrações..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan migrate --force

# 8) Permissões de Pasta (Storage)
echo ">>> 9/11: Ajustando permissões do storage..."
# Aplica tanto no container web quanto no worker (compartilham volume)
docker compose -f "${COMPOSE_FILE}" exec -T --user root "${LARAVEL_SERVICE}" chown -R www-data:www-data /var/www/html/storage
docker compose -f "${COMPOSE_FILE}" exec -T --user root "${LARAVEL_SERVICE}" chmod -R 775 /var/www/html/storage

# 9) Otimização Laravel
echo ">>> 10/11: Otimizando caches..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan optimize:clear
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan config:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan route:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan view:cache

# Reinicia sinalizadores de fila (embora o container tenha sido recriado, boa prática)
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan queue:restart

# 10) Volta da manutenção
echo ">>> 11/11: Desabilitando manutenção..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan up

# 11) Limpeza Pesada
echo ">>> 12/12: Limpando imagens..."
docker image prune -f 
docker builder prune -f

echo "✅ Deploy finalizado com Sucesso!"