# reMember

WordPress membership communities: **members**, **events**, **locations**, **applications**, **vetting**, **admission tickets**, and **billing** with **QuickBooks Online** or **Xero**. Extends WordPress users with custom tables, an admin UI under **reMember**, and front-end pages via shortcodes.

**Version:** 2.2.8  
**Requires:** WordPress 5.0+  
**License:** [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html)  
**Source:** [github.com/ctucker1984/remember](https://github.com/ctucker1984/remember)

---

## Install

1. Download **`remember-x.y.z.zip`** from the [GitHub Releases](https://github.com/ctucker1984/remember/releases) **Assets** — not GitHub’s auto “Source code (zip)” (that unpacks to the wrong folder name).
2. In WordPress: **Plugins → Add New → Upload Plugin**, then activate **reMember**.
3. On activation the plugin creates tables, seeds default roles, and grants reMember capabilities to the WordPress **Administrator** role. It does **not** auto-create a member for the activating user.
4. Use the **setup wizard** (or **reMember → Getting Started**) to create pages with shortcodes.

**Migration:** Activate reMember on the destination site (same version as the backup, or newer), then **reMember → Import / Export → Restore backup**. A newer backup will not load into an older install. Users are matched by email (then username); missing people are created as Subscribers with random passwords until they reset. Profile photos are URLs only. Setup-wizard pages stay on the new site. Billing secrets and live invoice ledgers are not in the file; after restore, reconnect QuickBooks or Xero so balances redownload against stored customer and invoice IDs.

Members log in through WordPress (`wp-login.php` or the site’s login). reMember uses that session; there is no separate member login form.

**Updates:** From **1.3.5+**, new releases appear under **Plugins → Updates** in wp-admin (WordPress 5.8+). reMember reads [GitHub Releases](https://github.com/ctucker1984/remember/releases) and installs the packaged `remember-x.y.z.zip`. Getting to 1.3.5 itself still requires one manual upload.

Before a dashboard update or Upload → Replace, reMember checks that the web server can replace every file in the plugin folder. If it cannot, the update stops and the installed copy stays as it is.

The updater checks the downloaded `remember-x.y.z.zip` against the SHA-256 digest GitHub records for that release asset. A download that does not match, or a release with no digest, is discarded and nothing is installed. That catches a truncated, corrupted, or swapped file between GitHub and the site. It does not protect against a compromised release account, because the digest comes from the same GitHub API as the download URL. An account that can publish a release can still ship code to every site running reMember. The updater ignores GitHub’s automatic “Source code” zip.

Keep that list small. Turn on two-factor authentication for every collaborator, give write access to as few people as possible, and protect `master` and release tags so a release cannot be published from an unprotected push.

**Upgrade tip:** From **1.3.0+**, Upload → Replace deactivates reMember, replaces files, then reactivates. When upgrading **from ≤1.2.x**, deactivate reMember first, then upload and activate.

**Deactivate vs Delete:** Deactivate pauses the plugin and keeps data. Delete (shown only after deactivate) removes plugin files and wipes reMember tables, settings, logs, photos, setup pages, and capabilities. WordPress user accounts stay. Deactivate offers a backup download first.

---

## First-time setup

Configure foundation data before members apply:

1. **Locations** — venues for events  
2. **Roles** — event and system roles / capabilities (members may hold more than one)  
3. **Products** — optional event add-ons (map to QBO/Xero items in Settings if needed)  
4. **Events** — attach location, roles, and add-ons  

Then add members via **Members → Add New**, **Convert WP User**, or the public registration shortcode. Shortcode reference: **reMember → Settings**.

---

## Features (overview)

| Area | Notes |
|------|--------|
| **Members** | Profiles (legal name private; public nickname / display name), photo cropper, privacy toggles, custom fields, clothing sizes, dietary / medical / allergies, profile notes (member-visible or private to wp-admin) |
| **Duplicates** | Daily scan of possible duplicate profiles; staff with **Merge Duplicate Profiles** review side by side, pick surviving fields and roles, then merge or undo. Members are emailed only after a merge. |
| **Reports** | Staff wp-admin builder (not raw SQL): subjects, columns, filters, grouping, AJAX preview, CSV, and per-user saved reports. Copy a saved report to another staff member who can run it. `View Reports` opens the screen; each column still needs the matching read cap. |
| **Events** | Locations, roles, add-ons, attendee directory, printable admission tickets |
| **Applications** | Review and save profile first, then apply / accept / decline / waitlist; optional agreements with typed legal name; allow reapply after decline/cancel |
| **Vetting** | New-member review workflow |
| **Billing** | One active provider: none, QuickBooks Online, or Xero; amounts in reMember are subtotal-oriented |
| **Import / Export** | CSV for members, events, locations, and custom field definitions. Full JSON backup and restore of plugin tables and settings (needs Access Settings and Import / Export Data). Billing secrets and live invoice ledgers are omitted; reconnect the provider after restore. Restore can migrate onto a fresh install. |
| **Agreements** | Versioned library; events pin revisions shown on apply |
| **Notifications** | Email templates in Settings, grouped by Vetting, Applications, Billing, and General |

---

## Changelog

See **[CHANGELOG.md](CHANGELOG.md)** for the full version history. Release notes are also on [GitHub Releases](https://github.com/ctucker1984/remember/releases).
