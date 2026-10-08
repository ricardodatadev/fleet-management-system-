# syntax=docker/dockerfile:1
# Contexto: services/node-realtime
FROM node:24.21.0-alpine AS deps
WORKDIR /srv
COPY package.json package-lock.json ./
RUN npm ci

FROM deps AS build
COPY tsconfig.json ./
COPY src ./src
RUN npm run build

FROM build AS test
COPY tests ./tests
CMD ["npm", "test"]

FROM node:24.21.0-alpine AS prod
ENV NODE_ENV=production
WORKDIR /srv
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY --from=build /srv/dist ./dist
USER node
EXPOSE 3000
CMD ["node", "dist/server.js"]
