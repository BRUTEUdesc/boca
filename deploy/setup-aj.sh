#!/bin/bash
# Setup/ajuste de um autojudge do BOCA (fork BRUTEUdesc) em Ubuntu.
#
# Idempotente: pode rodar quantas vezes quiser. Use:
#   - num autojudge novo (depois de clonar o repo em /var/www/boca e criar o .env);
#   - depois de todo do-release-upgrade (o jail é refeito na versão nova do Ubuntu).
#
#   sudo deploy/setup-aj.sh
#
# O .env do autojudge só precisa de BOCA_DB_HOST (IP do servidor BOCA) e BOCA_DB_PASS (igual ao do
# servidor). Não copie BOCA_DB_SUPERPASS, BOCA_BASE_PASS nem BOCA_KEY: o autojudge não os usa e esta
# máquina roda código dos times. BOCA_AUTOJUDGE_IP é o identificador deste AJ no BOCA (aj1, aj2...;
# distinto por AJ): se faltar no .env, é acrescentado a partir da variável de ambiente
# BOCA_AUTOJUDGE_IP ou do hostname (brute-AJ1 -> aj1); se já existir, não é alterado.
# O send_ip_to_boca precisa do token em /root/.boca_aj_token (o mesmo do servidor).
#
# O que faz: pacotes do host, vendor (phpdotenv), .env 600, /etc/boca.conf, jail em /home/bocajail
# (debootstrap da mesma versão do host, com gcc/g++/OpenJDK/Kotlin/Python), safeexec, bloqueio de
# rede do uid bocajail, services (bocaautojudge, send_ip_to_boca) e um teste das 5 linguagens.
# Não liga o autojudge se ele estiver desligado (só reinicia se já estiver habilitado).
# Lógica de julgamento: igual ao upstream (src/private/autojudging.php + scripts dos problemas).
set -euo pipefail

# ---- configuração --------------------------------------------------------------------------
BOCADIR=/var/www/boca
JAIL=/home/bocajail
KOTLIN_VERSION=${KOTLIN_VERSION:-2.1.21}
JDK_PKG=${JDK_PKG:-openjdk-21-jdk-headless}
MIRROR=http://archive.ubuntu.com/ubuntu

HOST_PACKAGES=(
    php-cli php-pgsql php-zip php-mbstring php-xml composer git
    gcc libc6-dev debootstrap unzip curl ca-certificates python3 python3-requests iptables
)
JAIL_PACKAGES=(gcc g++ "$JDK_PKG" python3)
# --------------------------------------------------------------------------------------------

[ "$(id -u)" = 0 ] || { echo "rode como root"; exit 1; }
export DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8
REPO=$(cd "$(dirname "$0")/.." && pwd)
log() { echo "==> $*"; }

# limpeza mesmo em falha: diretório de teste, download do Kotlin e o /proc montado no jail
T="" t="" PROC_MOUNTED=0
# desmonta tudo em $1 e abaixo dele (mais fundo primeiro)
umount_under() {
    findmnt -rn -o TARGET | awk -v p="$1" '$0 == p || index($0, p "/") == 1' | sort -r |
        xargs -r -n1 umount -l
}
jail_proc_umount() {
    if mountpoint -q "$JAIL/proc"; then
        umount "$JAIL/proc" 2>/dev/null || umount -l "$JAIL/proc" ||
            echo "AVISO: não consegui desmontar $JAIL/proc" >&2
    fi
    PROC_MOUNTED=0
}
cleanup() {
    local rc=$?
    [ -n "$T" ] && rm -rf "$T"
    [ -n "$t" ] && rm -rf "$t"
    [ "$PROC_MOUNTED" = 1 ] && jail_proc_umount
    [ "$rc" = 0 ] || echo "setup-aj.sh: abortou (código $rc)" >&2
}
trap cleanup EXIT
trap 'echo "setup-aj.sh: ERRO na linha $LINENO: $BASH_COMMAND" >&2' ERR

[ -f "$BOCADIR/src/private/autojudging.php" ] || { echo "código do BOCA não encontrado em $BOCADIR"; exit 1; }
[ -f "$BOCADIR/.env" ] || { echo "falta $BOCADIR/.env"; exit 1; }
# só para autojudge: o servidor web precisa que o www-data leia src/private.
# Detecta o servidor também com os serviços parados (site do apache no BOCA, cluster do postgres).
is_server() {
    systemctl is-active -q apache2 2>/dev/null && return 0
    systemctl is-active -q postgresql 2>/dev/null && return 0
    [ -d /etc/apache2/sites-enabled ] && grep -RqsF -- "$BOCADIR" /etc/apache2/sites-enabled/ && return 0
    if dpkg-query -W -f='${db:Status-Status} ${Package}\n' 'postgresql-[0-9]*' 2>/dev/null |
        grep -q '^installed ' && command -v pg_lsclusters >/dev/null &&
        [ -n "$(pg_lsclusters -h 2>/dev/null || true)" ]; then
        return 0
    fi
    return 1
}
if is_server; then
    echo "apache2 com site em $BOCADIR ou postgresql (ativo ou com cluster): isto parece o servidor BOCA, não um autojudge (use setup-server.sh)"; exit 1
