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
BOCADIR=/var/www/boca
# IPs que podem acessar o PostgreSQL (os autojudges conectam direto no banco, com SSL e SCRAM).
# São os IPs de NAT pelos quais cada AJ chega no servidor: AJ1 (10.20.161.98) -> .162,
# AJ2 (10.20.162.51) -> .163. AJ novo ou NAT trocado: atualizar aqui.
DB_ALLOWED_IPS=("200.19.106.162/32" "200.19.106.163/32")
# Redes inteiras (md5) que versões antigas deste script liberavam: removidas do pg_hba e do ufw.
DB_LEGACY_NETS=("10.20.0.0/16" "200.19.106.0/24")

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

# hooks do git em $BOCADIR: git rodado como root (pull/checkout) deixaria arquivos novos com dono root
GIT_HOOK=$(cat <<'EOF'
#!/bin/sh
# instalado por deploy/setup-server.sh: o PHP roda como www-data e precisa escrever em arquivos
# versionados (score.sep, webcast.sep, admin/report/webcast.php); git rodado como root os deixaria root.
top=$(git rev-parse --show-toplevel) || exit 0
find "$top" -path "$top/.git" -prune -o -path "$top/.env" -prune -o ! -user www-data -exec chown -h www-data:www-data {} +
exit 0
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
    -e 's/^pm\.start_servers = .*/pm.start_servers = 4/' \
    -e 's/^pm\.min_spare_servers = .*/pm.min_spare_servers = 2/' \
    -e 's/^pm\.max_spare_servers = .*/pm.max_spare_servers = 6/' "$POOL"
# cookie de sessão do PHP só por HTTPS, sem acesso por JS e SameSite=Lax
hdr=0
for kv in "php_admin_value[session.cookie_samesite] = Lax" \
    "php_admin_flag[session.cookie_secure] = on" "php_admin_flag[session.cookie_httponly] = on"; do
    k=${kv%% *}
    grep -v '^\s*;' "$POOL" | grep -qF -- "$k" && continue
    [ "$hdr" = 1 ] || printf '\n; BOCA (deploy/setup-server.sh): cookie de sessão\n' >> "$POOL"
    hdr=1
    printf '%s\n' "$kv" >> "$POOL"
done
php-fpm"$PHPV" -t 2>/dev/null
systemctl enable -q --now "php$PHPV-fpm"
# reload (graceful) aplica php.ini e pool sem derrubar requisições em andamento
systemctl reload "php$PHPV-fpm"

log "apache -> php$PHPV-fpm"
# rewrite/ssl/proxy_http/proxy_wstunnel: vhost do BOCA (redirect HTTPS e proxy do /animeitor/)
a2enmod -q proxy_fcgi setenvif rewrite ssl proxy_http proxy_wstunnel >/dev/null
# mpm_event: mais processos/threads ociosos para aguentar o pico do início da prova
sed -i -E \
    -e 's/^(\s*)StartServers\s.*/\1StartServers            4/' \
    -e 's/^(\s*)MinSpareThreads\s.*/\1MinSpareThreads         75/' \
    -e 's/^(\s*)MaxSpareThreads\s.*/\1MaxSpareThreads         250/' \
    /etc/apache2/mods-available/mpm_event.conf
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
for net in "${DB_LEGACY_NETS[@]}"; do
    sed -i -E "\#^(host|hostssl)\s+all\s+all\s+${net//./\\.}(\s|$)#d" "$HBA"
done
for ip in "${DB_ALLOWED_IPS[@]}"; do
    re=${ip//./\\.}
    # sem SSL não: uma linha "host" do mesmo IP antes da hostssl casaria primeiro
    sed -i -E "\#^host\s+all\s+all\s+${re%/32}(/32)?\s#d" "$HBA"
    grep -qE "^hostssl\s+all\s+all\s+${re}\s+scram-sha-256(\s|$)" "$HBA" ||
        printf '%-8s%-16s%-16s%-24s%s\n' hostssl all all "$ip" scram-sha-256 >> "$HBA"
done
# pg_hba só precisa de reload (não derruba conexões dos autojudges)
if [ "$pg_restart" = 1 ] || ! systemctl is-active -q "postgresql@$PGV-main"; then
    systemctl restart "postgresql@$PGV-main"
else
    systemctl reload "postgresql@$PGV-main"
fi
psql_q() { (cd / && runuser -u postgres -- psql -XAtq -v ON_ERROR_STOP=1 -c "$1"); }
# hostssl precisa de ssl = on (o padrão do pacote do Ubuntu, com o certificado snakeoil)
[ "$(psql_q 'SHOW ssl')" = on ] ||
    echo "    ATENÇÃO: ssl = off no PostgreSQL; as linhas hostssl do pg_hba não valem e os AJs não conectam"
# ALTER SYSTEM (postgresql.auto.conf), como foi feito em prod; só se o valor for outro
pg_reload=0
pg_set() { # nome valor
    if [ "$(psql_q "SHOW $1")" != "$2" ]; then
        psql_q "ALTER SYSTEM SET $1 = '$2'" >/dev/null
        pg_reload=1
    fi
}
pg_set password_encryption scram-sha-256
# loga conexões e autenticação (quem entra no banco); o formato com lista é do PG 18
if [ "${PGV%%.*}" -ge 18 ]; then pg_set log_connections receipt,authentication
else pg_set log_connections on; fi
[ "$pg_reload" = 0 ] || psql_q 'SELECT pg_reload_conf()' >/dev/null
md5roles=$(psql_q "SELECT string_agg(rolname, ' ') FROM pg_authid WHERE rolpassword LIKE 'md5%'")
if [ -n "$md5roles" ]; then
    echo "    ATENÇÃO: senha em md5 (não entra com scram-sha-256 no pg_hba): $md5roles"
    echo "    refazer com: ALTER ROLE <nome> PASSWORD '<a mesma senha>' (com password_encryption = scram-sha-256)"
fi

log "firewall (ufw)"
ufw_st=$(ufw status)
grep -q '^Status: active' <<<"$ufw_st" ||
    echo "    ATENÇÃO: ufw inativo; só o pg_hba protege a porta 5432 (ligar com: ufw enable)"
if grep -E "^5432/tcp( \(v6\))?\s+ALLOW\s+Anywhere" <<<"$ufw_st" >/dev/null; then
    ufw delete allow 5432/tcp >/dev/null
fi
for net in "${DB_LEGACY_NETS[@]}"; do
    if grep -E "^5432/tcp\s+ALLOW\s+${net//./\\.}(\s|$)" <<<"$ufw_st" >/dev/null; then
        ufw delete allow from "$net" to any port 5432 proto tcp >/dev/null
    fi
done
for ip in "${DB_ALLOWED_IPS[@]}"; do
    # o ufw mostra o host sem /32
    re=${ip%/32}
    grep -E "^5432/tcp\s+ALLOW\s+${re//./\\.}(\s|$)" <<<"$ufw_st" >/dev/null ||
        ufw allow from "${ip%/32}" to any port 5432 proto tcp >/dev/null
done

log "dono dos arquivos em $BOCADIR e hooks do git"
if [ -d "$BOCADIR" ]; then
    # tudo www-data, menos .git (root, como o git que roda nele) e .env (root:www-data 640)
    find "$BOCADIR" -path "$BOCADIR/.git" -prune -o -path "$BOCADIR/.env" -prune -o ! -user www-data \
        -exec chown -h www-data:www-data {} +
    if [ -f "$BOCADIR/.env" ]; then
        chown root:www-data "$BOCADIR/.env"
        chmod 640 "$BOCADIR/.env"
    fi
    if [ -d "$BOCADIR/.git/hooks" ]; then
        for h in post-checkout post-merge post-rewrite; do
            f=$BOCADIR/.git/hooks/$h
            [ "$(cat "$f" 2>/dev/null)" = "$GIT_HOOK" ] || printf '%s\n' "$GIT_HOOK" > "$f"
            chown root:root "$f"
            chmod 755 "$f"
        done
    fi
else
    echo "    ATENÇÃO: $BOCADIR não existe (clonar o repo lá e rodar de novo)"
fi

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
