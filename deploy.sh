#!/usr/bin/env bash
#
# Nasazení Ratatosku na server. Spouštěj z kořene projektu:
#
#   ./deploy.sh --check     jen ověří prostředí, nic nemění
#   ./deploy.sh             nasadí
#   ./deploy.sh --no-build  nasadí bez přestavby image (jen změny v kódu)
#
# Cíl se dá přebít proměnnými DEPLOY_HOST a DEPLOY_DIR.
#
# Co skript NEDĚLÁ (schválně, je to práce roota):
#   - nginx na hostu (vzor je v nginx.example.conf)
#   - založení PostgreSQL databáze a uživatele
#   - certifikát

set -euo pipefail

# Výstup jde na obrazovku i do souboru — když se okno zavře, dá se dočíst.
LOG="${DEPLOY_LOG:-deploy.log}"
if [ -z "${RATATOSK_TEE:-}" ]; then
    export RATATOSK_TEE=1
    printf '\n===== %s =====\n' "$(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG"
    exec > >(tee -a "$LOG") 2>&1
fi

HOST="${DEPLOY_HOST:-manx@a4.arthur.city}"
DIR="${DEPLOY_DIR:-/home/manx/ratatosk}"
MODE="${1:-deploy}"

bold() { printf '\033[1m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }

# Jedno spojení na všechny příkazy — ať se to neptá na heslo pořád dokola.
SSH=(ssh -o ControlMaster=auto -o ControlPath=/tmp/.ratatosk-ssh-%r@%h:%p -o ControlPersist=60s)
remote() { "${SSH[@]}" "$HOST" "$@"; }

bold "Ratatosk → $HOST:$DIR"
echo

# ---------------------------------------------------------------- lokální

command -v rsync >/dev/null || die "rsync není nainstalovaný (apt install rsync)"
[ -f docker-compose.yml ] || die "spouštěj z kořene projektu (chybí docker-compose.yml)"

# ----------------------------------------------------------------- server

bold "1. Prostředí na serveru"

remote true 2>/dev/null || die "nejde se připojit na $HOST"
ok "SSH spojení"

remote 'command -v docker >/dev/null' || die "na serveru není docker"
remote 'docker ps >/dev/null 2>&1' \
  || die "uživatel nesmí mluvit s dockerem. Jako root: usermod -aG docker \$USER, pak se odhlas a přihlas"
ok "docker běží a uživatel na něj dosáhne ($(remote 'docker --version'))"

remote 'docker compose version >/dev/null 2>&1' \
  || die "chybí docker compose (plugin v2). Jako root: apt install docker-compose-plugin"
ok "docker compose $(remote 'docker compose version --short')"

DB_NAME_LOCAL=$(grep -E '^DB_NAME=' .env.example 2>/dev/null | cut -d= -f2- || echo ratatosk)
if remote 'command -v pg_isready >/dev/null && pg_isready -q'; then
    ok "PostgreSQL na hostu běží"

    # Databázi ani uživatele skript nezakládá — na to je potřeba root.
    # Lepší to zjistit teď než až migrací uprostřed nasazení.
    if remote "psql -lqt 2>/dev/null | cut -d'|' -f1 | grep -qw '$DB_NAME_LOCAL'"; then
        ok "databáze $DB_NAME_LOCAL existuje"
    else
        warn "databáze $DB_NAME_LOCAL neexistuje (nebo na ni tenhle účet nevidí)"
        DB_SETUP_NEEDED=1
    fi

    if remote "ip -4 addr show docker0 >/dev/null 2>&1"; then
        BRIDGE=$(remote "ip -4 addr show docker0 | grep -oP 'inet \K[0-9.]+'" || true)
        ok "docker bridge: ${BRIDGE:-?} (kontejner sem chodí přes host.docker.internal)"
    fi
else
    warn "PostgreSQL na hostu nenalezen nebo neběží"
    warn "  produkční docker-compose.yml počítá s databází NA HOSTU, ne v kontejneru"
    DB_SETUP_NEEDED=1
fi

FREE=$(remote "df -m '$(dirname "$DIR")' | awk 'NR==2{print \$4}'" || echo 0)
if [ "${FREE:-0}" -lt 1024 ]; then
    warn "na disku zbývá jen ${FREE} MB — worker potřebuje místo na zdroj i výstup"
else
    ok "místo na disku: ${FREE} MB"
fi

# ------------------------------------------------------------------ .env

if [ "${DB_SETUP_NEEDED:-0}" = "1" ]; then
    echo
    bold "Databázi je potřeba založit jako root:"
    cat <<'SQL'
  sudo -u postgres psql <<'EOF'
    CREATE USER ratatosk WITH PASSWORD 'zvol-heslo';
    CREATE DATABASE ratatosk OWNER ratatosk;
  EOF

  # a pustit dovnitř docker bridge (172.17.0.0/16):
  echo "host ratatosk ratatosk 172.16.0.0/12 scram-sha-256" \
    | sudo tee -a /etc/postgresql/*/main/pg_hba.conf
  sudo systemctl reload postgresql
SQL
    echo
fi

bold "2. Konfigurace"

if remote "[ -f '$DIR/.env' ]"; then
    ok ".env na serveru existuje (přenos ho nepřepíše)"
    APP_URL=$(remote "grep -E '^APP_URL=' '$DIR/.env' | cut -d= -f2-" || true)
    case "$APP_URL" in
        ''|*localhost*) warn "APP_URL je '$APP_URL' — do sdílených odkazů patří veřejná adresa" ;;
        *)              ok "APP_URL: $APP_URL" ;;
    esac
    ENV_MISSING=0
else
    warn ".env na serveru zatím není"
    ENV_MISSING=1
fi

if [ "$MODE" = "--check" ]; then
    echo
    bold "Kontrola hotová, nic se neměnilo."
    exit 0
fi

# --------------------------------------------------------------- přenos

bold "3. Přenos kódu"

remote "mkdir -p '$DIR'"
rsync -az --delete --human-readable \
    --exclude '.git/' \
    --exclude '.env' \
    --exclude '*.log' \
    --exclude 'compose.dev.yml' \
    -e "${SSH[*]}" \
    ./ "$HOST:$DIR/"
ok "kód přenesen (.env na serveru zůstal nedotčený)"

if [ "$ENV_MISSING" = "1" ]; then
    remote "cp '$DIR/.env.example' '$DIR/.env' && chmod 600 '$DIR/.env'"
    echo
    bold "Zastavuji: vyplň konfiguraci a spusť deploy znovu."
    echo "  ssh $HOST"
    echo "  \$EDITOR $DIR/.env      # DB, R2 klíče, APP_URL, INVITE_CODE"
    exit 1
fi

remote "chmod 600 '$DIR/.env'"

# -------------------------------------------------------------- kontejner

bold "4. Kontejner"

if [ "$MODE" = "--no-build" ]; then
    remote "cd '$DIR' && docker compose up -d"
else
    remote "cd '$DIR' && docker compose up -d --build"
fi
ok "kontejner běží"

bold "5. Databáze"
if remote "cd '$DIR' && docker compose exec -T app php bin/migrate.php"; then
    ok "schéma je aktuální"
else
    die "migrace selhala — zkontroluj DB_* v .env a to, že Postgres pouští dovnitř docker bridge"
fi

# ------------------------------------------------------------------ cron

bold "6. Kontrola běhu"

# Nutně jako www-data: root přečte i .env s právy 600, takže by kontrola
# pod rootem přehlédla přesně tu chybu, kvůli které appka vracela 500.
if remote "cd '$DIR' && docker compose exec -u www-data -T app php -r 'require \"/var/www/html/src/bootstrap.php\"; db()->query(\"SELECT 1\"); echo \"ok\";'" >/dev/null 2>&1; then
    ok "appka nabootuje pod www-data a vidí na databázi"
else
    echo
    remote "cd '$DIR' && docker compose exec -u www-data -T app php -r 'require \"/var/www/html/src/bootstrap.php\"; db()->query(\"SELECT 1\");'" 2>&1 | tail -5
    die "appka pod www-data nenabootuje — nginx by dostával 500"
fi

bold "7. Cron na překódování"

CRON="* * * * * cd $DIR && /usr/bin/docker compose exec -T app php bin/worker.php >> $DIR/worker.log 2>&1"
if remote "crontab -l 2>/dev/null | grep -Fq 'ratatosk'" ; then
    ok "cron už je nastavený"
else
    remote "( crontab -l 2>/dev/null; echo '# ratatosk worker'; echo '$CRON' ) | crontab -"
    ok "cron přidán (každou minutu, flock drží jeden ffmpeg naráz)"
fi

# ---------------------------------------------------------------- shrnutí

echo
bold "Hotovo."
remote "cd '$DIR' && docker compose ps --format 'table {{.Name}}\t{{.Status}}\t{{.Ports}}'"
echo
bold "Zbývá udělat ručně (jako root):"
echo "  1. nginx  — vzor v $DIR/nginx.example.conf, uprav server_name a \$app_root=$DIR"
echo "  2. TLS    — certbot --nginx -d <doména>"
echo "  3. CORS   — do R2 bucketu přidej origin z APP_URL, jinak upload z prohlížeče spadne"
