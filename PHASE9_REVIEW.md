# Phase 9 review — items needing a decision, not a fix

Original review from the Phase 9 pass, updated after the user's
2026-09-11 follow-up decisions and the subsequent PHP-FPM migration (see
`CODEBASE.md`'s "Phase 9 follow-up" section for the full write-up of
everything implemented and how each item was verified live). Resolved
items have been removed from this file rather than marked done in place,
so what's left below is only what's still actually open.

## Open

### Access log scope — deferred to the client, not decided here

Still only covers login/logout/failed-login, not per-customer-view
tracking, despite the schema's own NDPR framing. The user is taking this
specific question to the client directly rather than deciding it
unilaterally. Nothing should be built for this until that answer comes
back - if it does come back as "yes, track per-customer views," that's a
real write-volume increase to plan for (see the original review's
reasoning), not a small addition.

## Resolved — kept here only as a pointer, not duplicated

Everything else from the original review is done, each verified live, not
just "should work" - full detail in `CODEBASE.md`'s "Phase 9 follow-up"
and "PHP-FPM migration" sections: `LoanPackageController` built,
`staffOptions()` restricted to the `staff` role, `uploads/` removed, a
30-minute session idle timeout added, the app now runs on PHP-FPM instead
of mod_php, and password length / CSV file-type checking / bulk-import
audit granularity were all confirmed by the user as fine to leave as-is.

Two things were found and fixed along the way that weren't on the
original list: a real plaintext-credential leak in the `login_failed`
audit-log entries, and a brief raw-source-disclosure window during the
first (failed) PHP-FPM attempt, caused by an unrelated broken apt repo
short-circuiting the install - both in `CODEBASE.md`.
