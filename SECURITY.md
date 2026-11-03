# Security and sensitive data

Do not post tokens, encryption/private keys, real configuration or identifiers, participant records, SQLite/WAL/backups, raw logs, attachment URLs or private screenshots in this repository or its public issues/PRs.

Use fictitious reproductions and bounded redacted error categories. Secret values belong in an owner-approved secret store outside both public and private Git repositories. A private repository is not a secret vault.

A private vulnerability contact or GitHub private-reporting route has not been confirmed. For a suspected exposure, do not open a public report containing details or values; contact the repository owner through an already verified private channel. Do not invent a contact address or assume private reporting is enabled.

If a credential may have been exposed:
- Stop redistributing it; do not quote it in reports.
- Tell the owner which credential category/location may be affected, not its value.
- Obtain authorization for revocation/rotation and affected service changes.
- Review reachable history and downstream copies. Deleting the current file alone does not remove historical exposure.
- Preserve only minimal sanitized incident evidence; do not publish exploit-enabling operational details.

No automated security reporting, history cleanup, rotation or deployment is enabled by this document.

