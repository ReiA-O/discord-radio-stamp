# Maintainer / AI handoff

Read README.md, docs/STATUS.md, docs/ARCHITECTURE.md, CONTRIBUTING.md and SECURITY.md before work.

## Boundaries
- This repository is the canonical sanitized application source, not a production checkout.
- Never copy production configuration, real identifiers, credentials, databases, participant records or raw logs into code, commits, issues or test fixtures.
- Use example configuration (disabled by default), fake HTTP/Gateway, fixed clocks and isolated SQLite.
- Do not contact Discord or production during ordinary development or tests.
- Do not weaken signature, application, guild, user, voice, schema, lease or outbound-mention guards to make a test pass.
- Never interpret missing best-effort diagnostics as proof of upstream non-delivery. Unknown side effects must not be automatically replayed.

## Change workflow
1. Confirm current base SHA and branch. Preserve others' work; checkpoint is WIP, not stable.
2. Make a focused branch and regression test using sanitized fixtures.
3. Run PHP syntax checks and applicable tests per README/tests documentation. Keep generated artifacts outside checkout.
4. Record exact SHA, commands, PHP/SQLite versions, exit codes and skipped/blocked checks. Do not carry historical counts forward.
5. Prepare a draft PR explaining behavior, safety boundaries and unverified scope. Publishing, merging and production deployment are separate actions.
6. Update docs/STATUS.md when evidence changes. Separate local/CI results from observed live behavior.

## Operational handoff
The private operations repository should reference approved public commit SHAs, not become a second divergent application source. Its identity and maintainer access are not yet confirmed. Do not invent deployment destinations or permissions. Stop and ask the owner when operations require missing authorization.

License and CODEOWNERS identities remain undecided. Do not assign them on behalf of the owner.

