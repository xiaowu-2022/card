#!/usr/bin/env bash
# Restore ONLY into newly created, disposable archive verification databases.
set -euo pipefail
umask 077
cd "$(dirname "$0")/../.."
[[ $# == 1 && $1 == /* ]] || { echo 'Usage: bash scripts/deploy/verify-backup-local.sh /absolute/backup-directory' >&2; exit 2; }
backup_dir=$1
[[ $(head -c 8 "$backup_dir/STATUS") == COMPLETE ]] || { echo 'Incomplete backup.' >&2; exit 2; }
(cd "$backup_dir" && shasum -a 256 -c SHA256SUMS)
created=()
cleanup() {
    for db in "${created[@]:-}"; do
        [[ $db == card_archive_check_* ]] && docker compose exec -T postgres dropdb -U card_platform "$db"
    done
}
trap cleanup EXIT
stamp="$(date +%Y%m%d%H%M%S)_$$"
for source_db in card_mock card_platform; do
    restore_db="card_archive_check_${stamp}_${source_db}"
    docker compose exec -T postgres createdb -U card_platform -T template0 "$restore_db"
    created+=("$restore_db")
    docker compose exec -T postgres pg_restore -U card_platform -d "$restore_db" --no-owner --no-privileges --single-transaction --exit-on-error < "$backup_dir/$source_db.dump"
    docker compose exec -T postgres psql -X -U card_platform -d "$restore_db" -At -v ON_ERROR_STOP=1 \
        -c "SELECT 'migrations',count(*) FROM migrations UNION ALL SELECT 'tenants',count(*) FROM tenants UNION ALL SELECT 'users',count(*) FROM users UNION ALL SELECT 'cards',count(*) FROM user_cards UNION ALL SELECT 'ledger_entries',count(*) FROM ledger_entries UNION ALL SELECT 'ledger_postings',count(*) FROM ledger_postings;" > "$backup_dir/$source_db.restored-counts"
    diff -u "$backup_dir/$source_db.counts" "$backup_dir/$source_db.restored-counts"
    mismatches=$(docker compose exec -T postgres psql -X -U card_platform -d "$restore_db" -At -v ON_ERROR_STOP=1 -c "SELECT count(*) FROM (SELECT a.id FROM ledger_accounts a LEFT JOIN ledger_postings p ON p.ledger_account_id=a.id LEFT JOIN ledger_entries e ON e.id=p.ledger_entry_id AND e.sealed_at IS NOT NULL GROUP BY a.id HAVING a.balance <> COALESCE(sum(CASE WHEN e.id IS NOT NULL THEN p.delta ELSE 0 END),0)) inconsistent;")
    [[ $mismatches == 0 ]] || { echo 'Restored ledger reconciliation failed.' >&2; exit 1; }
    printf '%s: restore succeeded, recorded counts match, ledger balances match sealed postings.\n' "$source_db"
done
printf 'Restore verification passed. No application or workers were attached to archive databases.\n'
