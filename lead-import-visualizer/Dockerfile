# /lead-import-visualizer/Dockerfile

# --- ESTÁGIO 1: Build ---
# Usamos a imagem Node para "construir" nosso app React/Vite.
FROM node:18-alpine AS build

WORKDIR /app

# Copia os manifestos de dependência e instala (aproveitando o cache do Docker)
COPY package*.json ./
RUN npm install

# Copia todo o resto do código fonte
COPY . .

# Roda o comando que gera a build de produção na pasta /app/dist
RUN npm run build

# --- ESTÁGIO 2: Produção ---
# Agora, usamos uma imagem super leve do Nginx para servir os arquivos.
# O resultado final é um container minúsculo e rápido.
FROM nginx:1.25-alpine

# Copia os arquivos otimizados do estágio de build para a pasta do Nginx.
COPY --from=build /app/dist /usr/share/nginx/html

# Copia nosso arquivo de configuração do Nginx que criamos no Passo 1.
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Expõe a porta 80, onde o Nginx escuta por padrão.
EXPOSE 80