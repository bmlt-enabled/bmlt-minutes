<p align="center">
  <img src="minutes-logo.svg" alt="Minutes logo" width="128" height="128">
</p>

# BMLT Minutes for WordPress

[![WordPress Plugin](https://img.shields.io/wordpress/plugin/v/bmlt-minutes)](https://wordpress.org/plugins/bmlt-minutes/)
[![WordPress Tested](https://img.shields.io/wordpress/plugin/tested/bmlt-minutes)](https://wordpress.org/plugins/bmlt-minutes/)
[![PHP Version](https://img.shields.io/wordpress/plugin/required-php/bmlt-minutes)](https://wordpress.org/plugins/bmlt-minutes/)

[WordPress plugin](https://wordpress.org/plugins/bmlt-minutes/) for service bodies and committees to publish meeting minutes — PDFs, DOCX, XLSX, or links to Google Docs / Dropbox / OneDrive — via a single shortcode. Built by the [bmlt-enabled](https://bmlt.app) community.

## Usage

```
[minutes]
```

Filter, limit, or change the grouping per-page:

```
[minutes committee="hospitals-institutions" group_by="year"]
[minutes limit="10" group_by="none" show_excerpt="true"]
```

## Installation

1. Upload to `/wp-content/plugins/bmlt-minutes/`
2. Activate in WordPress admin
3. Add minutes under **Minutes → Add New** — upload a file or paste a Google Doc URL, choose a committee, set the meeting date
4. Add `[minutes]` to any page or post

## Settings

Configured under **Minutes → Settings**.

| Setting              | Description                                                                  |
|----------------------|------------------------------------------------------------------------------|
| Default Sort Order   | `desc` (newest first) or `asc`. Applies when `[minutes]` has no `order` attr. |
| Maximum Upload Size  | Per-file cap (MB) applied to uploads on the Minutes editor. Default 10 MB. Clamped to the server limit on save. |

## Shortcode Attributes

| Attribute      | Default     | Description                                                  |
|----------------|-------------|--------------------------------------------------------------|
| `committee`    | _all_       | Slug or comma-separated slugs of Committee terms to filter.  |
| `year`         | _all_       | Restrict to a single year by Meeting Date (e.g. `2026`).     |
| `limit`        | `-1`        | Max items to render. `-1` = no limit.                        |
| `order`        | `desc`      | `desc` (newest first) or `asc`.                              |
| `group_by`     | `committee` | `committee`, `year`, or `none`.                              |
| `show_excerpt` | `false`     | `true` to show each post's excerpt under the link.           |

## Password Protection

Some service bodies redact personal details from minutes before posting, others share unredacted minutes with members only. Each minutes post can be optionally locked:

- **Per-post**: set a value in the **Password Protection** field of the Minutes Document meta box (or use WordPress's native Publish → Visibility → Password protected). Locked entries show a padlock in the `[minutes]` list and require the password before the document URL is revealed.
- **Whole-page**: set a password on the page that contains `[minutes]` via the same Visibility control — WordPress's standard password form gates the entire page.

Default behavior with no password is fully public access.

## Supported File Types

PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, TXT, RTF, CSV — plus arbitrary URLs (Google Docs, Dropbox, OneDrive, anywhere else).

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for development setup, code standards, and the release flow. Security issues go through [SECURITY.md](.github/SECURITY.md).
