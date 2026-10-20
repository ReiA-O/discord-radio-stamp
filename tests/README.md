# Offline tests

Run the 36 test groups with `php tests/run.php`. They use fictional signed requests, fake HTTP/Gateway adapters, temporary SQLite databases, and generated PNG fixtures. They do not verify live Discord, server deployment, or device image saving.

`RADIO_STAMP_TEST_ARTIFACT_DIR` may point to an absolute directory outside this repository. If absent, the test initializer creates an isolated random directory under the operating system temporary directory. A directory inside the repository is rejected before test output is written. The same initializer runs when an individual test is invoked directly.

This clean source export includes only the pure PHP fixture code needed by the current suite. Disabled activation is fictional. Role names, IDs, and permission observations in the runtime fixture are generated from fictional configured scope and policy; no historical guild observations are bundled.

The original local audit contained 43 additional checks that verified old, private image/archive bytes: 13 in the placeholder-card group and 30 in the stamp-rendering group. Those local preservation checks are intentionally excluded here because the owner archive and its manifests are not exported. Current card pixels, layouts, ordering, counts, resource bounds, runtime behavior, recovery, and qualification checks remain. The source owner retains the original audit separately.
