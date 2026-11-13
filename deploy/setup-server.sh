#!/bin/bash
# Setup/ajuste do servidor web do BOCA (fork BRUTEUdesc) em Ubuntu.
#
# Idempotente: pode rodar quantas vezes quiser. Use:
#   - num servidor novo (depois de clonar o repo em /var/www/boca);
#   - depois de todo do-release-upgrade (PHP e PostgreSQL mudam de versão).
#
#   sudo deploy/setup-server.sh
#
# Não faz: clonar o código, criar o banco, migrar cluster do PostgreSQL entre versões
# (veja o fim da saída, que avisa quando há cluster antigo para migrar).
set -euo pipefail

# ---- configuração --------------------------------------------------------------------------
# Redes que podem acessar o PostgreSQL (os autojudges conectam direto no banco).
# 10.20.0.0/16 = rede interna UDESC; 200.19.106.0/24 = NAT pelo qual os AJs chegam no servidor.
DB_ALLOWED_NETS=("10.20.0.0/16" "200.19.106.0/24")

PACKAGES=(
    apache2 php-fpm php-cli php-pgsql php-gd php-xml php-zip php-intl php-mbstring
    postgresql postgresql-client python3-matplotlib openssl wget git libany-uri-escape-perl
    ufw
)

PHP_INI=$(cat <<'EOF'
; BOCA (gerado por deploy/setup-server.sh)
upload_max_filesize = 400M
post_max_size = 400M
memory_limit = 1536M
max_execution_time = 300
output_buffering = 4096
EOF
)
# --------------------------------------------------------------------------------------------

[ "$(id -u)" = 0 ] || { echo "rode como root"; exit 1; }
export DEBIAN_FRONTEND=noninteractive
log() { echo "==> $*"; }

log "pacotes"
apt-get update -qq
apt-get install -y -qq "${PACKAGES[@]}" >/dev/null
apt-mark manual "${PACKAGES[@]}" >/dev/null
# metapacote antigo (substituído por este script)
if dpkg -s boca-udesc-deps >/dev/null 2>&1; then
    apt-get remove -y -qq boca-udesc-deps >/dev/null
    log "removido metapacote boca-udesc-deps"
fi

PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
log "PHP $PHPV"
for sapi in fpm cli; do
    printf '%s\n' "$PHP_INI" > "/etc/php/$PHPV/$sapi/conf.d/99-boca.ini"
done
POOL=/etc/php/$PHPV/fpm/pool.d/www.conf
sed -i -E \
    -e 's/^pm\.max_children = .*/pm.max_children = 20/' \
    -e 's/^pm\.start_servers = .*/pm.start_servers = 2/' \
    -e 's/^pm\.min_spare_servers = .*/pm.min_spare_servers = 1/' \
    -e 's/^pm\.max_spare_servers = .*/pm.max_spare_servers = 3/' "$POOL"
php-fpm"$PHPV" -t 2>/dev/null
systemctl enable -q --now "php$PHPV-fpm"
# reload (graceful) aplica php.ini e pool sem derrubar requisições em andamento
systemctl reload "php$PHPV-fpm"

log "apache -> php$PHPV-fpm"
a2enmod -q proxy_fcgi setenvif >/dev/null
for c in /etc/apache2/conf-enabled/php*-fpm.conf; do
    [ -e "$c" ] || continue
    n=$(basename "$c" .conf)
    [ "$n" = "php$PHPV-fpm" ] || a2disconf -q "$n"
done
a2enconf -q "php$PHPV-fpm"
apache_test=$(apache2ctl configtest 2>&1) || true
if ! grep -q "Syntax OK" <<<"$apache_test"; then
    printf '%s\n' "$apache_test"
    echo "apache2ctl configtest falhou; apache não foi recarregado"
    exit 1
fi
systemctl enable -q apache2
systemctl reload-or-restart apache2

PGV=$(pg_lsclusters -h | awk '$2=="main" && $3=="5432" {print $1}' | head -1)
[ -n "$PGV" ] || { echo "nenhum cluster PostgreSQL 'main' na porta 5432"; exit 1; }
log "PostgreSQL $PGV"
PGDIR=/etc/postgresql/$PGV/main
mkdir -p "$PGDIR/conf.d"
# listen_addresses só vale com restart: reinicia só se este arquivo mudar agora
PGCONF=$PGDIR/conf.d/000-boca.conf
PGCONF_WANT="listen_addresses = '*'"
pg_restart=0
if [ "$(cat "$PGCONF" 2>/dev/null)" != "$PGCONF_WANT" ]; then
    printf '%s\n' "$PGCONF_WANT" > "$PGCONF"
    pg_restart=1
fi
HBA=$PGDIR/pg_hba.conf
# nunca deixar o banco aberto para a internet (IPv4 e IPv6)
sed -i -E '/^(host|hostssl)\s+all\s+all\s+(0\.0\.0\.0\/0|::\/0)(\s|$)/d' "$HBA"
for net in "${DB_ALLOWED_NETS[@]}"; do
    grep -qE "^host\s+all\s+all\s+${net//./\\.}\s" "$HBA" ||
        printf 'host\tall\t\tall\t\t%s\t\tmd5\n' "$net" >> "$HBA"
done
# pg_hba só precisa de reload (não derruba conexões dos autojudges)
if [ "$pg_restart" = 1 ] || ! systemctl is-active -q "postgresql@$PGV-main"; then
    systemctl restart "postgresql@$PGV-main"
else
    systemctl reload "postgresql@$PGV-main"
fi

log "firewall (ufw)"
if ufw status | grep -E "^5432/tcp( \(v6\))?\s+ALLOW\s+Anywhere" >/dev/null; then
    ufw delete allow 5432/tcp >/dev/null
fi
for net in "${DB_ALLOWED_NETS[@]}"; do
    ufw allow from "$net" to any port 5432 proto tcp >/dev/null
done

log "verificação"
code=$(curl -sk -o /dev/null -m 15 -w '%{http_code}' https://localhost/ || true)
echo "    HTTPS local: $code"
php -r "echo '    upload_max_filesize CLI: '.ini_get('upload_max_filesize').PHP_EOL;"
old=$(pg_lsclusters -h | awk -v v="$PGV" '$1!=v && $4 ~ /^online/ {print $1"/"$2" ("$4")"}')
if [ -n "$old" ]; then
    echo "    ATENÇÃO: cluster(s) PostgreSQL antigo(s): $old"
    echo "    migrar: pg_dropcluster $PGV main --stop; pg_upgradecluster -m upgrade --link <ver_antiga> main; e rode este script de novo"
fi
log "ok"
