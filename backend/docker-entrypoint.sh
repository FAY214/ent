#!/bin/sh
set -eu
php /srv/backend/scripts/prepare-production.php
exec docker-php-entrypoint "$@"
