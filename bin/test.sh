#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
# Enable pdo_sqlite in php.ini for normal deployments. This flag also works in Void.
if php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'; then
    php tests/core.php
else
    php -d extension=pdo_sqlite tests/core.php
fi
python3 tests/integration.py "$@"
find public src bin tests config database -name '*.php' -exec php -l '{}' \;
php -l vendor/parsedown/Parsedown.php
find vendor/highlight -name '*.php' -exec php -l '{}' \;
