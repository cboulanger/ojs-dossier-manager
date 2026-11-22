#!/bin/bash
# Runs once at image build time: creates the database, installs OJS and the test journal.
set -euo pipefail

cd /var/www/html

service mariadb start
until mysqladmin ping --silent; do sleep 1; done

mysql <<SQL
CREATE DATABASE ${OJS_DB_NAME} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${OJS_DB_USER}'@'localhost' IDENTIFIED BY '${OJS_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${OJS_DB_NAME}.* TO '${OJS_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

runuser -u www-data -- php tools/devSetup.php install
if ! grep -q '^installed = On' config.inc.php; then
	echo "OJS installation failed" >&2
	exit 1
fi

# Development settings: log mails instead of sending them, show stack traces,
# fixed secret for API keys (api/SKILL.md)
sed -i \
	-e 's/^default = sendmail/default = log/' \
	-e 's/^show_stacktrace = Off/show_stacktrace = On/' \
	-e 's/^api_key_secret = ""/api_key_secret = "ojs-dev-api-key-secret"/' \
	config.inc.php

runuser -u www-data -- php tools/devSetup.php journal

service mariadb stop
