#!/bin/bash

# deploy.sh
# Script para deploy manual da aplicação Catarinense Leads em ambiente de staging.

# --- Configuração ---
set -e # Encerra o script imediatamente se qualquer comando falhar.

COMPOSE_FILE="docker-compose.staging.yml"
LARAVEL_SERVICE="laravel"
GIT_BRANCH="staging"
MYSQL_CONTAINER="leads-mysql"


# --- Início do Deploy ---
echo "🚀 Iniciando deploy para o ambiente de staging..."

# Passo 1: Puxar o código mais recente do repositório (executado da raiz)
echo ">>> 1/8: Puxando atualizações do branch '$GIT_BRANCH'..."
git pull origin $GIT_BRANCH

# Passo 2: Entrar no diretório do backend para os comandos Docker
# A partir daqui, todos os comandos são executados de dentro da pasta 'backend'.
cd backend || { echo "❌ Falha ao entrar no diretório 'backend'. Abortando."; exit 1; }

# Passo 3: Colocar a aplicação em modo de manutenção
echo ">>> 2/8: Colocando a aplicação em modo de manutenção..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan down || echo "Aplicação já estava em modo de manutenção ou não foi possível conectar. Continuando..."

# Passo 4: Reconstruir as imagens Docker
echo ">>> 3/8: Reconstruindo as imagens Docker com o novo código..."
# Exportamos uma variável com a data/hora atual para quebrar o cache do Docker
export CACHE_BUSTER=$(date +%s)
echo "Forçando reconstrução com CACHE_BUSTER=$CACHE_BUSTER"

docker compose -f $COMPOSE_FILE build --no-cache

# Passo 5: Recriando e subindo os contêineres
echo ">>> 4/8: Recriando e subindo os contêineres..."
docker compose -f $COMPOSE_FILE up -d --force-recreate

# Passo 6: Esperar o MySQL ficar saudável
echo ">>> 5/8: Aguardando o banco de dados ficar pronto..."
for i in {1..24}; do
    health_status=$(docker inspect --format='{{.State.Health.Status}}' $MYSQL_CONTAINER)
    if [ "$health_status" = "healthy" ]; then
        echo "✅ Banco de dados está pronto e saudável!"
        break
    fi
    echo "Aguardando o MySQL... (status: $health_status)"
    sleep 5
done

if [ "$(docker inspect --format='{{.State.Health.Status}}' $MYSQL_CONTAINER)" != "healthy" ]; then
    echo "❌ O banco de dados não ficou saudável a tempo. Abortando deploy."
    exit 1
fi

# Passo 7: Executar as migrações do banco de dados
echo ">>> 6/8: Rodando migrações do banco de dados..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan migrate --force

# Passo 8: Limpar e otimizar os caches do Laravel
echo ">>> 7/8: Limpando caches e otimizando a aplicação..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan optimize

# Passo 9: Sair do modo de manutenção
echo ">>> 8/8: Tirando a aplicação do modo de manutenção..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan up

echo "✅ Deploy finalizado com sucesso!"