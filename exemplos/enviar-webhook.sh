#!/bin/sh
# Simula um push do GitHub assinado, para testar o Vau sem sair do terminal.
# Uso: ./exemplos/enviar-webhook.sh <slug-do-projeto> <segredo-do-webhook> [url-do-vau]
set -eu
SLUG=$1; SEGREDO=$2; URL=${3:-http://127.0.0.1:8080}
CORPO=$(dirname "$0")/github-push.json
ASSINATURA="sha256=$(openssl dgst -sha256 -hmac "$SEGREDO" < "$CORPO" | awk '{print $NF}')"
curl -sS -X POST "$URL/webhook/$SLUG" \
  -H "Content-Type: application/json" \
  -H "X-GitHub-Event: push" \
  -H "X-GitHub-Delivery: teste-$(date +%s)" \
  -H "X-Hub-Signature-256: $ASSINATURA" \
  --data-binary "@$CORPO"
echo
