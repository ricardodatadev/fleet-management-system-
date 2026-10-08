# syntax=docker/dockerfile:1
# Contexto de build: raiz do repo (ver docker-compose.yml). .dockerignore filtra o contexto.
# PHP 8.4: série estável atual suportada pelo Laravel 13 (8.3–8.5), com suporte mais longo que 8.3; permite migrar ao Pest 5 no futuro.

FROM php:8.4.26-fpm-alpine AS base

RUN apk add --no-cache fcgi libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl opcache intl zip \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY docker/laravel/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/laravel/php-fpm.d/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

# ---- ferramentas de composer (usado para scaffold e instalação de dependências)
FROM base AS composer-tools
RUN apk add --no-cache git unzip
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer

# ---- dependências
FROM composer-tools AS vendor
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

# ---- imagem final
FROM base AS app
WORKDIR /var/www/html
COPY --from=composer-tools /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data backend/ .
# OpenAPI versionada (docs/ fica ao lado de html/, como no repositório: base_path('../docs'))
COPY --chown=www-data:www-data docs/api/ /var/www/docs/api/
COPY docker/laravel/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache vendor \
    && find vendor -type d -name .temp -exec chmod a+rwx {} + \
    && php artisan package:discover --ansi
USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
