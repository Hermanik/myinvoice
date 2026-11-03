#!/usr/bin/env bash
# =============================================================================
#  cron-databox-sync.sh — hourly synchronisation of data box message envelopes
#  Frequency: once an hour, recommended minute 17
#
#  Lists received + sent messages of the connected data boxes (ISDS) and raises
#  dashboard items for new messages. Runs only with a login that cannot read
#  messages, so it never delivers them (see manual chapter "Datové schránky").
#
#  crontab:
#    17 * * * *  /var/www/myinvoice.cz/cmd/cron-databox-sync.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-databox-sync.php" "$@" \
    >> "$LOG_DIR/databox-sync-$(date +%Y-%m-%d).log" 2>&1
