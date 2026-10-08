# syntax=docker/dockerfile:1
# Contexto: raiz do repo. Stage 1 compila a SPA (frontend/); stage 2 serve o dist/ no nginx.
FROM node:24.21.0-alpine AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM nginx:1.30.5-alpine AS web

RUN rm /etc/nginx/conf.d/default.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/swagger.location.conf /etc/nginx/swagger.location.conf
COPY docker/nginx/40-swagger.sh /docker-entrypoint.d/40-swagger.sh
RUN mkdir -p /etc/nginx/enabled
COPY --from=frontend-build /app/dist /usr/share/nginx/html
EXPOSE 80
