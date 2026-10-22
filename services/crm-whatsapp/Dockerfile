FROM node:20-bookworm-slim

ENV PUPPETEER_SKIP_DOWNLOAD=false \
    PUPPETEER_CACHE_DIR=/home/node/.cache/puppeteer

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    chromium \
    dumb-init \
    fonts-liberation \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnss3 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund
COPY src ./src
RUN chown -R node:node /app
RUN mkdir -p /tmp/whatsapp-remote-auth
EXPOSE 3100
ENTRYPOINT ["dumb-init", "--"]
CMD ["npm", "start"]
