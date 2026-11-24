#!/usr/bin/env bash
# Levanta Moodle 4.5 + PostgreSQL con moodle-docker, instala este plugin, corre sus pruebas PHPUnit
# y deja el sitio andando en http://localhost:8000 (usuario admin, contraseña test) para probarlo a mano.
#
# Uso:   scripts/probar-en-docker.sh          # levanta, instala o actualiza, y corre las pruebas
#        scripts/probar-en-docker.sh down     # apaga y borra los contenedores
#
# Hace falta Docker. Moodle y moodle-docker se descargan una sola vez en $MOODLE_WORKDIR.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../sovracred" && pwd)"
WORK="${MOODLE_WORKDIR:-$HOME/.cache/sovracred-moodle}"
BRANCH="${MOODLE_BRANCH:-MOODLE_405_STABLE}"

export MOODLE_DOCKER_WWWROOT="$WORK/moodle"
export MOODLE_DOCKER_DB=pgsql
export MOODLE_DOCKER_PHP_VERSION="${MOODLE_DOCKER_PHP_VERSION:-8.3}"
export COMPOSE_PROJECT_NAME=sovracred

mkdir -p "$WORK"
[ -d "$WORK/moodle" ] || git clone --depth 1 -b "$BRANCH" https://github.com/moodle/moodle.git "$WORK/moodle"
[ -d "$WORK/moodle-docker" ] || git clone --depth 1 https://github.com/moodlehq/moodle-docker.git "$WORK/moodle-docker"
cd "$WORK/moodle-docker"
dc() { bin/moodle-docker-compose "$@"; }

if [ "${1:-}" = "down" ]; then
    dc down
    exit 0
fi

# El plugin, tal como está en el repo.
rsync -a --delete "$PLUGIN_DIR/" "$WORK/moodle/local/sovracred/"
[ -f "$WORK/moodle/config.php" ] || cp config.docker-template.php "$WORK/moodle/config.php"

dc up -d
bin/moodle-docker-wait-for-db

# El sitio para probar a mano: se instala la primera vez; después, sólo se actualiza el plugin.
if ! dc exec -T webserver php admin/cli/install_database.php --agree-license --fullname="Moodle Sovra" \
        --shortname="sovra" --summary="Prueba del plugin local_sovracred" --adminpass="test" \
        --adminemail="admin@example.com" > "$WORK/install.log" 2>&1; then
    dc exec -T webserver php admin/cli/upgrade.php --non-interactive
fi

echo "== Preparando PHPUnit (la primera vez tarda varios minutos) =="
dc exec -T webserver php admin/tool/phpunit/cli/init.php > "$WORK/phpunit-init.log" 2>&1 \
    || { tail -40 "$WORK/phpunit-init.log"; exit 1; }

echo "== Pruebas de local_sovracred =="
status=0
dc exec -T webserver vendor/bin/phpunit --testsuite local_sovracred_testsuite --testdox || status=$?

echo
echo "Sitio: http://localhost:8000  (admin / test)"
echo "Para apagarlo: $0 down"
exit $status
