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
[bmlt_minutes]
```

Filter, limit, or change the grouping per-page:

```
[bmlt_minutes committee="hospitals-institutions" group_by="year"]
[bmlt_minutes limit="10" group_by="none" show_excerpt="true"]
```

## Installation

1. Upload to `/wp-content/plugins/bmlt-minutes/`
2. Activate in WordPress admin
3. Add minutes under **Minutes → Add New** — upload a file or paste a Google Doc URL, choose a committee, set the meeting date
4. Add `[bmlt_minutes]` to any page or post

## Settings

Configured under **Minutes → Settings**.

| Setting              | Description                                                                  |
|----------------------|------------------------------------------------------------------------------|
| Default Sort Order   | `desc` (newest first) or `asc`. Applies when `[bmlt_minutes]` has no `order` attr. |
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

- **Per-post**: set a value in the **Password Protection** field of the Minutes Document meta box (or use WordPress's native Publish → Visibility → Password protected). Locked entries show a padlock in the `[bmlt_minutes]` list and require the password before the document URL is revealed.
- **Whole-page**: set a password on the page that contains `[bmlt_minutes]` via the same Visibility control — WordPress's standard password form gates the entire page.

Default behavior with no password is fully public access.

## Committee Scope

By default anyone with minutes access can edit every committee's minutes. To limit a user to a subset, open their profile (**Users → edit user**) and tick committees under **Committee Scope** in the Meeting Minutes section. A scoped user:

- can only add, edit, and delete minutes filed under those committees (sub-committees included);
- sees only those committees in the editor and the Minutes list;
- must choose one of their committees before publishing.

Leave every box unchecked for access to all committees. Administrators are never restricted.

For a region hosting several areas, nest each area's committees under the area (Area A → Hospitals & Institutions, Area B → Hospitals & Institutions) and scope each area's uploader to their area. Grouped output labels nested committees by their full path, so same-named sub-committees stay separate.

## Supported File Types

PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, TXT, RTF, CSV — plus arbitrary URLs (Google Docs, Dropbox, OneDrive, anywhere else).

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for development setup, code standards, and the release flow. Security issues go through [SECURITY.md](.github/SECURITY.md).
