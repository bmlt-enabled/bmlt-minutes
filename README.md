# BMLT Minutes

A simple WordPress plugin for NA (Narcotics Anonymous) Service Bodies to publish committee meeting minutes — PDFs, DOCX, XLSX, or Google Doc / Dropbox / OneDrive links — on their website.

Built by the [bmlt-enabled](https://bmlt.app) community.

## Why

~90% of NA service bodies run their site on WordPress, and every one of them needs to post Area / Region / Zonal Forum minutes each month. This plugin gives them a no-fuss way to do it: a dedicated Minutes post type, a Committee taxonomy, a meeting-date field, and a single shortcode to render the list.

Some service bodies redact PII from minutes and some don't, so each post supports an optional password — public by default, locked when a password is set.

## Install

Drop the plugin into `wp-content/plugins/minutes/` and activate.

For local development with Docker:

```bash
make dev
```

opens WordPress at <http://localhost:8080> with the plugin auto-mounted.

## Usage

```text
[minutes]
[minutes committee="hospitals-institutions" group_by="year"]
[minutes limit="10" group_by="none" show_excerpt="true"]
```

See `readme.txt` for the full attribute list.

## Develop

```bash
make composer  # install dev deps (phpcs, wpcs, phpunit, wp-phpunit, polyfills)
make lint      # phpcs
make fmt       # phpcbf
make test      # phpunit in Docker (no local DB needed)
make build     # zip for distribution
```

CI runs lint + PHPUnit on every PR (PHP 8.3 and 8.4). Tagged releases (e.g. `git tag 1.2.0 && git push --tags`) build a zip, attach it to a GitHub Release, and push to WordPress.org SVN. See `AGENTS.md` for the full workflow rundown.

## License

GPLv2 or later — see [LICENSE](LICENSE).
