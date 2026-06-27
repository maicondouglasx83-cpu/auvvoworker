FROM node:20-alpine

WORKDIR /app

# Dependências de produção apenas
COPY package*.json ./
RUN npm ci --omit=dev

# Código fonte
COPY src/ ./src/

# Healthcheck: verifica se o heartbeat foi atualizado recentemente
# (o worker grava /app/storage/worker_heartbeat.txt a cada tick)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD node -e " \
    const fs=require('fs'); \
    const p='/app/storage/worker_heartbeat.txt'; \
    if(!fs.existsSync(p)) process.exit(1); \
    const age=(Date.now()/1000)-parseInt(fs.readFileSync(p,'utf8')||'0'); \
    process.exit(age>180?1:0)" || exit 1

# Cria diretório de storage para o heartbeat
RUN mkdir -p /app/storage

CMD ["node", "src/index.js"]
