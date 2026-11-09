#!/usr/bin/env bash
# =============================================================================
#  cron-databox-email-notices.sh — watch data boxes through ISDS notification e-mails
#  Frequency: every 30 minutes
#
#  Reads the configured IMAP folder, proves each "new data message" notice genuine
#  (DKIM) and records the announced message; optionally mails an alert. Never logs
#  in to the data box system, so it never delivers a message (see manual chapter
#  "Datové schránky").
#
#  crontab:
#    */30 * * * *  /var/www/myinvoice.cz/cmd/cron-databox-email-notices.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-databox-email-notices.php" "$@" \
    >> "$LOG_DIR/databox-email-notices-$(date +%Y-%m-%d).log" 2>&1
