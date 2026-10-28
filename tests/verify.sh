#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v php >/dev/null || { echo 'PHP is required for lint and unit verification.' >&2; exit 1; }
while IFS= read -r -d '' file; do php -l "$file"; done < <(find . -path ./vendor -prune -o -name '*.php' -print0)
composer install --no-interaction
vendor/bin/phpunit
if [[ "${WARPSEARCH_TEST_SITE:-}" == 1 ]]; then
  : "${WARPSEARCH_API_KEY:?Supply a test key via the environment, never a command argument}"
  : "${WARPSEARCH_TEST_COLLECTION:?Use a new test_wc_ collection name}"
  wp eval-file tests/live.php
fi
