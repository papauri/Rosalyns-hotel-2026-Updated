#!/usr/bin/env bash
#
# setup-cron.sh — install this installation's scheduled jobs.
#
# Previously this script installed ONE job (scheduled-cache-clear), which left
# database backups, the guest-email lifecycle, gym reminders and tentative-booking
# expiry with no scheduling path at all. Every cadence below is the one each
# script documents in its own header docblock.
#
#   ./scripts/setup-cron.sh              # dry run — print the crontab block
#   ./scripts/setup-cron.sh --install    # write it to the crontab (deduplicated)
#
# Optional environment:
#   PHP_BIN             path to the php binary (default: first php on PATH)
#   LOG_DIR             where job logs are written (default: <project>/logs)
#   REPORT_RECIPIENTS   comma-separated addresses for the daily operations report.
#                       Without it, the daily_reports job is omitted — the script
#                       exits 2 when it has no recipients, so scheduling it blind
#                       would just email cron failures every morning.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
LOG_DIR="${LOG_DIR:-${PROJECT_ROOT}/logs}"

if [[ -z "${PHP_BIN}" ]]; then
  echo "Error: php binary not found in PATH." >&2
  echo "Set PHP_BIN manually, e.g.: PHP_BIN=/usr/local/bin/php ./scripts/setup-cron.sh" >&2
  exit 1
fi

if [[ ! -x "${PHP_BIN}" ]]; then
  echo "Error: PHP_BIN is not executable: ${PHP_BIN}" >&2
  exit 1
fi

mkdir -p "${LOG_DIR}"

# --- Job definitions -----------------------------------------------------------
# Format: "<cron schedule>|<script + flags>|<log file>|<description>"
JOBS=(
  "0 2 * * *|scripts/backup_database.php --quiet|backup.log|Database backup (retention: 14 daily / 8 weekly / 12 monthly)"
  "*/15 * * * *|scripts/auto-scheduler-run.php --quiet|auto-scheduler.log|Automated emails + backups (optional: they also run on page loads)"
  "* * * * *|scripts/scheduled-cache-clear.php --quiet|cache-clear.log|Cache clear (honours the schedule set in admin/cache-management.php)"
)

# daily_reports.php needs explicit recipients or it exits 2.
if [[ -n "${REPORT_RECIPIENTS:-}" ]]; then
  JOBS+=("30 7 * * *|scripts/daily_reports.php --recipients=${REPORT_RECIPIENTS} --quiet|daily-reports.log|Daily operations report email")
fi

MARKER="# --- rosalyns-hotel scheduled jobs (managed by scripts/setup-cron.sh) ---"

build_block() {
  echo "${MARKER}"
  for job in "${JOBS[@]}"; do
    IFS='|' read -r schedule script logfile desc <<< "${job}"
    echo "# ${desc}"
    echo "${schedule} ${PHP_BIN} ${PROJECT_ROOT}/${script} >> ${LOG_DIR}/${logfile} 2>&1"
  done
}

BLOCK="$(build_block)"

echo "Project : ${PROJECT_ROOT}"
echo "PHP     : ${PHP_BIN}"
echo "Logs    : ${LOG_DIR}"
if [[ -z "${REPORT_RECIPIENTS:-}" ]]; then
  echo
  echo "Note: REPORT_RECIPIENTS not set — the daily operations report is NOT scheduled."
  echo "      Re-run as: REPORT_RECIPIENTS=ops@example.com ./scripts/setup-cron.sh --install"
fi
echo
echo "${BLOCK}"
echo

if [[ "${1:-}" == "--install" ]]; then
  # Drop any previously installed block, then append the current one.
  existing="$(crontab -l 2>/dev/null || true)"
  cleaned="$(printf '%s\n' "${existing}" | grep -vF "${PROJECT_ROOT}/scripts/" | grep -vF "${MARKER}" || true)"
  printf '%s\n%s\n' "${cleaned}" "${BLOCK}" | sed '/^$/N;/^\n$/D' | crontab -
  echo "Installed. Current crontab:"
  crontab -l
else
  echo "Dry run only. Re-run with --install to write to the crontab."
  echo "On cPanel you can instead paste the block above into Cron Jobs."
fi
