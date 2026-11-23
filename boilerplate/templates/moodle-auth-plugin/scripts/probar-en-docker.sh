#!/usr/bin/env bash
# Levanta Moodle + PostgreSQL con moodle-docker, instala este plugin, corre sus pruebas PHPUnit
# y deja el sitio andando en http://localhost:8001 (usuario admin, contraseña test) para probarlo a mano.
#
# Uso:   scripts/probar-en-docker.sh                                  # Moodle 4.5
#        MOODLE_BRANCH=MOODLE_501_STABLE scripts/probar-en-docker.sh  # Moodle 5.1
#        scripts/probar-en-docker.sh down                             # apaga y borra los contenedores
#
# Hace falta Docker. Moodle y moodle-docker se descargan una sola vez por rama en $MOODLE_WORKDIR.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../sovra" && pwd)"
BRANCH="${MOODLE_BRANCH:-MOODLE_405_STABLE}"
WORK="${MOODLE_WORKDIR:-$HOME/.cache/sovra-auth-moodle}/$BRANCH"

export MOODLE_DOCKER_WWWROOT="$WORK/moodle"
export MOODLE_DOCKER_DB=pgsql
export MOODLE_DOCKER_PHP_VERSION="${MOODLE_DOCKER_PHP_VERSION:-8.3}"
export MOODLE_DOCKER_WEB_PORT="${MOODLE_DOCKER_WEB_PORT:-8001}"
export COMPOSE_PROJECT_NAME="sovraauth$(echo "$BRANCH" | tr -dc '0-9')"

mkdir -p "$WORK"
[ -d "$WORK/moodle" ] || git clone --depth 1 -b "$BRANCH" https://github.com/moodle/moodle.git "$WORK/moodle"
[ -d "$WORK/moodle-docker" ] || git clone --depth 1 https://github.com/moodlehq/moodle-docker.git "$WORK/moodle-docker"
cd "$WORK/moodle-docker"
dc() { bin/moodle-docker-compose "$@"; }

if [ "${1:-}" = "down" ]; then
    dc down
    exit 0
fi

# Desde Moodle 5.1 los plugins y admin/tool están en public/; admin/cli sigue en la raíz.
PUB=""
[ -d "$WORK/moodle/public" ] && PUB="public/"

# El plugin, tal como está en el repo.
rsync -a --delete "$PLUGIN_DIR/" "$WORK/moodle/${PUB}auth/sovra/"
[ -f "$WORK/moodle/config.php" ] || cp config.docker-template.php "$WORK/moodle/config.php"

dc up -d
bin/moodle-docker-wait-for-db

# El sitio para probar a mano: se instala la primera vez; después, sólo se actualiza el plugin.
if ! dc exec -T webserver php admin/cli/install_database.php --agree-license --fullname="Moodle Sovra" \
        --shortname="sovra" --summary="Prueba del plugin auth_sovra" --adminpass="test" \
        --adminemail="admin@example.com" > "$WORK/install.log" 2>&1; then
    dc exec -T webserver php admin/cli/upgrade.php --non-interactive
fi

echo "== Preparando PHPUnit (la primera vez tarda varios minutos) =="
dc exec -T webserver php "${PUB}admin/tool/phpunit/cli/init.php" > "$WORK/phpunit-init.log" 2>&1 \
    || { tail -40 "$WORK/phpunit-init.log"; exit 1; }

echo "== Pruebas de auth_sovra =="
status=0
dc exec -T webserver vendor/bin/phpunit --testsuite auth_sovra_testsuite --testdox || status=$?

echo
echo "Sitio: http://localhost:$MOODLE_DOCKER_WEB_PORT  (admin / test)"
echo "Para apagarlo: $0 down"
exit $status
