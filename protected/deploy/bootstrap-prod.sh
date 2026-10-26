#!/bin/bash
# Run ONCE as root on app node 10.10.10.3 (Galera replicates the DB to .4;
# lsyncd mirrors the .env). Creates only NEW things for the loan app:
#   - database `loantracker`, user `loantracker` (SELECT/INSERT/UPDATE/DELETE)
#   - a temporary migration user, dropped again at the end
#   - protected/.env (root:loanapp 640) with generated DB password + cookie key
#   - runs the migrations, SKIPPING the dummy-data seed (which would create
#     manager1/staff1/staff2 with a known password)
#   - sets a random password for the seeded `admin` account and prints it once
# It touches no existing user, password, database or service. It refuses to
# run if the DB or .env already exist. If it fails part-way:
#   mysql -e 'DROP DATABASE loantracker; DROP USER IF EXISTS "loan_migrate"@"127.0.0.1"; DROP USER IF EXISTS "loantracker"@"127.0.0.1"; DROP USER IF EXISTS "loantracker"@"localhost"'; rm /var/www/loan-management/protected/.env
# then run it again.
set -euo pipefail
umask 077

APP=/var/www/loan-management
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 1; }
[ "$(ip -4 -o addr show | grep -c ' 10\.10\.10\.3/')" = 1 ] || { echo "run on 10.10.10.3"; exit 1; }
[ ! -e "$APP/protected/.env" ] || { echo ".env already exists, refusing"; exit 1; }
[ -z "$(mysql -N -e 'show databases like "loantracker"' 2>/dev/null)" ] || { echo "database loantracker already exists, refusing"; exit 1; }
systemctl is-active --quiet keydb-loan loan-php-fpm || { echo "keydb-loan / loan-php-fpm not running"; exit 1; }

DBPW=$(openssl rand -hex 24)
MPW=$(openssl rand -hex 24)
CK=$(openssl rand -hex 32)
ADMINPW=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-18)

mysql 2> >(grep -v "Deprecated program" >&2) <<SQL
CREATE DATABASE loantracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'loantracker'@'127.0.0.1' IDENTIFIED BY '$DBPW';
CREATE USER 'loantracker'@'localhost' IDENTIFIED BY '$DBPW';
GRANT SELECT,INSERT,UPDATE,DELETE ON loantracker.* TO 'loantracker'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON loantracker.* TO 'loantracker'@'localhost';
CREATE USER 'loan_migrate'@'127.0.0.1' IDENTIFIED BY '$MPW';
GRANT ALL ON loantracker.* TO 'loan_migrate'@'127.0.0.1';
SQL

cat > "$APP/protected/.env" <<ENV
APP_ENV=prod
APP_DEBUG=false
APP_HOST_INFO=https://dmcmanagement.duckdns.org
TRUSTED_PROXY_CIDRS=10.10.10.0/24,10.10.2.0/24
RUNTIME_PATH=/run/loan-app
DB_DSN=mysql:host=127.0.0.1;dbname=loantracker
DB_USER=loantracker
DB_PASSWORD=$DBPW
REDIS_HOST=127.0.0.1
REDIS_PORT=6381
REDIS_DATABASE=0
COOKIE_VALIDATION_KEY=$CK
ENV
chown root:loanapp "$APP/protected/.env"
chmod 640 "$APP/protected/.env"

yii() {
    runuser -u loanapp -- env RUNTIME_PATH=/run/loan-app/cli DB_USER=loan_migrate DB_PASSWORD="$MPW" \
        php "$APP/protected/yii" "$@"
}

# First 10 migrations (schema, packages, admin seed, RBAC), then mark the
# 11th (dummy data) as applied without running it, then everything after.
# Yii's own RBAC schema (auth_* tables) must exist before init_rbac runs.
yii migrate/up --migrationPath=@yii/rbac/migrations --interactive=0
yii migrate/up 10 --interactive=0
mysql loantracker -e "INSERT INTO migration (version, apply_time) VALUES ('m260910_220000_seed_dummy_data', UNIX_TIMESTAMP())" 2> >(grep -v "Deprecated program" >&2)
yii migrate/up --interactive=0

HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 13]);' "$ADMINPW")
mysql loantracker -e "UPDATE user SET password_hash='$HASH' WHERE username='admin'" 2> >(grep -v "Deprecated program" >&2)

mysql -e "DROP USER 'loan_migrate'@'127.0.0.1'" 2> >(grep -v "Deprecated program" >&2)

echo
echo "users in loantracker:"; mysql loantracker -e "select id,username,email from user" 2> >(grep -v "Deprecated program" >&2)
echo
echo "================================================================"
echo " admin login (shown once, not stored anywhere):"
echo "   username: admin"
echo "   password: $ADMINPW"
echo "================================================================"
