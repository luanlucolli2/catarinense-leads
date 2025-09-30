#!/bin/bash
# deploy.sh – STAGING

set -Eeuo pipefail

# --- Config ---
COMPOSE_FILE="docker-compose.staging.yml"
LARAVEL_SERVICE="laravel"
MYSQL_CONTAINER="leads-mysql"
REDIS_CONTAINER="leads-redis"
GIT_BRANCH="staging"

# Limpezas "seguras"
BUILDER_PRUNE_UNTIL="${BUILDER_PRUNE_UNTIL:-24h}"
CONTAINER_PRUNE_UNTIL="${CONTAINER_PRUNE_UNTIL:-24h}"
DOCKER_PRUNE_UNUSED="${DOCKER_PRUNE_UNUSED:-0}"

# Workers (para restart em bloco)
WORKERS=("queue" "queue-clt" "queue-fgts" "queue-reports")

echo "🚀 Iniciando deploy para STAGING..."

# 0) Disco (antes)
echo ">>> 0/12: Estado de disco (ANTES)..."
docker system df -v || true

# 1) git pull
echo ">>> 1/12: git pull origin ${GIT_BRANCH}"
git pull origin "${GIT_BRANCH}"

# 2) entra no backend
cd backend || { echo "❌ Falha ao entrar em 'backend'."; exit 1; }

# 3) manutenção
echo ">>> 2/12: Habilitando manutenção..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan down || echo "ℹ️  Já em manutenção."

# 4) build (no-cache)
echo ">>> 3/12: Build das imagens (--no-cache)..."
export CACHE_BUSTER="$(date +%s)"
echo "CACHE_BUSTER=${CACHE_BUSTER}"
docker compose -f "${COMPOSE_FILE}" build --no-cache

# 5) LIMPAR CACHES *ANTES* de subir workers (evita 'cache'->database)
echo ">>> 4/12: Limpando caches do Laravel (optimize:clear)..."
docker compose -f "${COMPOSE_FILE}" run --rm "${LARAVEL_SERVICE}" php artisan optimize:clear || true

# 6) sobe stack
echo ">>> 5/12: Subindo containers (--force-recreate --remove-orphans)..."
docker compose -f "${COMPOSE_FILE}" up -d --force-recreate --remove-orphans

# 7) aguarda MySQL saudável
echo ">>> 6/12: Aguardando MySQL saudável..."
for i in {1..24}; do
  status="$(docker inspect --format='{{.State.Health.Status}}' "${MYSQL_CONTAINER}" 2>/dev/null || echo "unknown")"
  [ "${status}" = "healthy" ] && echo "✅ MySQL OK!" && break
  echo "⏳ MySQL... (status: ${status})"; sleep 5
done
if [ "$(docker inspect --format='{{.State.Health.Status}}' "${MYSQL_CONTAINER}" 2>/dev/null || echo "unknown")" != "healthy" ]; then
  echo "❌ MySQL não ficou saudável a tempo."; exit 1
fi

# 8) aguarda REDIS saudável
echo ">>> 7/12: Aguardando Redis saudável..."
for i in {1..24}; do
  status="$(docker inspect --format='{{.State.Health.Status}}' "${REDIS_CONTAINER}" 2>/dev/null || echo "unknown")"
  [ "${status}" = "healthy" ] && echo "✅ Redis OK!" && break
  echo "⏳ Redis... (status: ${status})"; sleep 5
done
if [ "$(docker inspect --format='{{.State.Health.Status}}' "${REDIS_CONTAINER}" 2>/dev/null || echo "unknown")" != "healthy" ]; then
  echo "❌ Redis não ficou saudável a tempo."; exit 1
fi

# 9) migrações
echo ">>> 8/12: Rodando migrações..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan migrate --force

# 10) recompila caches de config/route/event (agora com .env atual e Redis)
echo ">>> 9/12: Otimizando Laravel (config/route/event cache)..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan config:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan route:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan event:cache

# 11) reinicia os workers p/ pegarem o config novo
echo ">>> 10/12: Reiniciando workers..."
for SVC in "${WORKERS[@]}"; do
  docker compose -f "${COMPOSE_FILE}" restart "$SVC" || true
done
# redundância amigável ao Laravel
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan queue:restart || true

# 12) tira manutenção
echo ">>> 11/12: Desabilitando manutenção..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan up

# 13) limpeza segura Docker
echo ">>> 12/12: Limpando artefatos Docker (SEGURO)..."
echo " - docker image prune (dangling only)..."; docker image prune -f || true
echo " - docker builder prune (até ${BUILDER_PRUNE_UNTIL})..."; docker builder prune -af --filter "until=${BUILDER_PRUNE_UNTIL}" || true
echo " - docker container prune (parados há > ${CONTAINER_PRUNE_UNTIL})..."; docker container prune -f --filter "until=${CONTAINER_PRUNE_UNTIL}" || true
echo " - docker network prune..."; docker network prune -f || true
if [ "${DOCKER_PRUNE_UNUSED}" = "1" ]; then
  echo " - docker image prune (unused; conservador) ..."; docker image prune -f || true
fi

echo ">>> Estado de disco (DEPOIS)..."
docker system df -v || true

echo "✅ Deploy finalizado!"
