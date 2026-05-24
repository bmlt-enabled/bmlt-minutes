=== BMLT Minutes ===

Contributors: bmltenabled, pjaudiomv
Tags: narcotics anonymous, na, meeting minutes, bmlt, service committee
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Publish NA service committee meeting minutes (PDF, DOCX, XLSX, Google Doc links) with a simple shortcode.

== Description ==

BMLT Minutes is a lightweight WordPress plugin for NA (Narcotics Anonymous) Service Bodies — Areas, Regions, and Zonal Forums — to publish committee meeting minutes on their website. Most service committees produce PDFs, Word docs, spreadsheets, or Google Doc links every month, and need a clean way to organize them by committee and date.

Features:

* Dedicated **Meeting Minutes** post type with a uploaded-file or external-URL document field
* **Committee** taxonomy seeded with common NA committees (ASC, RSC, H&I, PR, Activities, Outreach, Literature, Policy)
* Meeting-date field separate from publish-date, with sorting and year-grouping
* Supports PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, TXT, RTF, CSV, plus arbitrary URLs (Google Docs, Dropbox, OneDrive)
* Single `[minutes]` shortcode with grouping, filtering, and limiting
* Optional **password protection** per post — for minutes that still contain unredacted personal details
* Block-editor compatible (custom fields exposed via the REST API)
* Works on any BMLT-enabled or non-BMLT NA site

= Usage =

Add the shortcode to any page or post:

`[minutes]`

Filter to one committee, group by year:

`[minutes committee="hospitals-institutions" group_by="year"]`

Show the 10 most recent, flat list with excerpts:

`[minutes limit="10" group_by="none" show_excerpt="true"]`

Shortcode attributes:

* `committee` — Slug or comma-separated slugs of Committee terms. Defaults to all committees.
* `year` — Restrict to a single year by Meeting Date (e.g. `2026`).
* `limit` — Max items to render. `-1` (default) = no limit.
* `order` — `desc` (default, newest first) or `asc`.
* `group_by` — `committee` (default), `year`, or `none`.
* `show_excerpt` — `true` or `false` (default). Shows each post's excerpt under the link.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/minutes/`, or install via the Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. (Optional) Open **Minutes → Settings** to set your BMLT server URL and default sort.
4. Add minutes under **Minutes → Add New** — upload a file or paste a Google Doc / external link, choose a committee, set the meeting date, publish.
5. Add `[minutes]` to any page or post.

== Frequently Asked Questions ==

= Where are the documents stored? =

Uploaded files go into the standard WordPress Media Library. External links (Google Docs, Dropbox, OneDrive) are stored as URLs on the minutes post — nothing is copied or proxied.

= Can I use this without BMLT? =

Yes. The plugin works as a standalone document-list plugin. The BMLT server URL setting is optional and only used to surface contextual info — no calls are made unless you choose to integrate later.

= Can I add my own committees? =

Yes. The Committee taxonomy is hierarchical (like Categories). Go to **Minutes → Committees** to add, rename, or nest committees. The default list is just a starting point on first activation.

= How do I link to a Google Doc instead of uploading? =

In the **Minutes Document** meta box on the editor screen, leave the file field empty and paste your Google Doc / Drive URL into the **External Link** field.

= Can I password-protect minutes that contain personal details? =

Yes. Some service bodies redact PII before posting, others share unredacted minutes with members only. On the editor screen, set a value in the **Password Protection** field of the Minutes Document meta box (or use WordPress's built-in Visibility → Password protected option in the Publish panel). On the public `[minutes]` list, protected entries show a padlock and link to a password-prompt page rather than exposing the document URL. Members enter the shared password once per browser to unlock the document. Default behavior with no password set is fully public access.

= Can I password-protect the entire page that contains [minutes]? =

Yes — use WordPress's built-in page password. Edit the page that holds your `[minutes]` shortcode, open the Publish (Block Editor: Status & visibility) panel, set **Visibility → Password protected**, and enter a password. Visitors will see WordPress's standard password form before the whole page (including the minutes list) is rendered. This is independent of the per-post password on individual minutes — you can combine them if you want a members-only landing page plus an extra lock on specific minutes.

= Will uninstalling delete my minutes? =

Yes. The `uninstall.php` script removes the plugin's settings and deletes all minutes posts when you delete the plugin via the WordPress admin. Deactivate instead of uninstalling if you want to preserve them.

== Screenshots ==

1. Frontend list of minutes grouped by committee.
2. Editor screen with the Minutes Document meta box (file picker or external URL).
3. Settings screen.

== Changelog ==

= 1.0.0 =
* Initial release.
