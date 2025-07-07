# Em lead-import-visualizer/Dockerfile

# 1. Usar a imagem oficial do Node.js como base
FROM node:18-alpine

# 2. Definir o diretório de trabalho dentro do contêiner
WORKDIR /app

# 3. Copiar os arquivos de manifesto de dependências
COPY package*.json ./

# 4. Instalar as dependências do projeto
RUN npm install

# 5. Copiar o restante do código do projeto para dentro do contêiner
COPY . .

# 6. Expor a porta que o Vite usa
EXPOSE 8080

# 7. O comando padrão para iniciar o servidor
# Em lead-import-visualizer/Dockerfile
CMD ["npm", "run", "dev", "--", "--host"]