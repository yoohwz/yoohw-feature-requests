# YoOhw Feature Requests

Standalone WordPress plugin for feature, plugin and integration suggestions. Wave 1 implements logged-in submission, private personal tracking, administration and moderation. No WooCommerce, YoOhw service or external API is required.

This is an unreleased development candidate. No production deployment or WordPress.org publication is authorized by this repository's initial PR.

## Use

Activate on an individual WordPress site and add `[yfr_requests]` to a published page (a Shortcode block works). Exclude the page from full-page caching. Administrators manage requests, categories and products in **Feature Requests**. Publish approves; Draft rejects; Trash removes spam. Roadmap status is separate from publication.

See [readme.txt](readme.txt) for privacy, retention and installation details, and [the Wave 1 contract](docs/wave-1.md) for scope and safety boundaries.

## Validation

`tests/runtime.php` and `tests/http.py` run only against a marked disposable WordPress fixture with a dedicated `yfr_wave1_*` database. They must never run against a customer site. Create `fixture.json` beside the WordPress root with `root` and `db` matching the isolated install. Outgoing mail must be disabled.

```sh
wp --path=/tmp/yfr-wave1-example/wordpress eval-file tests/runtime.php
python3 tests/http.py /tmp/yfr-wave1-example/fixture.json
bash scripts/stage-distribution.sh . /tmp/yfr-staged/yoohw-feature-requests
```

The CI workflow provisions its own database/core, runs syntax and runtime/HTTP checks for the declared floor and a modern version, then checks the staged plugin. No release or deployment jobs exist. Human retains merge/release/publication/deployment authority.
