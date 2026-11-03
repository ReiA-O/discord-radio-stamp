# Recovery checkpoint

This is a work-in-progress source checkpoint, not a claim of stable recovery or a production deployment package. Public example identifiers and configuration placeholders are retained. Runtime configuration, credentials, participant data, databases, logs, deployment receipts and machine-specific files are excluded.

## Source changes

- Keep the pre-response ingress path focused on validation, the existing queue and durable acknowledgement preparation.
- Save durable acknowledgement evidence only after the HTTP response finisher confirms completion, before starting worker work.
- Preserve compatibility with the specifically reviewed operational baseline through explicit capability checks and its validated fallback. Arbitrary historical or public-main mixtures have not been verified; this is a complete source checkpoint, not an eight-file deployment recipe.
- Keep schema, authorization, voice-channel and outbound mention checks in place. HTTP ingress does not initialize or migrate storage.
- Attempt a bounded diagnostic checkpoint after durable acknowledgement, preserving earlier progress evidence when that best-effort write succeeds.

## Verification and remaining issues

The reviewed operational candidate passed isolated regression suites with two SQLite versions, including acknowledgement recovery and mixed-version compatibility checks. The anonymized public export must be validated separately; its test result should accompany the checkpoint.

Recovery remains incomplete. Some card requests succeed, while other client operations still fail intermittently. Missing application evidence does not establish the cause or completeness of upstream delivery. Operational log details are deliberately excluded from this public checkpoint.

Requests from a channel outside the configured permitted set currently receive HTTP 403 before queueing. A private guidance response for well-formed card interactions is proposed but has not been implemented. It must preserve signature, application, guild, user and voice restrictions and must not start business work in an unapproved channel.

Further work should distinguish Web ingress and response-delivery failures using narrowly scoped, anonymized evidence. Do not infer non-delivery from missing best-effort logs, weaken validation to make tests pass, or treat one successful request as stable recovery.

No server configuration, logging policy, endpoint, credential or live data change is part of this checkpoint. A checkpoint branch must not trigger a production deployment.
