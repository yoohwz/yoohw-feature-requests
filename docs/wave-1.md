# Wave 1: foundation and request management

Human approved MVP and Wave 1 on 2026-11-17. This document is the bounded implementation contract; the PR records implementation and evidence.

## Outcome and scope

A logged-in WordPress user submits a Feature, Plugin or Integration request, receives an on-screen confirmation, and views their own submissions and moderation/roadmap state. A request moderator reviews, publishes or rejects submissions, manages categories/products and updates roadmap status in wp-admin.

The shortcode provides a form, personal list and authorized detail view. A pending submission uses WordPress `pending`; published requests have roadmap metadata (`under_review`, `planned`, `in_progress`, `released`, `declined`). Rejection uses draft/trash; it differs from declining an otherwise publishable suggestion.

No public community board, voting, merge, follower notifications, guest submission, comments/files, custom accounts, Workspace adapter, GitHub/project/payment integration, network activation or release/publication/deployment.

## Architecture and invariants

- One non-public CPT `yfr_request`, no core REST controller, archive, rewrite, feed or native search exposure. Frontend output is shortcode-only in Wave 1.
- Request title/content/author use WordPress posts; fixed request kind and roadmap status use protected metadata; category/product use plugin taxonomies. No custom table in Wave 1.
- New requests always belong to the current user and enter pending. Client-supplied author/status/roadmap values cannot grant authority.
- Only owner or `yfr_manage_requests` can read a request through the shortcode. List/count queries are scoped before retrieval, not filtered after pagination. No email/login disclosure.
- WordPress capabilities separate request administration from ordinary post editing. Only administrators receive moderator capabilities on activation; editors/customers gain no implicit management rights.
- Mutations use POST, nonce, login/capability checks, strict scalar validation, bounded content, existing term IDs and safe URL schemes. Rate limit per-account submissions and pending backlog; do not collect IP addresses or call external services.
- Output is escaped; description HTML is limited on input and output. Shortcode output returns rather than echoes. Personal views set no-cache headers early when the page contains the shortcode.
- Admin metadata saves honor nonce, capability, autosave/revision guards and allowlisted values. Saving another post type must not write YFR data.
- Deactivate/uninstall retain request data; document retention, privacy export/erase and manual deletion. No schema migration, locks or recovery framework is needed.
- No writes to existing Workspace/Support/website source or databases.

## Validation

Use a separate synthetic WordPress fixture. Cover anonymous, owner, unrelated user, editor and administrator; forged author/status; invalid/missing nonce; private detail/list/count leakage; core REST/search/feed/direct post access; rate/backlog limits; invalid terms/URL/HTML; moderation, status updates, autosave/revision handling; privacy export/erase; reactivation/uninstall retention; no WooCommerce/YoOhw dependencies. Exercise actual HTTP form POST and redirects as well as service tests.

Run PHP 7.4 and 8.2 syntax/runtime where available; WordPress 6.2 floor and current fixture; classic/block theme rendering, keyboard/mobile layout, Plugin Check against staged product files. Record exact limitations instead of claiming unrun combinations.

## Complexity budget

Plain PHP classes/templates, scoped CSS and one shortcode. No JavaScript is required for Wave 1. A small CI workflow runs syntax/runtime checks and staged Plugin Check; no release jobs or custom gate engine. Stop and revise the technical plan if persistence requires custom tables or guest identity, or if implementation starts changing host integrations; those belong outside Wave 1.
