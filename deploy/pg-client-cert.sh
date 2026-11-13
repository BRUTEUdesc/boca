#!/bin/bash
# Emite o certificado de cliente do PostgreSQL para um autojudge (rodar como root no servidor BOCA,
# depois do setup-server.sh, que cria a CA). O AJ só conecta no banco com SSL + este certificado + senha.
#   sudo deploy/pg-client-cert.sh aj3
# Depois copie para o AJ (como root, que é quem roda o autojudge; o libpq acha sozinho):
#   /root/.postgresql/postgresql.crt  (644)   /root/.postgresql/postgresql.key  (600)
set -euo pipefail
CA=/root/pg-clientca
DBUSER=bocauser   # o CN tem que ser o usuário do banco (clientcert=verify-full)
[ "$(id -u)" = 0 ] || { echo "rode como root"; exit 1; }
[[ "${1:-}" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "uso: $0 <nome-do-aj>"; exit 1; }
[ -f "$CA/ca.key" ] || { echo "falta a CA em $CA (rode deploy/setup-server.sh)"; exit 1; }
cd "$CA"
[ -f "$1.crt" ] && { echo "$CA/$1.crt já existe"; exit 1; }
openssl req -new -nodes -newkey rsa:3072 -keyout "$1.key" -out "$1.csr" -subj "/CN=$DBUSER" 2>/dev/null
openssl x509 -req -in "$1.csr" -CA ca.crt -CAkey ca.key -CAcreateserial -days 3650 -out "$1.crt" 2>/dev/null
chmod 600 "$1.key"; rm -f "$1.csr"
echo "ok: $CA/$1.crt e $CA/$1.key"
