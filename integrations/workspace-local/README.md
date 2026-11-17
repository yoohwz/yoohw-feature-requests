# Local Workspace presentation for Feature Requests

This local-only adapter uses Support Portal's existing WordPress page UI, shell,
navigation behavior, custom color/font variables and assets. The YFR product
files remain the merged standalone plugin; this adapter is not part of its
distribution and does not run outside local/development on local hostnames.

`yoohw_yfr_local_page_id` identifies the published page containing
`[yfr_requests]`. Only that page receives the request-form CSS. A small script
adds a deduplicated navigation link and places the Feature Requests panel beside
the existing Workspace panel in an equal-width dashboard row (stacked on mobile).
Dashboard layout CSS loads only on the Support homepage. The existing host
menus have no extension filter; their templates and Workspace data stay intact.

The adapter adds only `yfr_submit` to Extra Code's existing customer admin-post
allowlist. YFR still validates POST/login/nonce and owns request authorization.
No Support license, SSO, topic, Project or payment behavior is changed.

Before Support's generic post-author filter runs, only the configured published
YFR Page main query is explicitly typed as `page`. This avoids treating an
admin-authored Page as a customer's support post. It does not remove author
conditions from topic/post queries or widen YFR object visibility.

Disable the adapter by removing the loader `yoohw-yfr-local.php`; the page still
contains the standard YFR shortcode. Deactivating YFR preserves its data.
Installation/configuration is for Workspace local only, not production.
