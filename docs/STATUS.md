# Source status and maintenance handoff

Last inventory: 2026-11-02 UTC. This is a read-only GitHub inventory, not a live service check.

| Item | Observed state |
| --- | --- |
| Canonical public repository | ReiA-O/discord-radio-stamp |
| main at inventory | fcda3f2b97ab136cf501d55596b46ba9f0be15a7 (2026-10-21) |
| WIP branch | checkpoint/recovery-20261101-wip-092112 |
| WIP SHA | 5a84f050fbf711ada3518571aee24111ee71ac76 |
| WIP vs main | 1 commit ahead, 32 changed files |
| Pull requests / Actions runs | 0 / 0 observed |
| Branch protection | main and checkpoint reported protected:false |
| Private operations repository | Not yet identified or created by this work |

## Do not confuse these states
The main README and VALIDATION describe dated preparation/verification. They do not establish today's production status.
The checkpoint is a complete WIP source snapshot, not an eight-file deployment recipe. See [RECOVERY-CHECKPOINT at its fixed SHA](https://github.com/ReiA-O/discord-radio-stamp/blob/5a84f050fbf711ada3518571aee24111ee71ac76/docs/RECOVERY-CHECKPOINT.md).
Its report says intermittent client failures remain and its anonymized export requires separate validation. This inventory did not rerun those tests or inspect production. A successful card request is not stable-recovery evidence.

## Work queue
- Validate the exact sanitized checkpoint independently before recommending integration.
- Diagnose intermittent interaction failures using scoped anonymized evidence; preserve all authorization and delivery guards.
- Review proposed private guidance for card requests from disallowed channels. It is not implemented in the checkpoint and must not enable business work in unapproved channels.
- Add reproducible offline CI after reviewing the proposed workflow.
- Decide license, maintainer identities, private repository destination and branch protections with the owner.
- Review all reachable history and new diffs for secrets/private data before publication. No complete history audit is claimed here.

## Evidence record for each candidate
Record source SHA, branch, review reference, test commands/runtime versions/exits, affected behavior, skipped/blocked checks, remaining issues and approval scope. Keep production approval and live observation in the private operations handoff, with no raw secrets or participant data.

