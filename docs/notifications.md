# Essential email notifications

Approved scope: notify the configured admin recipient about a completed frontend
submission; notify the author about Published/Not approved moderation and changes
to Planned, In Progress, Released or Declined while published. A combined save
produces one author email. No submission receipt, followers or bulk mail.

Use WordPress mail without a Support/WooCommerce dependency. Workspace can reuse
its existing global Support email template. Settings are administrator-only:
three notification switches, one admin address (site admin by default) and the
published shortcode Page for author links. Email contains request title/status,
not its description or private Support/Project data. Never use a nonpublic CPT
permalink. No backfill when activating/upgrading or enabling a switch.

Capture before-state at pre_post_update, complete native saves only after
wp_after_insert_post and validated/read-back metabox metadata. Explicit hooks
complete frontend submissions after terms persist and bracket external adapters'
multi-step updates. Failed adapter updates never complete. Raw metadata writes
alone are not a completed review operation. Trash/restore, autosaves/revisions,
unchanged saves and unpublished roadmap edits do not send author notifications.

Keep retry bounded using WordPress single cron events and request metadata;
recheck settings, recipient ownership and object state at each attempt. Mail
failure does not fail the request save. An accepted message is not proof of inbox
delivery, and exactly-once delivery is not promised. No generic queue framework.

Acceptance covers complete/failed submission, native publication and metadata
saves, one combined email, invalid nonce/metadata/readback, unchanged saves,
Trash/restore, disabled types, invalid/missing/changed recipients, header/content
injection, safe links and authorization, bounded/stale retries and optional
Support wrapping without double templates. Tests run only on marked disposable
fixtures with mail/HTTP blocked. Actual local emails remain blocked.

Independent plan challenge confirmed the completion boundaries above and the
partial-write risk in Workspace. Final security/persistence review and focused
runtime/HTTP, staging and Plugin Check are required before handoff. No release,
publication, merge or production deployment is authorized.
