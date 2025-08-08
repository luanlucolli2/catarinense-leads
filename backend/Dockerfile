# /backend/Dockerfile - Versão Final e Robusta

# Usamos a imagem oficial do Laravel Sail como base.
# Ela já inclui Apache, PHP, Composer e todas as extensões necessárias.
# Substitua '8.2' pela sua versão do PHP se for diferente.
FROM ghcr.io/laravel/sail:8.3

# Define o diretório de trabalho padrão.
WORKDIR /var/www/html

# Copia os manifestos de dependência primeiro para otimizar o cache do Docker.
COPY --chown=sail:sail composer.json composer.lock ./

# Instala apenas as dependências de produção, sem rodar scripts.
# Usamos o cache de pacotes do Composer para acelerar builds futuros.
RUN --mount=type=cache,target=/home/sail/.composer/cache composer install --no-interaction --no-dev --no-scripts --optimize-autoloader

# Copia o restante do código da sua aplicação.
COPY --chown=sail:sail . .

# !! PASSO DE ESTERILIZAÇÃO !!
# Removemos forçadamente quaisquer arquivos de cache que possam ter sido copiados.
RUN rm -f bootstrap/cache/*.php

# Agora, com a aplicação completa, rodamos as otimizações de produção.
RUN php artisan config:cache && php artisan route:cache

# O entrypoint da imagem do Sail já cuida de iniciar o Apache e o PHP-FPM.
# Não precisamos de EXPOSE ou CMD.