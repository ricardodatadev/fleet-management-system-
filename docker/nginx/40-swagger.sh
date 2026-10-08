#!/bin/sh
# Habilita o location do Swagger UI somente com L5_SWAGGER_ENABLED=true.
set -eu
mkdir -p /etc/nginx/enabled
rm -f /etc/nginx/enabled/swagger.conf
case "$(printf '%s' "${L5_SWAGGER_ENABLED:-false}" | tr 'A-Z' 'a-z')" in
    true|1)
        cp /etc/nginx/swagger.location.conf /etc/nginx/enabled/swagger.conf
        echo "40-swagger: Swagger UI habilitado em /api/documentation" ;;
    *)
        echo "40-swagger: Swagger UI desabilitado" ;;
esac
