#!/bin/bash
# One-off prod deploy of the 2026-09-26 client batch (customer dates/gender/
# photo, guarantor + loan editing, weekdays-only schedule).
# Run from the repo root on the dev machine:  bash protected/deploy/deploy-2026-09-26.sh
# Asks for the root password (ssh/rsync prompt). Every change step asks first.
#
# Assumes: lsyncd mirrors /var/www to node .4, so code is copied to .3 only
# and the migration runs once, on .3 only. Take a fresh DB backup first
# (db-backups/backup_prod_db.sh) - the recalculation migration is one-way.
set -euo pipefail
N3=root@10.10.10.3
APP=/var/www/loan-management
cd "$(dirname "$0")/../.."

ask() { read -r -p "$1 [y/N] " a; [ "$a" = y ]; }

echo "== 1. Read-only look at prod =="
ssh $N3 "cd $APP && stat -c '%U:%G %n' protected protected/controllers protected/.env protected/uploads;
  ls protected/uploads; systemctl is-active loan-php-fpm keydb-loan;
  grep -E '^(upload_max_filesize|post_max_size)' /etc/php/8.4/loan/php.ini;
  cd protected && runuser -u loanapp -- env RUNTIME_PATH=/run/loan-app/cli php yii migrate/new --interactive=0 | tail -6"
echo
ask "Do those pending migrations look like ONLY the two m260926_* ones?" || exit 1

echo "== 2. Code dry run (nothing copied) =="
EXCL=(--exclude=.git --exclude='/protected/.env*' --exclude='/protected/runtime/' --exclude='/protected/uploads/'
      --exclude='/humhub-1.18.5/' --exclude='*.md' --exclude='/protected/deploy/' --exclude='/app.css'
      --exclude='/.claude/' --exclude='/.gitignore' --exclude='/assets/'
      --exclude='*.jpeg' --exclude='/static/img/' --exclude='/static/js/')
# Only files whose CONTENT differs show up here (expected: ~34 files of the batch).
rsync -rlt -n -i --checksum "${EXCL[@]}" ./ $N3:$APP/ | grep -v '^\.d'
ask "Copy the code for real?" || exit 1
# No --delete: only add/overwrite, never remove. Prod code is root:root, so
# files are created as root with 644/755, like the rest.
rsync -rlt --checksum --no-owner --no-group --chmod=D755,F644 "${EXCL[@]}" ./ $N3:$APP/

echo "== 3. Uploads dir, php limits =="
ssh $N3 "cd $APP && install -d -o loanapp -g loanapp -m 750 protected/uploads/customers"
ask "Raise upload_max_filesize to 10M and post_max_size to 12M in the loan php.ini?" && \
  ssh $N3 "sed -i -E 's/^upload_max_filesize.*/upload_max_filesize = 10M/; s/^post_max_size.*/post_max_size = 12M/' /etc/php/8.4/loan/php.ini && grep -E '^(upload_max_filesize|post_max_size)' /etc/php/8.4/loan/php.ini"

echo "== 4. Migrations (once, node .3 only) =="
ask "Run the two migrations now?" || exit 1
ssh $N3 "cd $APP/protected && runuser -u loanapp -- env RUNTIME_PATH=/run/loan-app/cli php yii migrate/up --interactive=0"

echo "== 5. Reload the LOAN php-fpm pool only (opcache does not revalidate on prod) =="
ask "Reload loan-php-fpm on .3 and .4?" || exit 1
ssh $N3 "systemctl reload loan-php-fpm"
ssh root@10.10.10.4 "systemctl reload loan-php-fpm"
echo "Done. Now log in and check: customers list, add a customer with a photo, edit a guarantor, edit a loan."
