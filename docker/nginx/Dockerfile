# syntax=docker/dockerfile:1
# Contexto: raiz do repo. O build do frontend (multi-stage) é plugado na F1-21;
# até lá a SPA é um placeholder estático.
FROM nginx:1.30.5-alpine AS web

RUN rm /etc/nginx/conf.d/default.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
# Placeholder até existir frontend/dist (F1-21)
RUN printf '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>SIGOF-M</title><body>SIGOF-M — frontend em construção</body></html>\n' > /usr/share/nginx/html/index.html
EXPOSE 80
