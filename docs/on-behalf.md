# Privileged creation for an existing WordPress user

Approved integration goal: Support managers extract requests from topics/replies,
but the topic opener always owns the request. Site-specific source lookup,
permissions and references stay in Workspace Extra Code. See
YoOhw-com/yoohw-website#219 and its implementation PR#220. Adapter relocation
was completed and verified before this work began.

Core adds `Requests::create_for_user($input, $owner_id, $context = [])` for an
existing owner. Require YFR management and CPT creation capabilities. Never
switch the current actor or trust an author in the input. New records are Pending
with server-derived creator audit; personal submit keeps its existing integer
return and current-user ownership. Share validation and persistence. Support-created
pending records do not consume the owner's self-submission backlog quota;
legacy records without creator metadata continue to count.

Context supports a strict boolean `notify` (default true), and an optional
64-lowercase-hex `creation_key` unique per confirmation. Return `['id'=>int,
'created'=>bool]` or WP_Error. Keyed creation uses a scoped insert-only option lock
and persisted actor/owner-bound marker. Replays return the existing ID without
notifying or modifying it; different confirmations may create multiple requests
from the same source. No custom table or generic workflow engine. Native WordPress
post/meta insertion is not an arbitrary-process-crash transaction.

An integration may pass `notify=false`, persist/verify its own private metadata,
then fire the existing submitted event. Only a newly created record may be
rolled back by the caller on its source-write failure. Generic user-facing audit
and a permission-checked detail extension hook show ownership transparently.
Emails add a generic on-behalf sentence only, never source/private conversation
information or actor account identifiers. Privacy policy/export cover creator
audit; site-specific source data remains outside default exports.

Acceptance: authorization, malformed/forged owner, missing account, validation and
metadata/term failure, actor/session stability, deferred notification, replay and
concurrent keyed creation, different confirmations, legacy and managed quota,
detail hook access, escaped audit and no source disclosure. Existing runtime,
HTTP, notification, staging and Plugin Check remain required. Main performs a
fresh independent final security/persistence review. No merge, release, publish
or production deployment.

The insert-only option lock has no expiry or automatic stealing. A process crash can leave that one confirmation blocked; a new explicit confirmation uses another key. Normal retries after a completed response use the persisted ID. This deliberately fails closed instead of risking two concurrent writers. The adapter must hold its own confirmation lock until source metadata and notification finalization complete.
