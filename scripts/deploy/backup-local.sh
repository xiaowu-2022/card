#!/usr/bin/env bash
# Offline backup of the two existing local databases; never restores or mutates them.
set -euo pipefail
umask 077
repo=$(cd "$(dirname "$0")/../.." && pwd)
cd "$repo"
if [[ $# != 1 || $1 != /* ]]; then
    echo 'Usage: bash scripts/deploy/backup-local.sh /absolute/new/backup-directory' >&2
    exit 2
fi
backup_dir=$1
if [[ -e "$backup_dir" ]]; then
    echo 'Destination must not already exist.' >&2
    exit 2
fi
mkdir -p "$backup_dir"
backup_dir=$(cd "$backup_dir" && pwd -P)
case "$backup_dir/" in "$repo/"*) echo 'Backup must be outside the repository.' >&2; exit 2;; esac
running=$(docker compose ps --status running --services)
if printf '%s\n' "$running" | /usr/bin/grep -Eq '^(app|deposit-refunds|card-mock|card-mock-refunds)$'; then
    echo 'Stop application and business workers before taking an offline backup.' >&2
    exit 2
fi
printf 'INCOMPLETE\n' > "$backup_dir/STATUS"
for db in card_mock card_platform; do
    docker compose exec -T postgres pg_dump -U card_platform -d "$db" --format=custom > "$backup_dir/$db.dump"
    docker compose exec -T postgres pg_restore --list < "$backup_dir/$db.dump" > "$backup_dir/$db.toc"
    docker compose exec -T postgres psql -X -U card_platform -d "$db" -At -v ON_ERROR_STOP=1 \
        -c "SELECT 'migrations',count(*) FROM migrations UNION ALL SELECT 'tenants',count(*) FROM tenants UNION ALL SELECT 'users',count(*) FROM users UNION ALL SELECT 'cards',count(*) FROM user_cards UNION ALL SELECT 'ledger_entries',count(*) FROM ledger_entries UNION ALL SELECT 'ledger_postings',count(*) FROM ledger_postings;" > "$backup_dir/$db.counts"
done
# Contains persistent encryption keys and private documents. Never publish this archive.
tar -czf "$backup_dir/private-config-storage.tar.gz" .env .env.example storage compose.yaml compose.card-mock.yaml
# Capture uncommitted/untracked source as well: the current tree is not a release commit.
tar --exclude='./.git' --exclude='./node_modules' --exclude='./vendor' \
    --exclude='./storage' --exclude='./.env' --exclude='./.env.*' \
    --exclude='./public/hot' --exclude='./public/storage' \
    -czf "$backup_dir/source.tar.gz" .
(cd "$backup_dir" && shasum -a 256 ./*.dump ./*.toc ./*.counts ./*.tar.gz > SHA256SUMS)
printf 'COMPLETE: databases + private configuration/storage + source. Current card_mock data is the approved single-site restore source; card_platform is historical backup only.\n' > "$backup_dir/STATUS"
chmod 600 "$backup_dir"/*
printf 'Backup completed: %s\n' "$backup_dir"
