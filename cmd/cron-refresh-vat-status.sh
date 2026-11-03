#!/usr/bin/env bash
# =============================================================================
#  cron-refresh-vat-status.sh — daily recompute of the cached VAT status
#  Frequency: once a day shortly after midnight, recommended 00:05
#
#  Recomputes supplier.is_vat_payer / is_identified ("status today") from the
#  VAT status history (supplier_vat_status_history). A history row dated in the
#  future (e.g. VAT payer from 2027-01-01) therefore flips the cache by itself.
#
#  crontab:
#    5 0 * * *  /var/www/myinvoice.cz/cmd/cron-refresh-vat-status.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-refresh-vat-status.php" "$@" \
    >> "$LOG_DIR/refresh-vat-status-$(date +%Y-%m-%d).log" 2>&1
