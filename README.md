# Pré-requisitos

- Git instalado.
- Docker Desktop instalado e rodando com integração WSL2.

# Instalação do Projeto

1. Clone o repositório:
   ```bash
   git clone <url-do-repositorio>
   cd <nome-da-pasta-do-projeto>
   ```

# Configurar o Backend

1. Acesse a pasta do backend:
   ```bash
   cd backend
   ```
2. Copie o arquivo de ambiente:
   ```bash
   cp .env.example .env
   ```

# Construir e Iniciar os Contêineres

Use o Sail para criar e iniciar os contêineres:
```bash
sail up -d --build
```
- O `--build` é necessário apenas na primeira vez.
- Aguarde a conclusão; pode levar alguns minutos.

# Gerar a Chave da Aplicação

Com os contêineres ativos, gere a chave:
```bash
sail artisan key:generate
```

# Preparar o Banco de Dados

Execute migrations e seeders:
```bash
sail artisan migrate:fresh --seed
```

# Acessar a Aplicação

No navegador, acesse:
```
http://localhost:8080
```