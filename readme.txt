=== Link Audit and Reports ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: links, outbound links, nofollow, sponsored, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audits every outbound link on your site, grouped by domain, with bulk rel changes, link stripping, whitelists, blacklists, an undo log and CSV export.

== Description ==

See every site you link to, in one sortable report, and fix your outbound links in bulk.

On activation, the plugin scans all your posts, pages and custom post types in the background and builds an index of every outbound link. After that it rescans each post whenever it's saved, so the report is always current.

**The report**

* Every linked domain, with its total links, number of posts, current rel attributes (follow, nofollow, sponsored, ugc), the authors who added the links, and first and last seen dates.
* Sort by any column, search domains, and filter by author, post type, rel attribute and date range.
* Click a domain to see every link to it: the post, its author, the link text, its rel values, and an edit link.

**Bulk and per-domain actions**

* Add nofollow, sponsored or ugc.
* Make links dofollow (removes nofollow, sponsored and ugc, keeps noopener and noreferrer).
* Strip links but keep the anchor text.
* Whitelist trusted domains to hide them from the report.
* Blacklist domains, so future links to them are stripped or nofollowed automatically whenever a post is saved.

Every change is logged, and any change can be undone from the Undo Log. Undo puts back exactly the links it changed and keeps any later edits to the post. Edited posts also keep a revision, and their "last modified" dates don't change.

Export the domain report, or every individual link, as a CSV file.

Find it under Content Powered > Link Audit and Reports in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `link-audit-reports` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress. The first scan starts in the background right away.
3. Go to Content Powered > Link Audit and Reports.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Which links does it audit? =

Links (`<a href>`) in the content of published, scheduled and private posts, pages and public custom post types that point to another website. Links to your own domain and relative links aren't listed. Drafts can be included from the Settings tab.

= Does it slow my site down? =

No. Nothing runs on the front end. The index is built in the background and updated when posts are saved.

= Can I undo a bulk change? =

Yes. Every change made from the report is listed in the Undo Log with an Undo button. If a post was edited after the change, the links that can still be found exactly are put back and the rest are reported; the post's revisions have the earlier version too.

= What happens when I delete the plugin? =

Its index, undo log and settings are removed. Links it changed in your posts stay as they are.

== Changelog ==

See https://github.com/jmsprsns/link-audit-reports/releases for the full release history.