fi
# o ln -sfn abaixo trocaria um /bocajail de verdade por um link dentro dele
if [ -e /bocajail ] && [ ! -L /bocajail ]; then
    echo "/bocajail existe e não é symlink (jail antigo?): mova-o manualmente; este script cria /bocajail -> $JAIL"; exit 1
fi

# não julgar enquanto o jail é montado/alterado (no fim ele volta se estiver habilitado)
systemctl stop bocaautojudge.service 2>/dev/null || true

log "pacotes do host"
apt-get update -qq
apt-get install -y -qq "${HOST_PACKAGES[@]}" >/dev/null
apt-mark manual "${HOST_PACKAGES[@]}" >/dev/null

log "vendor (phpdotenv)"
if [ ! -f "$BOCADIR/vendor/autoload.php" ]; then
    (cd "$BOCADIR" && COMPOSER_ALLOW_SUPERUSER=1 composer require -q --no-interaction vlucas/phpdotenv:^5.6)
fi
php -r "require '$BOCADIR/vendor/autoload.php'; exit(class_exists('Dotenv\\Dotenv') ? 0 : 1);"

log ".env e /etc/boca.conf"
chown root:root "$BOCADIR/.env"
chmod 600 "$BOCADIR/.env"
DBHOST=$(sed -nE 's/^BOCA_DB_HOST=["'\'']?([^"'\'' ]*).*/\1/p' "$BOCADIR/.env" | tail -1)
[ -n "$DBHOST" ] || { echo "falta BOCA_DB_HOST no $BOCADIR/.env"; exit 1; }
if ! grep -qE '^BOCA_AUTOJUDGE_IP=' "$BOCADIR/.env"; then
    ajid=${BOCA_AUTOJUDGE_IP:-$(hostname | sed -nE 's/^brute-AJ([0-9]+)$/aj\1/p')}
    if [[ "$ajid" =~ ^[A-Za-z0-9._-]+$ ]]; then
        # garante o \n final antes de acrescentar
        [ -z "$(tail -c1 "$BOCADIR/.env")" ] || echo >> "$BOCADIR/.env"
        printf 'BOCA_AUTOJUDGE_IP=%s\n' "$ajid" >> "$BOCADIR/.env"
        log "BOCA_AUTOJUDGE_IP=$ajid acrescentado ao .env"
    else
        echo "AVISO: falta BOCA_AUTOJUDGE_IP no .env (ex.: aj1); rode com BOCA_AUTOJUDGE_IP=ajN $0" >&2
    fi
fi
for v in BOCA_DB_SUPERPASS BOCA_BASE_PASS BOCA_KEY; do
    grep -qE "^$v=" "$BOCADIR/.env" &&
        echo "AVISO: $v está no .env do autojudge, que não o usa; pode ser removido" >&2
done
[ -f /root/.boca_aj_token ] || echo "AVISO: falta /root/.boca_aj_token (send_ip_to_boca vai falhar)" >&2
printf 'bdserver=%s\nbocadir=%s\nbdcreated=y\n' "$DBHOST" "$BOCADIR" > /etc/boca.conf
# arquivos privados do BOCA não podem ser legíveis pelo bocajail
chmod -R go-rwx "$BOCADIR/src/private" 2>/dev/null || true
[ -f /root/.boca_aj_token ] && chmod 600 /root/.boca_aj_token

log "usuário bocajail"
if ! id -u bocajail >/dev/null 2>&1; then
    useradd -M -s /usr/sbin/nologin -d "$JAIL" -g users bocajail
fi

CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")
JAILCODE=$( (. "$JAIL/etc/os-release" 2>/dev/null && echo "$VERSION_CODENAME") || true)
if [ "$JAILCODE" != "$CODENAME" ]; then
    # debootstrap em $JAIL.new e troca no fim: uma falha no meio não deixa o jail pela metade
    if [ -e "$JAIL.new" ]; then
        log "removendo $JAIL.new de uma execução anterior"
        umount_under "$JAIL.new"
        rm -rf --one-file-system "$JAIL.new"
    fi
    log "debootstrap $CODENAME em $JAIL.new (demora)"
    debootstrap --variant=minbase "$CODENAME" "$JAIL.new" "$MIRROR" >/dev/null
    umount_under "$JAIL.new"
    if [ -e "$JAIL" ]; then
        old="$JAIL.old-${JAILCODE:-x}-$(date +%Y%m%d%H%M)"
        log "jail é '$JAILCODE', host é '$CODENAME': movendo o antigo para $old"
        umount_under "$JAIL"
        mv "$JAIL" "$old"
    fi
    mv "$JAIL.new" "$JAIL"
fi

log "pacotes do jail"
cp /etc/apt/sources.list.d/ubuntu.sources "$JAIL/etc/apt/sources.list.d/" 2>/dev/null || true
[ -f "$JAIL/etc/apt/sources.list.d/ubuntu.sources" ] && : > "$JAIL/etc/apt/sources.list"
mountpoint -q "$JAIL/proc" || mount -t proc proc "$JAIL/proc"
PROC_MOUNTED=1
chroot "$JAIL" apt-get update -qq
chroot "$JAIL" apt-get install -y -qq --no-install-recommends "${JAIL_PACKAGES[@]}" >/dev/null
chroot "$JAIL" apt-get dist-upgrade -y -qq >/dev/null
chroot "$JAIL" apt-get clean
# o /proc do jail não fica montado (o runit.sh dos problemas monta quando precisa)
jail_proc_umount

log "Kotlin $KOTLIN_VERSION no jail"
if [ "$(cut -d- -f1 "$JAIL/opt/kotlinc/build.txt" 2>/dev/null || true)" != "$KOTLIN_VERSION" ]; then
    t=$(mktemp -d)
    base="https://github.com/JetBrains/kotlin/releases/download/v$KOTLIN_VERSION/kotlin-compiler-$KOTLIN_VERSION.zip"
    curl -fsSL -o "$t/k.zip" "$base"
    curl -fsSL -o "$t/k.sha256" "$base.sha256"
    echo "$(awk '{print $1}' "$t/k.sha256")  $t/k.zip" | sha256sum -c --quiet
    unzip -q "$t/k.zip" -d "$t"
    rm -rf "$JAIL/opt/kotlinc"
    mkdir -p "$JAIL/opt"
    mv "$t/kotlinc" "$JAIL/opt/kotlinc"
    rm -rf "$t"
    t=""
fi
chown -R root:root "$JAIL/opt/kotlinc"
chmod -R a+rX,go-w "$JAIL/opt/kotlinc"

log "safeexec (estático, sem setuid: os scripts já o chamam como root)"
gcc -g -O2 -static "$REPO/tools/safeexec.c" -o /usr/bin/safeexec
chown root:root /usr/bin/safeexec
chmod 0755 /usr/bin/safeexec
install -o root -g root -m 0755 /usr/bin/safeexec "$JAIL/usr/bin/safeexec"

log "permissões do jail"
# o dono do jail tem que ser root: o runit.sh dos problemas entra no chroot como root,
# então um jail do bocajail deixaria o código dos times trocar os binários do jail.
chown root:root "$JAIL"
chmod 0755 "$JAIL"
mkdir -p "$JAIL/tmp"
chown root:root "$JAIL/tmp"
chmod 1777 "$JAIL/tmp"
ln -sfn "$JAIL" /bocajail
# nenhum binário setuid/setgid no jail (o bocajail não tem por que virar root lá dentro)
find "$JAIL" -xdev -type f -perm /6000 -exec chmod ug-s {} +

log "rede do bocajail bloqueada"
install -m 0755 "$REPO/deploy/aj/boca-jail-net" /usr/sbin/boca-jail-net
install -m 0644 "$REPO/deploy/aj/boca-jail-net.service" /etc/systemd/system/
/usr/sbin/boca-jail-net

log "services"
install -m 0755 "$REPO/deploy/aj/run_aj" /usr/sbin/run_aj
install -m 0700 "$REPO/tools/boca-autojudge.sh" /usr/sbin/boca-autojudge
install -m 0700 "$REPO/deploy/aj/send_ip_to_boca" /usr/sbin/send_ip_to_boca
for u in bocaautojudge.service send_ip_to_boca.service send_ip_to_boca.timer; do
    install -m 0644 "$REPO/deploy/aj/$u" /etc/systemd/system/
done
# unidades antigas (antes ficavam em /root/send_ip_to_boca + reload-send_ip_to_boca.service)
if [ -f /etc/systemd/system/reload-send_ip_to_boca.service ]; then
    rm -f /etc/systemd/system/reload-send_ip_to_boca.service
fi
systemctl daemon-reload
systemctl enable -q boca-jail-net.service send_ip_to_boca.timer
systemctl start boca-jail-net.service send_ip_to_boca.timer
# a unit antiga tinha RemainAfterExit: a instância dela fica "active (exited)" e o timer novo não
# dispara até o reboot. O restart já roda a versão nova (IP real no servidor); sem rede, só avisa.
systemctl restart send_ip_to_boca.service ||
    echo "AVISO: send_ip_to_boca falhou (rede?); o timer tenta de novo a cada minuto" >&2

log "teste das linguagens no jail (como bocajail, com safeexec)"
U=$(id -u bocajail); G=$(id -g bocajail)
# como o runit.sh dos problemas: /proc montado só durante a execução (a JVM usa)
mountpoint -q "$JAIL/proc" || mount -t proc proc "$JAIL/proc"
PROC_MOUNTED=1
T=$(mktemp -d "$JAIL/tmp/smoke.XXXXXX")
chmod 755 "$T"; chown bocajail "$T"
D=${T#"$JAIL"}
cat > "$T/a.c"    <<'X'
#include <stdio.h>
int main(void){int a,b;scanf("%d %d",&a,&b);printf("%d\n",a+b);return 0;}
X
cat > "$T/a.cpp"  <<'X'
#include <bits/stdc++.h>
int main(){int a,b;std::cin>>a>>b;auto f=[](auto x,auto y){return x+y;};std::cout<<f(a,b)<<"\n";}
X
cat > "$T/Main.java" <<'X'
import java.util.*;
public class Main{public static void main(String[] x){Scanner s=new Scanner(System.in);System.out.println(s.nextInt()+s.nextInt());}}
X
cat > "$T/a.kt"   <<'X'
fun main(){val (a,b)=readLine()!!.trim().split(" ").map{it.toInt()};println(a+b)}
X
cat > "$T/a.py"   <<'X'
a,b=map(int,input().split());print(a+b)
X
cat > "$T/net.py" <<X
import socket,sys
for h,p in (("$DBHOST",5432),("127.0.0.1",22),("1.1.1.1",443)):
    try:
        socket.create_connection((h,p),3); print("ABERTO",h,p); sys.exit(1)
    except OSError: pass
print("bloqueado")
X
echo "2 3" > "$T/in"
chown bocajail "$T"/*
SE="/usr/bin/safeexec -n0 -C$D -i$D/in -o$D/out -e$D/err -U$U -G$G -F512 -u512 -t60 -T60 -d40000000000 -m40000000000 --"
fail=0
check() { # nome comando_compilar(ou vazio) comando_rodar
    local name=$1 comp=$2 run=$3
    rm -f "$T/out" "$T/err"
    if [ -n "$comp" ] && ! chroot "$JAIL" /bin/sh -c "cd $D && $SE /bin/sh -c '$comp'" >/dev/null 2>&1; then
        echo "    $name: FALHOU (compilação)"; tail -3 "$T/err" 2>/dev/null || true; fail=1; return
    fi
    rm -f "$T/out"
    chroot "$JAIL" /bin/sh -c "cd $D && $SE /bin/sh -c '$run'" >/dev/null 2>&1 || true
    if [ "$(cat "$T/out" 2>/dev/null)" = "5" ]; then echo "    $name: ok"
    else echo "    $name: FALHOU"; tail -3 "$T/err" 2>/dev/null || true; fail=1; fi
}
check "C"      "gcc -O2 -o a a.c -lm"                    "./a"
check "C++20"  "g++ -std=c++20 -O2 -o b a.cpp"           "./b"
check "Java"   "javac Main.java"                         "java -cp . Main"
check "Kotlin" "/opt/kotlinc/bin/kotlinc a.kt -include-runtime -d a.jar" "/opt/kotlinc/bin/kotlin a.jar"
check "Python" ""                                        "python3 a.py"
rm -f "$T/out"
chroot "$JAIL" /bin/sh -c "cd $D && $SE /bin/sh -c 'python3 net.py'" >/dev/null 2>&1 || true
if [ "$(cat "$T/out" 2>/dev/null)" = "bloqueado" ]; then echo "    rede do bocajail: bloqueada"
else echo "    rede do bocajail: FALHOU ($(cat "$T/out" 2>/dev/null))"; fail=1; fi
asjail() { setpriv --reuid="$U" --regid="$G" --clear-groups -- "$@"; }
if ! asjail cat /etc/hostname >/dev/null 2>&1; then
    echo "    teste do .env: FALHOU (setpriv não funcionou)"; fail=1
elif asjail cat "$BOCADIR/.env" >/dev/null 2>&1 || asjail cat /root/.boca_aj_token >/dev/null 2>&1; then
    echo "    .env/token legível pelo bocajail: FALHOU"; fail=1
else echo "    .env e token: protegidos"; fi
rm -rf "$T"
T=""
jail_proc_umount

if [ "$fail" != 0 ]; then
    echo "ATENÇÃO: teste falhou; o autojudge NÃO deve ser ligado"
    exit 1
fi
if systemctl is-enabled -q bocaautojudge.service 2>/dev/null; then
    systemctl restart bocaautojudge.service
    log "bocaautojudge reiniciado"
else
    log "bocaautojudge está desabilitado; para ligar: systemctl enable --now bocaautojudge"
fi
log "ok"
