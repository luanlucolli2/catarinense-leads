FROM php:8.4-fpm

# Argumentos para criar usuário de sistema igual ao host
ARG user=www-data
ARG uid=1000

# Instalar dependências do sistema
# ADICIONADO: libicu-dev (necessário para a extensão intl)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    default-mysql-client \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP necessárias
# Agora o 'intl' vai funcionar porque instalamos o libicu-dev acima
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Instalar extensão Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Obter o Composer mais recente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir diretório de trabalho
WORKDIR /var/www/html

# Copiar apenas arquivos de dependência primeiro
COPY composer.json composer.lock ./

# Instalar dependências (modo prod)
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copiar o restante do código da aplicação
COPY . .

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Rodar comandos de build do composer finais
RUN composer dump-autoload --optimize --no-dev

# Expor porta do FPM
EXPOSE 9000

CMD ["php-fpm"]