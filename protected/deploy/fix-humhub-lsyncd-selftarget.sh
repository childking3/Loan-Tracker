#!/bin/bash
# Fixes the HumHub file-upload "sometimes works, sometimes doesn't" bug.
# Node .4's lsyncd config targets ITSELF instead of node .3 (copy-paste
# error), so files uploaded directly to .4 never reach .3. Combined with
# HumHub's least_conn (non-sticky) load balancing and local-disk storage,
# a file that landed on .4 is invisible to any request routed to .3.
# Not part of the loan-management app; colocated here (see CODEBASE.md
# 2026-09-28 for the full investigation this fix comes from).
#
# Run from the repo root on the dev machine:
#   bash protected/deploy/fix-humhub-lsyncd-selftarget.sh
# Asks for the root password at each ssh prompt. Only touches node .4.
# Node .3 is not modified - its config is already correct.
set -euo pipefail
ask() { read -r -p "$1 [y/N] " a; [ "$a" = y ]; }

echo "== 1. Confirm the bug is still there before touching anything =="
ssh root@10.10.10.4 'grep -n "target" /etc/lsyncd/lsyncd.conf.lua'
echo
ask "Does the line above show target = \"root@10.10.10.4:/\" (self-target)? If it already says .3, STOP - someone beat us to it." || exit 1

echo "== 2. Back up the current config on .4 =="
ssh root@10.10.10.4 'cp -n /etc/lsyncd/lsyncd.conf.lua /etc/lsyncd/lsyncd.conf.lua.pre-selftarget-fix.bak'

echo "== 3. Compare file lists BEFORE the fix (for a before/after check) =="
ssh root@10.10.10.3 "find /var/www/humhub/uploads -type f -printf '%P\n' | sort" > /tmp/humhub-uploads-node3-before.txt
ssh root@10.10.10.4 "find /var/www/humhub/uploads -type f -printf '%P\n' | sort" > /tmp/humhub-uploads-node4-before.txt
echo "Files only on .4 right now (should shrink to 0 after the fix + one sync cycle):"
comm -13 /tmp/humhub-uploads-node3-before.txt /tmp/humhub-uploads-node4-before.txt

echo "== 4. Apply the one-line fix on node .4 =="
ask "Change node .4's lsyncd target from itself to node .3 (10.10.10.4 -> 10.10.10.3)?" || exit 1
ssh root@10.10.10.4 "sed -i 's|target    = \"root@10.10.10.4:/\"|target    = \"root@10.10.10.3:/\"|' /etc/lsyncd/lsyncd.conf.lua
  grep -n target /etc/lsyncd/lsyncd.conf.lua"

echo "== 5. Restart lsyncd on .4 only (node .3 is untouched) =="
ask "Restart lsyncd on node .4?" || exit 1
ssh root@10.10.10.4 "systemctl restart lsyncd; systemctl is-active lsyncd"

echo "== 6. Verify: the live rsync process should now target .3 =="
sleep 2
ssh root@10.10.10.4 "ps -eo args | grep -E 'rsync.*10\.10\.10\.3' | grep -v grep || echo '(no rsync mid-flight right now - that is fine, it runs every few seconds; re-check in a moment if you want certainty)'"

echo "== 7. Verify: after a few sync cycles, files that were only on .4 should now be on .3 too =="
echo "Run this again in ~30 seconds to give lsyncd's initial full sync time to finish:"
echo "  diff <(ssh root@10.10.10.3 \"find /var/www/humhub/uploads -type f -printf '%P\\n' | sort\") \\"
echo "       <(ssh root@10.10.10.4 \"find /var/www/humhub/uploads -type f -printf '%P\\n' | sort\")"
echo "Expect no output (both nodes' file lists identical) once the initial sync completes."
echo
echo "Done. Rollback if needed: restore /etc/lsyncd/lsyncd.conf.lua from the .pre-selftarget-fix.bak"
echo "next to it on node .4, then 'systemctl restart lsyncd' there again."
