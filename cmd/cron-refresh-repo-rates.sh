#!/usr/bin/env bash
# =============================================================================
#  cron-refresh-repo-rates.sh — monthly append of new CNB repo rates
#  Frequency: 1st of every month, recommended 07:10
#
#  Appends the CNB two-week repo rates published since the last run to the
#  append-only codebook behind the statutory late-payment interest (existing
#  rows are never rewritten). Without it reminders stop showing interest after
#  the first day of a half-year until an admin fetches the rates by hand.
#
#  crontab:
#    10 7 1 * *  /var/www/myinvoice.cz/cmd/cron-refresh-repo-rates.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-refresh-repo-rates.php" "$@" \
    >> "$LOG_DIR/refresh-repo-rates-$(date +%Y-%m-%d).log" 2>&1
