#!/bin/bash

# deploy.sh
# Script para deploy manual da aplicação Catarinense Leads em ambiente de staging.
#
# COMO USAR:
# 1. Coloque este arquivo na raiz do seu projeto no servidor.
# 2. Dê permissão de execução: chmod +x deploy.sh
# 3. Execute o script: ./deploy.sh

# --- Configuração ---
# Encerra o script imediatamente se qualquer comando falhar.
set -e

# O nome do seu arquivo docker-compose de staging.
COMPOSE_FILE="docker-compose.staging.yml"

# O nome do serviço do Laravel no arquivo compose.
LARAVEL_SERVICE="laravel"

# O nome do branch principal do seu repositório.
GIT_BRANCH="main"


# --- Início do Deploy ---
echo "🚀 Iniciando deploy para o ambiente de staging..."

# Passo 1: Entrar em modo de manutenção (Opcional, mas recomendado)
# Isso mostra uma página de "em manutenção" para os usuários enquanto o deploy ocorre.
# O script continuará mesmo se a aplicação já estiver em modo de manutenção.
echo ">>> 1/7: Colocando a aplicação em modo de manutenção..."
cd backend || exit
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan down || echo "Aplicação já estava em modo de manutenção ou não foi possível conectar. Continuando..."
cd ..

# Passo 2: Puxar o código mais recente do repositório
echo ">>> 2/7: Puxando atualizações do branch '$GIT_BRANCH'..."
git pull origin $GIT_BRANCH

# Passo 3: Reconstruir as imagens Docker
# Este passo é VITAL. Ele irá instalar novas dependências (composer e npm)
# e copiar o novo código para dentro das imagens, criando um artefato final.
echo ">>> 3/7: Reconstruindo as imagens Docker com o novo código..."
cd backend || exit
docker compose -f $COMPOSE_FILE build --no-cache

# Passo 4: Subir os novos contêineres
# O --force-recreate garante que os contêineres antigos sejam destruídos
# e novos sejam criados a partir das imagens que acabamos de construir.
echo ">>> 4/7: Recriando e subindo os contêineres..."
docker compose -f $COMPOSE_FILE up -d --force-recreate

# Passo 5: Executar as migrações do banco de dados
# Executa as migrações no novo container, garantindo que o DB esteja atualizado.
# O --force é necessário para rodar em modo não-interativo (produção).
echo ">>> 5/7: Rodando migrações do banco de dados..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan migrate --force

# Passo 6: Limpar e otimizar os caches do Laravel
# Fundamental para que o Laravel reconheça as novas configurações, rotas e views.
echo ">>> 6/7: Limpando caches e otimizando a aplicação..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan optimize

# Passo 7: Sair do modo de manutenção
echo ">>> 7/7: Tirando a aplicação do modo de manutenção..."
docker compose -f $COMPOSE_FILE exec $LARAVEL_SERVICE php artisan up

echo "✅ Deploy finalizado com sucesso!"