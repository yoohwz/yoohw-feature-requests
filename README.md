# YoOhw Feature Requests

Standalone WordPress plugin for feature, plugin and integration suggestions. Wave 1 implements logged-in submission, private personal tracking, administration and moderation. No WooCommerce, YoOhw service or external API is required.

This is an unreleased development candidate. No production deployment or WordPress.org publication is authorized by this repository's initial PR.

## Use

Activate on an individual WordPress site and add `[yfr_requests]` to a published page (a Shortcode block works). Exclude the page from full-page caching. Administrators manage requests, categories and products in **Feature Requests**. Publish approves; Draft rejects; Trash removes spam. Roadmap status is separate from publication.

See [readme.txt](readme.txt) for privacy, retention and installation details, and [the Wave 1 contract](docs/wave-1.md) for scope and safety boundaries.

## Validation

`tests/runtime.php` and `tests/http_checks.py` run only against a marked disposable WordPress fixture with a dedicated `yfr_wave1_*` database. They must never run against a customer site. Create `fixture.json` beside the WordPress root with `root` and `db` matching the isolated install. Outgoing mail must be disabled.

```sh
wp --path=/tmp/yfr-wave1-example/wordpress eval-file tests/runtime.php
python3 tests/http_checks.py /tmp/yfr-wave1-example/fixture.json
bash scripts/stage-distribution.sh . /tmp/yfr-staged/yoohw-feature-requests
```

The CI workflow provisions its own database/core, runs syntax and runtime/HTTP checks for the declared floor and a modern version, then checks the staged plugin. No release or deployment jobs exist. Human retains merge/release/publication/deployment authority.

## Essential email notifications

New frontend submissions notify the configured administrator address. Completed
moderation decisions and published roadmap changes notify the request author.
Configure the three switches, recipient and published shortcode Page in
**Feature Requests → Email notifications** (site administrators only). No
submission receipt or follower mail is sent. Existing requests are not backfilled.
Messages use WordPress mail and contain title/status plus an optional authorized
Page link, never the request description. The site supplies its mail transport.

See [notification boundaries](docs/notifications.md). Failed transport retries at
five-minute intervals, at most three attempts total; newer updates supersede old
failed messages and changed settings cancel queued messages. Accepted mail is not
proof of inbox delivery. WP-Cron must be functioning for retries. No external mail
service or Support plugin is required. Support's optional global template can wrap
these messages on Workspace without a second template or notification worker.

Run `wp eval-file tests/notifications.php --path=/path/to/marked/fixture` for
intercepted-mail acceptance in the same guarded disposable fixture.
