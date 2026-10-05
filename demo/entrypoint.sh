#!/bin/sh
# First boot: copies Joomla into /data/www and installs it into PostgreSQL (DATABASE_URL),
#             with German (CH), French and Italian.
# Every boot: installs/updates this plugin, then `supertext:demo-setup` creates missing demo
#             accounts, languages, home pages and sample articles (never changes existing ones).
set -e

DATA=/data
WWW=$DATA/www

# mod_php needs the prefork MPM. On Railway a second MPM can end up enabled and Apache
# refuses to start ("More than one MPM loaded"), so keep only prefork.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork

# Apache listens on Railway's $PORT (default 80).
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

joomla() { php "$WWW/cli/joomla.php" "$@"; }

# The database server comes from DATABASE_URL; the Joomla database is created if missing.
DB=$(php /opt/demo/createdb.php)
DB_HOST=$(echo "$DB" | cut -d' ' -f1)
DB_PORT=$(echo "$DB" | cut -d' ' -f2)
DB_USER=$(echo "$DB" | cut -d' ' -f3)
DB_NAME=$(echo "$DB" | cut -d' ' -f4)
DB_PASS=$(php -r '$u = parse_url(getenv("DATABASE_URL")); echo rawurldecode($u["pass"] ?? "");')

if [ ! -s "$WWW/configuration.php" ]; then
  echo "First boot: installing Joomla..."
  rm -rf "$WWW" && mkdir -p "$WWW" && cp -a /opt/joomla/. "$WWW/"

  # Joomla's installer insists on a Super User. It gets a random name and password that are
  # never shown; supertext:demo-setup deletes it as soon as the DEMO_ADMIN account exists.
  INSTALLER="installer-$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  INSTALLER_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -d '\n')"
  echo "$INSTALLER" > "$DATA/installer-user"
  php "$WWW/installation/joomla.php" install -n \
    --site-name="${JOOMLA_SITE_NAME:-Supertext Joomla Demo}" \
    --admin-user="Installer" --admin-username="$INSTALLER" --admin-password="$INSTALLER_PASSWORD" \
    --admin-email="$INSTALLER@supertext-demo.invalid" \
    --db-type=pgsql --db-host="$DB_HOST:$DB_PORT" --db-user="$DB_USER" --db-pass="$DB_PASS" \
    --db-name="$DB_NAME" --db-prefix="${JOOMLA_DB_PREFIX:-jos_}" >/dev/null
  unset INSTALLER_PASSWORD

  for zip in /opt/demo/languages/*.zip; do
    joomla extension:install --path="$zip" -n >/dev/null && echo "Installed language $(basename "$zip" .zip)"
  done
  echo "Joomla installed."
fi

# Behind Railway's TLS proxy Joomla picks up https from X-Forwarded-Proto by itself.

# Every boot: install or update this plugin and the demo-only setup command.
joomla extension:install --path=/opt/demo/plg_system_supertext.zip -n >/dev/null && echo "Supertext plugin installed/updated."
joomla extension:install --path=/opt/demo/plg_console_supertextdemo.zip -n >/dev/null
DEMO_PLUGIN_ID=$(joomla extension:list --type=plugin -n --no-ansi | grep "Supertext demo setup" | grep -oE ' [0-9]+ ' | head -1 | tr -d ' ')
[ -n "$DEMO_PLUGIN_ID" ] && joomla extension:enable "$DEMO_PLUGIN_ID" -n >/dev/null || true

# Demo accounts (DEMO_ADMIN_*, DEMO_EDITOR_*), languages, home pages, sample articles.
# Passwords are read from the environment inside the command; they never appear in the log.
joomla supertext:demo-setup -n --remove-user="$(cat "$DATA/installer-user" 2>/dev/null || true)"

joomla cache:clean -n >/dev/null || true
chown -R www-data:www-data "$WWW"
exec "$@"
