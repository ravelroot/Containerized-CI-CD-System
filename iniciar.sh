#!/bin/sh
# Sobe o Vau localmente: prepara tudo, inicia um worker em segundo plano e o painel.
set -e
cd "$(dirname "$0")"
command -v php >/dev/null 2>&1 || { echo "PHP 8.2 ou mais recente não encontrado. Instale em https://www.php.net/downloads"; exit 1; }
php bin/vau setup
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  echo "Docker encontrado: iniciando o worker em segundo plano (log em storage/logs/worker.log)."
  php bin/vau worker >> storage/logs/worker.log 2>&1 &
  WORKER=$!
  trap 'kill $WORKER 2>/dev/null' EXIT INT TERM
else
  echo "Docker não respondeu. O painel sobe, mas os builds só rodam com o worker: php bin/vau worker"
fi
php bin/vau serve --host=127.0.0.1 --port=8080
