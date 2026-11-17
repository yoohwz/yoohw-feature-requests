=== YoOhw Feature Requests ===
Contributors: yoohw
Tags: feature requests, feedback, roadmap
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Submit and moderate feature, plugin and integration requests with existing WordPress accounts.

== Description ==

YoOhw Feature Requests is a standalone request-management plugin. It does not require WooCommerce, a YoOhw account, Support Portal or an external service.

This initial development version provides:

* Authenticated Feature, Plugin and Integration submissions.
* Moderation before approval, with separate roadmap status.
* Private personal submission history and request details.
* WordPress administration for categories, products and request review.
* WordPress personal-data export and confirmed erasure integration.

Add `[yfr_requests]` to a published page, including inside WordPress's Shortcode block. Logged-out visitors see a login link. This plugin uses your existing WordPress login and registration policy; it does not create a new account system or enable registration.

All frontend requests, including approved requests, are visible only to their author and request moderators in this version. A community board, voting, duplicate merging, follower notifications and Workspace integration are outside this version.

Suggestions do not create projects, GitHub tasks, payments, delivery dates or commitments to implement. Use your site's separate contact/project workflow for paid custom development. Do not submit confidential information.

== Installation ==

1. Install the plugin directory in `wp-content/plugins/yoohw-feature-requests` and activate it on an individual site.
2. Create a published page containing `[yfr_requests]`.
3. Exclude that page from full-page, CDN and fragment caching. The plugin sends no-cache headers for pages containing the shortcode directly, but cannot override cached responses served before WordPress runs.
4. Manage categories and products under Feature Requests. Administrator accounts receive the plugin's moderation capabilities on activation.
5. Review Pending requests. Publish approves them; Draft rejects without publication; Trash removes spam. Select the separate roadmap status in Request Details.

Network-wide activation is not supported. No permalink flush or setup service is needed.

== Frequently Asked Questions ==

= Does it require WooCommerce or a YoOhw subscription? =

No. It uses local WordPress users, posts, taxonomies and metadata.

= Can visitors submit without logging in? =

No. Users need an existing WordPress session. The plugin limits normal repeated submissions to one per minute and five pending requests per account. These are anti-spam controls, not a strict concurrent quota or protection against multiple accounts.

= Are approved suggestions public? =

Not in this initial version. They remain in the author's private request history and the moderation screen. A later community board requires an explicit site publication policy.

= Does the plugin send emails or make external requests? =

No. This version sends no email and makes no external API calls. It does not include telemetry, remote assets or an updater outside WordPress.org.

= What data is stored and removed? =

Request title, description, WordPress author ID, type, category/product, reference URL, moderation/roadmap status, timestamps and revisions are stored locally. No IP address or separate email copy is collected. User-linked throttle flags last one minute; form error codes last five minutes. WordPress privacy export includes current requests; confirmed erasure permanently deletes that user's requests with revisions and metadata, while preserving shared categories/products and unrelated users' requests. Deactivation and uninstall retain data and moderation capabilities. To remove data intentionally, use the moderation screen or WordPress's confirmed privacy tools before uninstalling. Existing administrator exports/backups are outside the plugin's erasure scope.

== Changelog ==

= 0.1.0 =
* Initial development candidate: authenticated submission, private tracking, moderation and roadmap status management.

== Email notifications ==

Site administrators configure notification types, one moderation recipient and
an optional published [yfr_requests] Page under Feature Requests > Email
notifications. New submissions notify moderation; completed moderation and
published roadmap changes notify the author. No follower or receipt emails.
WordPress mail configuration is reused; no SaaS or Support plugin is required.
Messages include title/status, not request descriptions. Failed delivery retries
up to three attempts using WP-Cron; accepted mail does not prove inbox delivery.
No historical backfill. Existing data remains retained on deactivation/uninstall.
