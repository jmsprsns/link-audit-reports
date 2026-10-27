# Link Audit and Reports

Audits every outbound link on your site, grouped by domain, with bulk rel changes, link stripping, whitelists, blacklists, an undo log and CSV export.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

- **Indexes every outbound link.** On activation a one-time background scan reads every post, page and public custom post type (WP-Cron batches, faster while the report is open). After that, each post is rescanned whenever it's saved, so the index stays current without another full scan.
- **Groups them by domain.** Content Powered > Link Audit and Reports lists every linked domain with its total links, number of posts, rel attributes (follow, nofollow, sponsored, ugc), the authors who added the links, and when they were first and last seen. Sortable, searchable, and filterable by author, post type, rel and date range.
- **Shows each link.** Clicking a domain opens every link to it: the post, its author, who added the link, the link text, its rel values, and Edit/View links.
- **Changes them in bulk.** Add nofollow, sponsored or ugc, make links dofollow, or strip links and keep their text, for one domain or many at once. Changes respect the report's filters, so "add sponsored to every Amazon link Maya added this year" is one click.
- **Whitelist and blacklist.** Whitelisted domains are hidden from the report. Links to blacklisted domains are stripped or nofollowed automatically whenever a post is saved, whoever wrote them.
- **Undo log.** Every change is logged and can be undone. Undo puts back exactly the spots it changed, keeping later edits to the posts, and every edited post also keeps a revision.
- **CSV export.** Domains, or every single link, with the current filters.

## How it works

- The index is a table (`{prefix}lar_links`) with one row per outbound link. A link is identified by its post, its URL and which copy of that URL in the post it is, so edits elsewhere in a post never change which row a link belongs to. Links that appear in a save are credited to the user saving it ("Added by"); links found by the first scan are credited to the post's author and dated from its publish date.
- Outbound means an absolute or protocol-relative http(s) `<a href>` to a host other than this site's (`www.` ignored). URLs are read the way browsers read them (tabs and newlines dropped, backslashes as slashes, %-escaped hosts decoded), so a link can't hide from the audit or the blacklist behind a trick a browser ignores.
- Changes are surgical: adding or removing rel values touches only the `rel` attribute, and stripping removes only the `<a>` and `</a>` tags. Make dofollow removes nofollow, sponsored and ugc and keeps noopener/noreferrer. A Buttons block keeps its `<a>` (without a link) so the block stays valid.
- Changes run in batches (AJAX while the page is open, WP-Cron otherwise), one at a time, and keep each post's "last modified" date. The log (`{prefix}lar_log`, newest 200 entries) records each changed spot and what was there before.

## Filters

```php
// Post types to audit (default: every public type except attachments).
add_filter( 'link_audit_reports_post_types', function ( $types ) { return $types; } );

// Leave a post out entirely: not listed, not changed, and the blacklist skips it.
add_filter( 'link_audit_reports_skip_post', function ( $skip, $post ) { return $skip; }, 10, 2 );

// Extra hosts that count as this site (lowercase, no "www.").
add_filter( 'link_audit_reports_internal_hosts', function ( $hosts ) { return $hosts; } );
```

## Automatic updates

Updates come from https://github.com/jmsprsns/link-audit-reports through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `link-audit-reports.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `link-audit-reports.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'LINK_AUDIT_REPORTS_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'LINK_AUDIT_REPORTS_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
link-audit-reports.php                           Plugin header, constants, boot, activation
uninstall.php                                    Deletes the tables and options when the plugin is deleted
readme.txt                                       WordPress readme; feeds the "View details" popup
includes/
  class-link-audit-reports-plugin.php            Main singleton: settings, whitelist and blacklist
  class-link-audit-reports-content.php           Finds outbound links and makes the surgical edits (and undoes them)
  class-link-audit-reports-db.php                The link index and undo log tables, report queries
  class-link-audit-reports-scanner.php           Background full scan, rescan on save, blacklist on save
  class-link-audit-reports-jobs.php              Runs and undoes the report's changes in batches
  class-link-audit-reports-export.php            CSV exports
  class-link-audit-reports-admin.php             Content Powered > Link Audit and Reports page, AJAX and form handlers
  class-link-audit-reports-list-table.php        The domains report
  class-link-audit-reports-menu.php              Shared top-level "Content Powered" menu
  class-link-audit-reports-feedback.php          Shared Submit Feedback and Report a Bug pages
  class-link-audit-reports-updater.php           GitHub update checks (PUC wiring)
  class-link-audit-reports-icons.php             Inline Lucide icon helper
assets/
  admin.css, admin.js                            Loaded only on the plugin's page
  icon.svg                                       Icon on the update screens
  content-powered.svg                            Sumo logo for the Content Powered menu
icons/                                           Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                           Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-link-audit-reports-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-link-audit-reports-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/` (copy more from the full set as needed). Print one inline with:

```php
echo Link_Audit_Reports_Icons::get( 'package', 20 );           // name, size in px
echo Link_Audit_Reports_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
