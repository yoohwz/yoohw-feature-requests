# YoOhw Feature Requests

- Communicate with Human in Vietnamese; keep source, product strings and PR content in English.
- Human owns scope, merge, release, publication, deployment and rollback. No automatic release or deployment.
- Work on a task branch and PR. Preserve unrelated work. Git/PR and CI are the execution record; do not add workflow state machinery.
- Keep this a standalone WordPress plugin: no required WooCommerce, YoOhw service, Support Portal or external infrastructure.
- Use WordPress users and sessions. Do not add account, password or SSO implementations.
- Pending requests are visible only to their author and request moderators. Enforce object access on every entry point. Never expose email, login names or private Support/Project data.
- State changes require nonce and authorization checks. Do not trust client author, publication status or roadmap status.
- Record scope, invariants and meaningful test scenarios in the existing PR before risky persistence/security implementation. Use a fresh independent subagent for the plan challenge and final security/persistence review; provide source/diff and test evidence. Fix findings in the same PR.
- Tests must use an isolated WordPress fixture with synthetic identities, never production or an existing customer database. Mark unavailable checks honestly.
- Run PHP syntax, focused WordPress runtime tests, distribution staging and Plugin Check. Keep development files out of the payload. Do not add release automation in Wave 1.
- Deactivation and uninstall retain requests, taxonomy terms and request metadata. The WordPress confirmed personal-data eraser deletes only the identified user's requests and their revisions/metadata, retaining shared taxonomy terms. Do not add other destructive cleanup.
