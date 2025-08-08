# /backend/Dockerfile

# Usamos a imagem oficial do PHP 8.2 com FPM e Alpine (super leve) como base.
# Você pode trocar para a versão exata do seu PHP se necessário.
# A imagem do Sail é ótima para dev, mas para prod, queremos algo mais enxuto.
FROM php:8.2-fpm-alpine AS base

# Define o usuário 'sail' que o Laravel Sail usa por padrão.
# Manter a consistência de usuários/permissões é uma boa prática.
ARG UID=1000
ARG GID=1000

# Instala dependências do sistema e extensões do PHP necessárias para o Laravel.
# Isso inclui libs para imagem (gd), banco de dados (pdo_mysql), etc.
RUN apk add --no-cache \
      bash \
      curl \
      libzip-dev \
      libpng-dev \
      libxml2-dev \
      oniguruma-dev \
      icu-dev \
      && docker-php-ext-install \
      pdo_mysql \
      zip \
      gd \
      intl \
      bcmath \
      soap \
      && addgroup -g $GID -S sail \
      && adduser -u $UID -G sail -S -s /bin/sh sail

# Instala o Composer (gerenciador de pacotes do PHP)
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Mudar o dono dos arquivos para o usuário 'sail'
USER sail

# --- Estágio de Dependências ---
# Copiamos apenas os arquivos do composer e instalamos as dependências.
# Isso otimiza o cache do Docker. Se só mudarmos o código, esta camada não é reconstruída.
FROM base AS vendor

COPY --chown=sail:sail composer.json composer.lock ./

ARG CACHE_BUSTER=1
RUN --mount=type=cache,target=/root/.composer/cache composer install --no-interaction --no-dev --no-scripts --optimize-autoloader

# --- Estágio Final ---
# Começamos de novo da imagem base para manter a imagem final limpa.
FROM base AS release

# Copia as dependências já instaladas do estágio 'vendor'
COPY --from=vendor /var/www/html/vendor /var/www/html/vendor

# Copia o código da aplicação
COPY --chown=sail:sail . .

# Roda as otimizações do Laravel para produção.
# Isso cria arquivos de cache para rotas e configurações, tornando a app mais rápida.
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Expõe a porta 9000, padrão do PHP-FPM
EXPOSE 9000

# O comando para iniciar o servidor PHP-FPM
CMD ["php-fpm"]