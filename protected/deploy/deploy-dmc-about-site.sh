#!/bin/bash
# Moves dmcsparklesservices.com's static "About" page off proxy .1's local
# disk (/var/www/html) onto the app nodes, proxied the same way the loan
# tracker is (nginx upstream -> Apache on .3 primary / .4 backup). Not
# part of the loan-management app; colocated here because this is the
# established place for cross-cutting ops scripts on this project.
#
# Run from the repo root on the dev machine:
#   bash protected/deploy/deploy-dmc-about-site.sh
# Asks for the root password at each ssh/rsync/scp prompt. Every change
# step asks first. No TLS certs touched - Cloudflare terminates HTTPS and
# proxies to these origins over plain HTTP, same as today.
set -euo pipefail
cd "$(dirname "$0")/../.."
D=protected/deploy/dmc-about-site
ask() { read -r -p "$1 [y/N] " a; [ "$a" = y ]; }

echo "== 1. Read-only look: current state on both proxies and both app nodes =="
for h in root@10.10.10.1 root@10.10.10.5; do
  ssh "$h" 'echo "-- $(hostname)"; nginx -t 2>&1 | tail -2; ls /etc/nginx/sites-enabled/'
done
for h in root@10.10.10.3 root@10.10.10.4; do
  ssh "$h" 'echo "-- $(hostname)"; ls /etc/apache2/sites-enabled/; ls -d /var/www/dmc-about-site 2>/dev/null || echo "(no dmc-about-site dir yet)"'
done
echo
ask "Do both proxies show 'nginx -t' OK and no unexpected sites-enabled entries?" || exit 1

echo "== 2. Push the site + Apache vhost to .3 and .4 (Apache not reloaded yet - no traffic effect) =="
ask "Copy files to both app nodes now?" || exit 1
for pair in "3 root@10.10.10.3" "4 root@10.10.10.4"; do
  set -- $pair; n=$1; h=$2
  ssh "$h" "install -d -o root -g root -m 755 /var/www/dmc-about-site"
  scp "$D/index.html" "$h:/var/www/dmc-about-site/index.html"
  scp "$D/sparkle-about.node$n.conf" "$h:/etc/apache2/sites-available/sparkle-about.conf"
  ssh "$h" "chown root:root /var/www/dmc-about-site/index.html && chmod 644 /var/www/dmc-about-site/index.html
    a2ensite sparkle-about.conf
    apache2ctl configtest"
done
echo "Filename (sparkle-about.conf) sorts after humhub.conf and loan-management.conf on purpose - do not rename to something earlier alphabetically (see the .conf file's own comment: it would silently become the new default vhost for unmatched Host headers)."

echo "== 3. Reload Apache on both nodes (adds the vhost; still no traffic reaches it - nginx hasn't been told about it) =="
ask "Reload Apache on .3 and .4?" || exit 1
ssh root@10.10.10.3 "systemctl reload apache2"
ssh root@10.10.10.4 "systemctl reload apache2"

echo "== 4. Direct check against Apache on each node, bypassing nginx entirely =="
for h in root@10.10.10.3 root@10.10.10.4; do
  ssh "$h" 'echo -n "$(hostname): "; curl -s -o /dev/null -w "%{http_code}\n" -H "Host: dmcsparklesservices.com" http://127.0.0.1/
    echo -n "  no directory listing: "; curl -s -H "Host: dmcsparklesservices.com" http://127.0.0.1/nonexistent-path-xyz/ -o /dev/null -w "%{http_code}\n"'
done
echo
ask "Both nodes returned 200 for / above (and something other than a directory listing)?" || exit 1

echo "== 5. Cut nginx over: back up the current file, then swap content-serving for a reverse proxy =="
ask "Patch /etc/nginx/sites-available/about on proxy .1 now?" || exit 1
ssh root@10.10.10.1 "cp -n /etc/nginx/sites-available/about /etc/nginx/sites-available/about.pre-app-node-cutover.bak"
scp "$D/about.new" root@10.10.10.1:/etc/nginx/sites-available/about
ssh root@10.10.10.1 "nginx -t"

echo "-- checking whether the two-way lsyncd mirror already propagated this to .5 --"
sleep 3
if diff -q <(ssh root@10.10.10.1 "cat /etc/nginx/sites-available/about") \
           <(ssh root@10.10.10.5 "cat /etc/nginx/sites-available/about") >/dev/null 2>&1; then
  echo "proxy .5 already matches (lsyncd propagated it)."
else
  echo "proxy .5 does NOT match yet - copying explicitly."
  ssh root@10.10.10.5 "cp -n /etc/nginx/sites-available/about /etc/nginx/sites-available/about.pre-app-node-cutover.bak"
  scp "$D/about.new" root@10.10.10.5:/etc/nginx/sites-available/about
  ssh root@10.10.10.5 "nginx -t"
fi

echo "== 6. Reload nginx on both proxies (this is the actual cutover) =="
ask "Reload nginx on .1 and .5 now? This makes the app-node version live for real visitors." || exit 1
ssh root@10.10.10.1 "systemctl reload nginx"
ssh root@10.10.10.5 "systemctl reload nginx"

echo "== 7. Verify through nginx (Host header direct to each proxy, bypassing DNS/Cloudflare) =="
for h in root@10.10.10.1 root@10.10.10.5; do
  ssh "$h" 'echo -n "$(hostname): "; curl -s -o /dev/null -w "%{http_code}\n" -H "Host: dmcsparklesservices.com" http://127.0.0.1/'
done
echo "Compare a full fetch against the original if you want to eyeball it:"
echo "  curl -s -H 'Host: dmcsparklesservices.com' http://10.10.10.1/ | md5sum"
echo "  md5sum $D/index.html"
echo
echo "Done. The old copy is untouched at /var/www/html/index.nginx-debian.html on both proxies (instant rollback:"
echo "  restore /etc/nginx/sites-available/about from the .pre-app-node-cutover.bak next to it, then reload nginx)."
