#!/bin/bash
# deploy.sh – STAGING (PROD READY)

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

echo "🚀 Iniciando deploy OTIMIZADO para STAGING..."

# 0) git pull (Atualiza o código fonte no HOST para o Docker poder copiar)
echo ">>> 1/10: git pull origin ${GIT_BRANCH}"
git reset --hard
git pull origin "${GIT_BRANCH}"

# 1) Build da imagem do Backend (Aqui acontece o composer install)
echo ">>> 2/10: Build das imagens (--no-cache)..."
# Usamos no-cache para garantir que o COPY . . pegue o código novo
docker compose -f "${COMPOSE_FILE}" build --no-cache

# 2) Colocar Laravel em modo de manutenção (breve)
# Só tentamos se o container já estiver rodando
if [ "$(docker ps -q -f name=leads-backend)" ]; then
    echo ">>> 3/10: Habilitando manutenção..."
    docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan down || true
fi

# 3) Sobe stack (Recria containers com a imagem nova contendo o código novo)
echo ">>> 4/10: Subindo containers (--force-recreate)..."
docker compose -f "${COMPOSE_FILE}" up -d --force-recreate --remove-orphans

# 4) Aguarda MySQL
echo ">>> 5/10: Aguardando MySQL saudável..."
for i in {1..24}; do
  status="$(docker inspect --format='{{.State.Health.Status}}' "${MYSQL_CONTAINER}" 2>/dev/null || echo "unknown")"
  [ "${status}" = "healthy" ] && echo "✅ MySQL OK!" && break
  echo "⏳ MySQL... (status: ${status})"; sleep 5
done

# 5) Aguarda REDIS
echo ">>> 6/10: Aguardando Redis saudável..."
for i in {1..24}; do
  status="$(docker inspect --format='{{.State.Health.Status}}' "${REDIS_CONTAINER}" 2>/dev/null || echo "unknown")"
  [ "${status}" = "healthy" ] && echo "✅ Redis OK!" && break
  echo "⏳ Redis... (status: ${status})"; sleep 5
done

# 6) Rodar migrações (Banco de dados)
echo ">>> 7/10: Rodando migrações..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan migrate --force

# 7) Garantir permissões na pasta de Storage (Volume persistente)
echo ">>> 8/10: Ajustando permissões do storage..."
docker compose -f "${COMPOSE_FILE}" exec -T --user root "${LARAVEL_SERVICE}" chown -R www-data:www-data /var/www/html/storage
docker compose -f "${COMPOSE_FILE}" exec -T --user root "${LARAVEL_SERVICE}" chmod -R 775 /var/www/html/storage

# 8) Otimizando Laravel (Agora rodando DENTRO do container novo)
echo ">>> 9/10: Otimizando caches (config/route/view)..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan optimize:clear
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan config:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan route:cache
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan view:cache
# Restart nas filas para garantir que peguem caches novos (embora o recreate já ajude)
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan queue:restart

# 9) Tira manutenção
echo ">>> 10/10: Desabilitando manutenção..."
docker compose -f "${COMPOSE_FILE}" exec -T "${LARAVEL_SERVICE}" php artisan up

# 10) Limpeza
echo ">>> Limpando imagens antigas..."
docker image prune -f

echo "✅ Deploy finalizado!"