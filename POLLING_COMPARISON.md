# Dashboard polling: this app's approach vs. HumHub's

Both this app's dashboard (`DashboardController::actionPoll()`,
`app\components\DashboardCache`) and HumHub's live-update feature
(`humhub/modules/live`) use the same basic technique — the browser polls a
JSON endpoint every few seconds, sending the last value it saw, and the
server replies with what's new. The two differ in what that "last value"
actually is, because the two systems are solving different problems.

## What each one is answering

**This app:** "Has anything changed since the totals I already have?" —
one yes/no question, always about the exact same four numbers plus one
table (active loans, overdue loans, outstanding balance, today's
collections, staff performance).

**HumHub:** "What events have happened since I last checked, that I'm
allowed to see?" — a filtered list of arbitrary, independent events (new
posts, comments, likes, messages) across an entire social network, scoped
per-user by space membership and visibility rules.

## How each one tracks "last value"

| | This app | HumHub |
|---|---|---|
| `last` parameter is | An incrementing integer counter (`dashboard_version` in KeyDB) | A Unix timestamp (`time()`) |
| Bumped/advanced by | Any write that changes the totals (`DashboardCache::bumpVersion()`, called from `Loan::afterSave()`, `RepaymentController::actionCreate()`, the overdue-marking console command) | Simply the passage of time — every request captures `time()` |
| Server compares by | Exact equality (`$currentVersion === $lastSeenVersion`) | Range query (`created_at >= $lastQueryTime`) against an append-only `live` events table |
| Response on "nothing changed" | `{"changed": false}`, no payload | An empty `events` array, but still queries the table to find that out |
| Response on "something changed" | The one fresh totals payload, no history | Every individual event since `last`, each carrying its own data |

## Why the counter is the right fit here

- **The dashboard has no concept of "which thing changed," only "did the
  numbers change."** A version counter answers that in one integer
  comparison, no query needed on the "nothing changed" path — the poll
  endpoint doesn't even touch the database unless the version moved
  (`DashboardCache::getVersion()` is a single KeyDB read).
- **No event log table to maintain.** HumHub's approach needs a real table
  (`live`) with its own cleanup job (`live/jobs/DatabaseCleanup.php`,
  `maxLiveEventAge`) to stop it growing forever. A single integer in cache
  has nothing to clean up.
- **No clock-drift or stale-timestamp handling needed.** HumHub's
  `PollController::getLastQueryTime()` has to defend against a client
  presenting a suspiciously old `last` value (`maxTimeDecay`) and against
  `time()` disagreeing between servers in a multi-server deployment. A
  counter has no wall-clock component at all, so neither concern applies —
  useful if this app is ever deployed behind more than one app server,
  since only the shared KeyDB instance needs to agree on the count.
- **The whole app's write volume is low** (loan creates, repayments,
  a nightly overdue sweep) — a handful of writes a day, not hundreds of
  posts/comments/reactions a minute. There is no scale pressure pushing
  toward the more complex approach.

## Why HumHub's timestamp-based event log is the right fit *there*

- It genuinely needs to return **a list of distinct things**, each with its
  own payload (who posted what, in which space) — a single "did anything
  change" flag can't express that.
- Events need **per-user filtering by visibility/space membership**
  (`buildLookupQuery()`'s public/private/owner/global branches) — that
  only makes sense against real rows you can query and join, not a bare
  counter.
- At social-network scale, many independent things change per second
  across many different users — a shared global counter would mean every
  client re-fetches on every single unrelated change happening anywhere
  in the system, which doesn't cost HumHub anything extra since it's
  already querying the event table per-user regardless.
- HumHub's poll driver also adapts its interval (15-45s, backed off further
  while the user is idle — see `Poll.php`'s `minPollInterval`/
  `maxPollInterval`/`idleFactor`) rather than polling at a fixed rate,
  which matters more at their traffic scale than it does for a handful of
  staff with one dashboard tab open each.

## Bottom line

Neither approach is a mistake or a shortcut — they're sized to genuinely
different problems. Swapping this dashboard to a HumHub-style timestamped
event log would add a table, a cleanup job, and clock-drift handling to
solve a "what specifically changed" question this dashboard never actually
asks. If a future feature *does* need "list everything that changed, and
what it was" (an activity feed, for example — which this app already has
the raw material for in `activity_log`), that would be the point to
revisit an event-log-shaped design for that specific feature, not to
replace the dashboard's simpler counter.
