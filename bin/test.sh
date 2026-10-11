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
find public extensions bin tests config libraries -name '*.php' -exec php -l '{}' \;
for file in ./*.php; do php -l "$file"; done
