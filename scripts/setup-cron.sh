#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

if [[ -z "${PHP_BIN}" ]]; then
  echo "Error: php binary not found in PATH."
  echo "Set PHP_BIN manually, e.g.: PHP_BIN=/usr/bin/php ./scripts/setup-cron.sh"
  exit 1
fi

CRON_LINE="* * * * * ${PHP_BIN} ${PROJECT_ROOT}/scripts/scheduled-cache-clear.php --quiet >> /dev/null 2>&1"

echo "Recommended cron entry:"
echo "${CRON_LINE}"

echo
if [[ "${1:-}" == "--install" ]]; then
  (crontab -l 2>/dev/null; echo "${CRON_LINE}") | awk '!seen[$0]++' | crontab -
  echo "Cron entry installed (deduplicated)."
else
  echo "Dry run only. Re-run with --install to write to crontab."
fi
