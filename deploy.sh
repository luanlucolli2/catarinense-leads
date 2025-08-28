#!/bin/bash

# deploy.sh
# Deploy manual da aplicação Catarinense Leads em STAGING, com limpeza segura de artefatos Docker.

set -Eeuo pipefail

# --- Config ---
COMPOSE_FILE="docker-compose.staging.yml"
LARAVEL_SERVICE="laravel"
MYSQL_CONTAINER="leads-mysql"
GIT_BRANCH="staging"

# Limpezas "seguras" (não tocam volumes). Ajuste se quiser mais agressivo.
BUILDER_PRUNE_UNTIL="${BUILDER_PRUNE_UNTIL:-24h}"    # build cache mais antigo que isso
CONTAINER_PRUNE_UNTIL="${CONTAINER_PRUNE_UNTIL:-24h}"# containers parados há > isso
# Para também limpar *imagens não utilizadas* (além de dangling), exporte DOCKER_PRUNE_UNUSED=1
DOCKER_PRUNE_UNUSED="${DOCKER_PRUNE_UNUSED:-0}"

echo "🚀 Iniciando deploy para STAGING..."

# 0) Uso de disco (antes)
echo ">>> 0/10: Estado de disco (ANTES)..."
docker system df -v || true

# 1) Atualiza código (raiz do repo)
echo ">>> 1/10: git pull origin ${GIT_BRANCH}"
git pull origin "${GIT_BRANCH}"

# 2) Entra no backend
cd backend || { echo "❌ Falha ao entrar em 'backend'."; exit 1; }

# 3) Modo manutenção (sem TTY para script)
echo ">>> 2/10: Habilitando manutenção..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan down || \
  echo "ℹ️  Já em manutenção ou container indisponível — seguindo."

# 4) Rebuild das imagens (sem cache, como você já faz)
echo ">>> 3/10: Build das imagens (forçando cache-buster + --no-cache)..."
export CACHE_BUSTER="$(date +%s)"
echo "CACHE_BUSTER=${CACHE_BUSTER}"
docker compose -f "${COMPOSE_FILE}" build --no-cache

# 5) Sobe/recria containers
echo ">>> 4/10: Subindo containers (recreate + remove orphans)..."
docker compose -f "${COMPOSE_FILE}" up -d --force-recreate --remove-orphans

# 6) Aguarda MySQL saudável
echo ">>> 5/10: Aguardando MySQL saudável..."
for i in {1..24}; do
  health_status="$(docker inspect --format='{{.State.Health.Status}}' "${MYSQL_CONTAINER}" 2>/dev/null || echo "unknown")"
  if [ "${health_status}" = "healthy" ]; then
    echo "✅ MySQL OK!"
    break
  fi
  echo "⏳ Aguardando MySQL... (status: ${health_status})"
  sleep 5
done

if [ "$(docker inspect --format='{{.State.Health.Status}}' "${MYSQL_CONTAINER}" 2>/dev/null || echo "unknown")" != "healthy" ]; then
  echo "❌ MySQL não ficou saudável a tempo. Abortando."
  exit 1
fi

# 7) Migrações
echo ">>> 6/10: Rodando migrações..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan migrate --force

# 8) Caches do Laravel
echo ">>> 7/10: Otimizando Laravel (config/route/event cache)..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan config:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan route:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan event:cache

# 9) Tira manutenção
echo ">>> 8/10: Desabilitando manutenção..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan up

# 10) Limpeza segura Docker (não mexe em volumes)
echo ">>> 9/10: Limpando artefatos Docker (SEGURO)..."

# 10.1 imagens dangling (camadas órfãs, seguras de remover)
echo " - docker image prune (dangling only)..."
docker image prune -f || true

# 10.2 build cache antigo (BuildKit)
echo " - docker builder prune (até ${BUILDER_PRUNE_UNTIL})..."
docker builder prune -af --filter "until=${BUILDER_PRUNE_UNTIL}" || true

# 10.3 containers parados antigos
echo " - docker container prune (parados há > ${CONTAINER_PRUNE_UNTIL})..."
docker container prune -f --filter "until=${CONTAINER_PRUNE_UNTIL}" || true

# 10.4 redes não usadas (seguro; não remove redes em uso)
echo " - docker network prune..."
docker network prune -f || true

# 10.5 (opcional) imagens não utilizadas (NÃO remove imagens em uso por containers)
if [ "${DOCKER_PRUNE_UNUSED}" = "1" ]; then
  echo " - docker image prune (unused; conservador) ..."
  # sem filtro de 'until' porque alguns Docker não suportam; ainda assim NÃO remove imagens em uso
  docker image prune -f || true
fi

# 11) Estado de disco (depois)
echo ">>> 10/10: Estado de disco (DEPOIS)..."
docker system df -v || true

echo "✅ Deploy finalizado!"
