# Offline CI proposal (inactive)

The example at ../.github/workflows/offline-tests.yml.example is deliberately not active. No Actions configuration has been published.

Before activation:
1. Independently verify and pin checkout/setup-php to reviewed full commit SHAs.
2. Review workflow permissions and dependency/supply-chain choices.
3. Verify PHP 8.3 extensions and Japanese font resolution on the runner.
4. Run the exact candidate's suite with isolated artifacts and fake adapters. Do not supply operational secrets or endpoints.
5. Check failure exits, platform skips and subprocess behavior; record actual PHP/SQLite versions.
6. Decide whether to implement a hard network sandbox. Fake adapters alone do not enforce one.
7. Agree required checks/branch protection separately. Running CI is not permission to deploy.

This proposal covers one runner SQLite version only; it does not replicate the historical two-SQLite-version verification. No test execution or CI success is claimed by this draft.

