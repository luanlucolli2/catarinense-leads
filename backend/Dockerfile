FROM php:8.4-fpm

# Argumentos
ARG user=www-data
ARG uid=1000

# Instalar dependências do sistema e SUPERVISOR
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
    supervisor \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Instalar Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependências (Cache Layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Código da Aplicação
COPY . .

# Copiar configuração do Supervisor de PROD
COPY docker/supervisord.prod.conf /etc/supervisor/conf.d/supervisord.conf

# Permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Dump Autoload Final
RUN composer dump-autoload --optimize --no-dev

EXPOSE 9000

# O CMD padrão continua sendo php-fpm para o container 'laravel',
# mas o container 'leads-workers' irá sobrescrever isso no docker-compose.
CMD ["php-fpm"]