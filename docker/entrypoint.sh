#!/bin/sh
# Prepara banco, chave e usuário admin antes de subir o painel ou o worker.
set -e
cd /app
php bin/vau setup
chown -R www-data:www-data storage 2>/dev/null || true
exec "$@"
