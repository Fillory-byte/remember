# Changelog

All notable changes to reMember are listed here. The current plugin version is in `remember.php` (`REMEMBER_VERSION`) and [GitHub Releases](https://github.com/ctucker1984/remember/releases).

## 2.2.8

- **Fix:** An admission ticket prints the member's legal first and last name. The public display name is left off the ticket. If the legal name is blank, the ticket uses the WordPress first and last name, and the display name only when those are blank too. [#125](https://github.com/ctucker1984/remember/issues/125)

## 2.2.7

- **Fix:** A ticket shows the discount that reduced the role price, including a 100% discount that brings the total to $0.00. A role whose price in reMember is $0.00 is marked paid, so the applications list does not say Not invoiced and the ticket does not say payment is due. A $0.00 invoice is sent when the role is mapped. When it cannot be sent, no invoice is sent and the application is still marked paid.
- **Feature:** Settings can choose which WordPress roles and reMember roles are emailed when a new member profile is created. That covers public registration, a member added in wp-admin, and a WordPress user turned into a member. Until the choice is saved, System Administrators still receive it, and WordPress administrators receive it only when no System Administrator has an email address. The new member is not emailed about their own profile.
- **Fix:** Interests is required when someone registers and when a member saves their profile. A blank editor does not count, including one that only contains an empty paragraph or a space. Staff can still save a member in wp-admin without interests. [#121](https://github.com/ctucker1984/remember/issues/121)
- **Fix:** On a phone, each application in wp-admin is one short card. The event name and status share the first line, the member is on the next, and the role, billing, and date sit together. The action buttons wrap on their own line. A long event name no longer stacks one letter per line beside the member's email.
- **Fix:** Automatic vetting opens a case only for a member who has none, and only when the configured trigger says to. A new profile does that when the workflow is On Join. A first event application does that when the workflow is On First Event Application. A member who already has a case is left alone, including a vetted member whose first application used to be skipped only because their status was vetted. Another case after that is opened by hand.
- **Fix:** A member whose latest decided vetting case was a rejection cannot see events they were not already accepted to, and cannot apply to any event. Events they were accepted to stay visible. A newer case that is still open does not lift this. It lifts when that newer case is accepted.

## 2.2.6

- **Fix:** A Xero invoice for an event application uses the discount stored on that contact. A contact set to 100% is invoiced at zero, and the amount due stays zero instead of the full price. The invoice already sent for a live application is left as it is.
- **Fix:** Automatic vetting leaves a member alone when they are already vetted. A new profile or a first event application is considered only after that, so a vetted member's first application no longer opens a case or moves them back to in vetting. Opening a case by hand is unchanged.
- **Feature:** A survey report lists each response on its own row and each question in its own column, with the answer in the cell. A choice shows its label, and a question the member was not asked is left blank. Question and Answer remain filters. Summaries, and reports for members, applications, payments, vetting, and events, are unchanged.
- **Fix:** A one-click update stops before it touches the plugin when the web server cannot replace the files ([#113](https://github.com/ctucker1984/remember/issues/113)). The message names up to five paths, who owns them, and the account the web server runs as, and the installed copy stays active. Upload → Replace does the same check once the zip is open. This is the failure that left a half-deleted plugin folder on the demo box.
- **Feature:** The updater checks the downloaded release zip against the SHA-256 digest GitHub records for that asset ([#104](https://github.com/ctucker1984/remember/issues/104)). A mismatch, or a release with no digest, stops the update and nothing is installed. The digest comes from the same GitHub API as the download, so a compromised release account is still the trust boundary.
- **Feature:** An application's page shows the survey answers submitted with it. The question is shown with the answer the member gave, and a choice shows its label. Follow-up surveys, which are answered later, stay off this page.
- **Feature:** The admin dashboard leads with the work that needs someone: your vetting, unassigned cases, applications waiting for review, duplicate profiles, and events whose registration closes within a week. The counts are links. Upcoming events show the date, the place, and how many applications are pending or accepted. Recently updated members are listed with their roles. A short card stays as tall as its contents, so Needs attention no longer stretches to the height of that list. A quiet site says all clear instead of three empty sections. The WordPress dashboard widget uses the same cards, lists what needs attention, and says all clear when nothing is waiting.

## 2.2.5

- **Feature:** Survey roles are chosen per question instead of per survey. An event has one survey on the application, plus any follow-ups, and each question says which roles answer it; ticking no roles asks everyone. Applicants see only the questions for the role they apply for, and applying for a second role keeps earlier answers and asks only the new questions. Follow-ups are emailed only to accepted members with at least one question for their role. Event results measure each question against the members who were asked it. In survey reports, "Role" is now the member's role, and "Question asked of" shows which roles a question was for. Database 2.4.2 moves each survey's roles onto its questions and merges an event's application surveys into one, keeping every question and answer; the merged surveys' instructions are added to the end under their old titles, and a notice on the Surveys screen lists what was merged so the wording can be checked.
- **Feature:** A survey can be copied to another event from the Surveys screen ([#107](https://github.com/ctucker1984/remember/issues/107)). Each question's roles carry over to the matching roles on the new event, and a question left with no matching role is asked of everyone. The copy lists any such changes. An application survey copied to an event that already has one becomes a follow-up draft.
- **Feature:** Settings has an Automatic case assignment choice for vetting cases opened automatically: leave unassigned so the whole vetting team is emailed (the default), rotate through vetters, or give each case to the least busy vetter. Assigned vetters get the Vetting Case Assigned email.
- **Feature:** reMember keeps its own log, `remember.log` in the protected log folder, whether or not `WP_DEBUG_LOG` is on. The Log Level setting is the only switch, so errors are recorded on every site by default. The file is rotated at 5 MB.
- **Feature:** Login usernames are no longer handed out by public author URLs ([#106](https://github.com/ctucker1984/remember/issues/106)). The users sitemap is removed, `?author=<id>` returns "not found" to logged-out visitors, and new accounts get a random author slug. A profile whose author slug is still its login shows a one-click fix. Author pages reached by slug keep working. Sites that want WordPress's default can return false from `remember_hide_usernames`.
- **Feature:** The QuickBooks tab checks the connection when it opens and says whether it is working, expired, or temporarily unreachable. A Reconnect button replaces the login without disconnecting, as on the Xero tab. Reconnecting to a different QuickBooks company, or switching between sandbox and production, is refused so stored invoice links cannot point at the wrong company.
- **Fix:** The "On First Event Application" vetting workflow now opens a vetting case ([#110](https://github.com/ctucker1984/remember/issues/110)). Before, it never did, and unvetted members could not send the application that was meant to start vetting. They can now apply, and staff cannot accept the application until the member is vetted. If a case cannot be opened, System Administrators get a Vetting Case Not Opened email with a link to open it by hand.
- **Fix:** The Vetting Case Assigned email names the assigned vetter through a new `{vetter_name}` placeholder instead of telling the whole team "You have been assigned". Database 2.4.1 updates the stored email only where it still matches the old default word for word; edited emails are kept.
- **Fix:** A profile saves when the browser autofills Current password ([#109](https://github.com/ctucker1984/remember/issues/109)). Only the new-password fields start a password change, and an incomplete change names each missing field.
- **Fix:** Bullets and numbers show in lists in reMember's editors on block themes such as Ollie ([#108](https://github.com/ctucker1984/remember/issues/108)).
- **Fix:** The HSTS security header is sent on HTTPS sites behind Cloudflare or another proxy ([#105](https://github.com/ctucker1984/remember/issues/105)). A header already set by the host is left alone.
- **Fix:** Xero requests can only go to Xero's API, so another plugin cannot send reMember's Xero login elsewhere ([#101](https://github.com/ctucker1984/remember/issues/101)). Existing Xero connections are unaffected. Reported by [@Fillory-byte](https://github.com/Fillory-byte).
- **Fix:** QuickBooks now matches Xero in these ways:
    - Customer updates change only the name, email, phone and billing address, so fields a bookkeeper set in QuickBooks are no longer wiped.
    - Customers are named from the member's legal name, a name clash is retried with the email added, and a temporary outage no longer relinks a member or creates a duplicate customer.
    - Invoices carry "Event - Application #N", the customer's email and unit prices, and show in the ledger straight away.
    - Only invoices QuickBooks marks as voided are treated as void; a $0 invoice is not.
    - Payment and refund lookups read every page of results, a failed lookup leaves the local record alone, and refunds are matched to the invoice named in their memo.
    - Sign-in uses a one-time state, explains a cancelled or denied sign-in, and Disconnect revokes the login at Intuit.
- **Fix:** Refunding a paid invoice, on either provider, leaves the invoice as it is and gives billing staff step-by-step instructions, because the money goes back through whatever the member paid with. Unpaid invoices are still voided. Before, Xero could leave an orphaned credit that was applied to the member's next invoice.

## 2.2.4

- **Feature:** Report filters can be combined with AND or OR, and grouped with parentheses. Each filter after the first has an AND/OR choice, and once there are two or more filters, each one can open or close a group. A line under the filters spells out the result, such as "Rows must match: (Status is vetted OR Status is in_vetting) AND City is Portland". Reports saved before this keep requiring every filter. AND and OR need parentheses where they meet, so the result never depends on which one binds first. A group that is never closed, or closed without being opened, is pointed out by filter number before the report runs or saves. The event limit and access rules always apply on top of the filters.
- **Feature:** In the private-event invite list, staff who can read member records see each member's real name in smaller type under the display name. The vetting status stays as it was, members with no real name on file show only the display name, and the search box matches real names too. Thanks to [@Fillory-byte](https://github.com/Fillory-byte) ([PR #100](https://github.com/ctucker1984/remember/pull/100)).
- **Fix:** Adding or removing a report filter no longer clears the values chosen in the other filters.
- **Fix:** On phones, the Print menu on a member's profile opens as a full-width list under the Print button instead of running off the left edge of the screen. Thanks to [@Fillory-byte](https://github.com/Fillory-byte) ([PR #99](https://github.com/ctucker1984/remember/pull/99)).
- **Fix:** The Print menu's "Confidential profile" hint now says whether the printout includes emergency contact, health details, or both, matching what that user's print actually contains. Before, it mentioned only emergency contact.

## 2.2.3

- **Fix:** A follow-up survey that is missing a required answer keeps everything else the member entered instead of clearing the form. Required text, choice, and paragraph questions are also checked by the browser before the form is sent. Thanks to [@Fillory-byte](https://github.com/Fillory-byte) ([PR #89](https://github.com/ctucker1984/remember/pull/89)).
- **Fix:** An application that fails a check the member can fix, such as an incomplete survey, a missing agreement, or the profile confirmation, shows the form again under the error. The chosen role, add-ons and quantities, and survey answers are kept. Agreements and the profile confirmation are left for the member to complete again.
- **Feature:** On the admin member screen, health information and the emergency contact stay hidden until staff click Show for that section. Opening a profile to view or edit it no longer writes to the access log; each Show does, for that section only. A profile save changes health or emergency data only when that section was opened, and the save is logged as well.
- **Feature:** Printing the Confidential profile, from the Print menu or with Ctrl/Cmd+P, writes one access log entry before the print preview opens and always includes the emergency contact and health sections the user may see. It requires the confidential print right and access to emergency contact or health; without them, the print does not load or log that data. If the entry cannot be written, nothing is printed.
- **Fix:** On a phone, the event Add and Edit forms show each event role as a compact card: the checkbox and role name on one line, then labeled price and capacity. Tapping the role name ticks its box. Each add-on's per-role limits are one line per role, with the name on the left and the quantity on the right. Desktop is unchanged.

## 2.2.2

- **Fix:** The Events list on a phone is a compact card like Members: name and location on the first line, dates, status, type, and actions on the next.
- **Fix:** The Vetting list on a phone is a compact card like Members: case and member on the first line, vetter and schedule, then status, decision, and View Case.
- **Fix:** Surveys, Billing, and Applications admin lists on a phone use the same compact cards: title and secondary value on the first line, then status, amounts or actions.

## 2.2.1

- **Feature:** A private event is hidden from members who are not invited. Checking Private on create or edit shows a compact member pick list. Invite emails go out when registration opens, or when the event is saved as Open if no open time is set. An invite is not an application; invited members still complete the usual apply workflow. Database 2.4.0.
- **Fix:** A failed profile save or public registration keeps the submitted values on the form instead of reloading a blank copy from the database. Passwords are left empty. A new photo still has to be chosen again.
- **Fix:** Validation names every failing field in the error banner and marks those fields on the profile edit and public registration forms. On registration the note sits under the field, and the red border shows against the theme.

## 2.2.0

- **Feature:** The members list can be sorted by name, status, roles, joined date, or updated date. Updated is the last profile change. Each admin’s sort is remembered on their account.
- **Feature:** `wp remember` can back up, restore, import, and export reMember data, sync QuickBooks or Xero payments, and scan for duplicate profiles. Commands run as the `--user` you pass, so the same access checks apply. Restore does nothing unless `--yes` is included. Import can `--dry-run`.
- **Feature:** A saved report can be emailed as a CSV on a daily, weekly, or monthly schedule. The file is built with the report owner's access and sent only to staff who can still run that report. An empty result can be skipped. A report with health or emergency fields is not emailed unless that is confirmed. Database 2.3.9.
- **Feature:** An event can add a check-in code to the admission ticket that already prints. It is off unless the event turns it on. Staff with Check In Attendees open Check-in, scan or type the code or search by name, and see whether the ticket is valid, already used, void, or for another event. The event participant CSV and application reports include the check-in time. Database 2.3.8.
- **Feature:** An event can email Event Administrators, or move the oldest waiting application for that role to pending, when an accepted spot is withdrawn or declined. The automatic choice runs only while registration is open and the role has room. It does not accept the application or create an invoice. Off leaves the waitlist for staff.
- **Feature:** Public registration can require Cloudflare Turnstile or hCaptcha. The secret key is encrypted and left out of backups. The same form allows 10 attempts from one IP address in an hour unless that number is changed. A site can also reject an email domain or add its own check before an account is created.
- **Feature:** Tools → Export Personal Data and Erase Personal Data include the reMember profile, health, emergency contact, applications, and survey answers. Erase removes those personal fields and the photo. Payment amounts and invoice identifiers stay. Staff note text is left out of the export and deleted on erase.
- **Feature:** A log records who views or exports health information or emergency contacts, including member screens, duplicate review, member and event CSVs, reports, and full backups. WordPress administrators can read it under Access log. Entries are kept for 12 months unless that is changed. The log is included in a full backup and does not contain the sensitive values themselves.
- **Security:** The readme states that one-click updates install the latest GitHub release zip with no signature check. The accounts that can publish a release are what keep that update safe.
- **Fix:** QuickBooks and Xero secrets are encrypted with a key that is not stored in the database. Existing connections are rewritten only after the new value decrypts to the same secret. If that check fails, the old key and the old values stay.
- **Fix:** A new profile photo is stored under a random file name. The original camera name is no longer part of the URL. Photos already on the site keep their current names.
- **Fix:** Registration checks the profile photo before it creates an account. A photo that is not a real image is rejected, and no account is left behind.
- **Fix:** A backup restore that fails removes WordPress users it just created. Accounts that were already on the site stay.
- **Fix:** Saving a profile rejects a time zone that is not a real zone. A blank time zone still defaults to America/Los_Angeles. Registration already rejected a bad zone.
- **Fix:** A member import skips a WordPress administrator unless the person importing is also an administrator. Their name is left as it is, and no member record is attached.
- **Fix:** A staff backup restore cannot add reMember capabilities that person cannot grant, and cannot newly assign a role that carries those capabilities. Assignments and capabilities already on the site stay. A WordPress administrator restore is unchanged.
- **Fix:** Member CSV imports and backup restores read the uploaded file in place. The file is no longer copied into the public uploads folder under its original name.
- **Fix:** A member, event, or location import that does not save is counted as an error and includes the database message. An update that changes nothing is still a success.
- **Fix:** Member import rejects a Status the member table cannot store before it creates a WordPress user. A blank Status still starts as pending vetting. `Vetted` is accepted as `vetted`.
- **Fix:** Re-importing members leaves profile columns alone when those columns are not in the file. A blank cell still clears that field. A new member still gets the usual empty defaults.
- **Enhancement:** The event screen lists each survey under Attendees. Fixed-choice questions show how many people picked each answer and that count's share of submitted responses.
- **Fix:** Report filters follow the field type. Choice fields, including survey role, question, survey kind, and timing, offer their lists. An answer filter uses the selected question: a dropdown for pick-one and yes/no, a multi-select for pick-several, and a text box for typed answers.
- **Enhancement:** An event can include one survey on the application, shown before agreements and hidden when the event has none. Follow-on surveys are issued separately to accepted participants. Survey answers are a Reports subject, and the event picker limits a run to one event. The Surveys screen lists existing surveys, with edit and delete on each row. A survey is not on the application unless that is turned on. Each survey has its own title and an optional instructions block above the questions. A survey can include any number of event roles. Leaving every role unchecked covers everyone on the event. A role sees an application survey that names it before the survey for everyone. Follow-on surveys go only to accepted participants in the selected roles. Database 2.3.0, 2.3.4.
- **Fix:** The allergy reaction explanation shows on the admin member edit form, directly under the allergy list, and on the member detail page whenever an allergy other than None is selected.
- **Enhancement:** A member can change their account email from their profile, and staff can change it on the admin member edit form. The address must be valid and unused. Saving a new address changes it immediately, updates the linked Xero contact or QuickBooks customer, and WordPress emails the previous address.
- **Enhancement:** Settings can send vetting emails to everyone with the Vetting role, or only the primary vetter and collaborators on that case. Assignment, scheduling, completion, and collaborator invitations use that choice. The member’s own accepted or rejected email is unchanged.
- **Enhancement:** After public registration, the new member is signed in, sees a short confirmation that the profile was received, and is sent to the membership dashboard.
- **Enhancement:** When Allergies is anything other than None, a required explanation of the reaction appears immediately after that field. None hides it and does not require it.

## 2.1.8

- **Fix:** Interests is posted as marker text with no angle brackets, then turned back into bold, italic, underline, lists, paragraphs, and line breaks in PHP. A host firewall that blocks HTML in the form body no longer rejects the profile save.

## 2.1.7

- **Fix:** Email links for `{profile_url}`, `{vetting_url}`, `{review_url}`, and `{ticket_url}` stay a single absolute URL. A rich-text link saved as `http://{profile_url}` no longer becomes `http://https//…`.
- **Fix:** “On Member Join” opens a vetting case when someone registers. A blank workflow setting no longer skips the case, and an older vetting table that still requires a vetter is updated so the case can be saved with nobody assigned.
- **Enhancement:** An event can optionally set when registration opens and closes. Blank means that side is unlimited, so existing events stay open. Members cannot apply outside the window. Times use the site timezone.
- **Fix:** Interests keeps only bold, italic, underline, lists, paragraphs, and line breaks. The editor drops every other tag when the field is left and again before the form is posted, so Word and other paste markup is not saved.

## 2.1.6

- **Enhancement:** Report filters on multi-select fields include **is not** and **is not one of**. That covers dietary restrictions, allergies, roles, and custom multi-select questions. A row matches when its list does not include the chosen value or values, including rows whose list is empty.
- **Fix:** Member dashboard **Browse Events** opens the Events page instead of reloading the dashboard.
- **Enhancement:** Settings can auto-apply a member’s open Xero credit notes to each new invoice before it is emailed. Oldest note first, up to the invoice total. If allocation fails, the invoice stays in Xero and is not emailed.
- **Enhancement:** Each open Xero credit note is a row on the member billing register and the admin billing register, including when the member has no invoice yet. Current Balance subtracts only the still-unallocated remainder. After the note is applied, that amount stays on the invoice and the open row drops off, so it is not counted twice.
- **Fix:** On the event application, “Check your profile…” opens in a new tab so the form stays put. Return to Dashboard and Cancel go to the member dashboard.
- **Fix:** Voiding a Xero invoice shows Xero’s validation reason. Credit already allocated to that invoice is removed first, so the credit note stays available and the invoice can be voided. An invoice with a payment is left alone, with a message to remove the payment or choose Refund.

## 2.1.5

- **Fix:** Interests keep bold, italic, underline, lists, and paragraphs. Paste from ChatGPT, Word, Google Docs, LibreOffice, Pages, and other editors is reduced to `b`, `em`, `u`, `ul`, `ol`, `li`, `p`, and `br` with no attributes, in the same block layout TinyMCE posts when that text is typed in the editor. The browser sends that HTML.

## 2.1.4

- **Fix:** The profile-photo framing circle on iPhone (and other WebKit) now shows the full photo at the chosen zoom, not a sliver in the corner. Save was already using the correct crop; the preview was clipped because the image was positioned with CSS `transform` inside a round `overflow: hidden` box.
- **Enhancement:** The Members list shows each member’s roles as badges (event roles in blue, system roles in amber), matching the profile.
- **Fix:** Narrow Members cards use both sides of the row: display name and legal name, then role badges, then phone and email, then status and “Joined:” with the date and View Profile on the right. The desktop table column order is unchanged.

## 2.1.3

- **Fix:** Interests keeps rich text but strips Word/Office junk (mso styles, `o:p`, comments) on paste and save so the POST is normal HTML.
- **Enhancement:** Notification emails send as HTML. Settings → Notifications uses a visual editor for each body; existing plain-text templates become paragraphs and URLs are clickable.

## 2.1.2

- **Fix:** Completing a vetting case now emails the member the result. Accepted uses **Member Vetted**; rejected uses a new **Member Rejected** template. Those templates existed but were never sent.
- **Enhancement:** reMember System Administrators are emailed on every new member (public registration, Add Member, and convert WordPress user). Settings → Notifications → General.
- **Fix:** Dashboard **Check again** drops the GitHub release cache so it talks to GitHub immediately. New GitHub releases are also overlaid on WordPress’s 12-hour plugin list (GitHub snapshot kept 15 minutes) so an update appears without waiting.

## 2.1.1

- **Fix:** Time zone pickers use a short city list (US first, then UK / France, then the rest of the world) instead of WordPress’s full IANA dump. Stored ids stay IANA; an already-saved zone that is not in the list still appears. Help text names the organization’s WordPress time zone and why picking your own matters. Closes [#38](https://github.com/ctucker1984/remember/issues/38).

## 2.1.0

- **Enhancement:** Staff-only **Reports** in wp-admin (`View Reports`). Pick a subject (members, applications, payments, vetting, events), columns, filters, and optional grouping/totals; preview runs over AJAX; CSV export is separate. Saved reports are per user. Field catalog and query compiler enforce the same read, attendees-only, emergency-contact, and health gates as member lists and CSV. Custom profile questions appear as columns on members and applications. Database 2.2.0.
- **Enhancement:** Reports builder uses labeled fields, a header Run control, and less nested boxing so the screen sits closer to the rest of wp-admin without a wall of fieldsets.
- **Enhancement:** Report filters use the field’s choice list for custom select/multi-select questions and for catalog fields (dietary, allergies, medical, roles, clothing sizes, IM type, event role, location).
- **Enhancement:** Multi-select custom field answers print as comma-separated option keys in the results grid and CSV, not as JSON arrays.
- **Enhancement:** Reports can be limited to one event at run time without saving that event on the report. Members and vetting use accepted participants; applications and payments use that event’s records.
- **Enhancement:** Copy a saved report into another staff member’s library. The recipient must have View Reports, the subject’s read cap, and emergency/health access if the report uses those fields. The copy is theirs; the run-time event is not copied.
- **Enhancement:** **My Reports** lists saved reports A–Z by name.
- **Enhancement:** Import/Export can download and restore a full JSON backup of plugin tables and settings (not WordPress users). Restore matches users by email or creates Subscribers with random passwords, so a backup can be loaded onto a fresh install. Restore is refused if this site’s plugin or database version is older than the backup. The CSV tools on that screen are condensed; column notes sit behind each card.
- **Security:** The JSON backup omits billing client secrets, OAuth tokens, and encryption keys. Payment rows keep invoice IDs rather than downloaded payment/refund lines; QuickBooks customer and Xero contact IDs travel in the user index so a restored site can reconnect and rematch.
- **Enhancement:** New profile photos and location logos are stored under `uploads/remember/photos/` and `uploads/remember/locations/` (still not Media Library attachments). Existing files stay where they are until replaced. Ticket logos stay in Media.
- **Enhancement:** Location logos can be chosen from the Media Library or uploaded as a new file. Clearing a library-picked logo does not delete the Media file.
- **Enhancement:** Plugins → Deactivate leaves reMember data in place and offers a JSON backup first. Plugins → Delete (only after deactivate) runs uninstall: tables, settings, logs, stored photos, setup pages, and reMember capabilities are removed; WordPress users are not.
- **Security:** Saving a role’s capabilities immediately recalculates WordPress caps for every member who holds that role. Previously those users kept the old caps until their profile was saved.

## 2.0.0

- **Enhancement:** Applying for an event first shows a dialog: review and save the profile (changes optional), then return to the application. The “my profile is current” confirmation remains required. Closes [#33](https://github.com/ctucker1984/remember/issues/33).
- **Enhancement:** Settings → Notifications uses sub-tabs (Vetting, Applications, Billing, General) instead of one long list.
- **Enhancement:** Staff can append profile notes on a member record, separate from vetting case notes. Notes are either visible to the member on their profile or private to wp-admin (not shown on the member front end). Confidential print includes them; the event card does not. Database 2.0.0. Closes [#34](https://github.com/ctucker1984/remember/issues/34).
- **Enhancement:** Daily duplicate-profile scan (legal names, location, display names, IM/social handles). City and state count as one location match so same-town pairs do not flood. Staff with **Merge Duplicate Profiles** get a review link (seeded to System Administrator; grant it on Roles; WordPress administrators keep full access). reMember System Administrators are emailed new hits; members are emailed only after a merge (no other-profile data). Admins pick field values and which roles survive (roles they cannot assign stay on the remaining profile only if it already had them). Emergency contact is hidden without Access Emergency Contact; the remaining profile keeps its existing values. The later-entered password is always kept; merged (locked) profiles cannot request a password reset. After merge, the discarded profile is locked out, leftover pending reviews of it are closed, and the merge can be undone (edits to the remaining profile after the merge are lost; members are not emailed). Pairs can be marked not-duplicates so they do not re-flag. Database 2.1.0–2.1.3. Closes [#35](https://github.com/ctucker1984/remember/issues/35).

## 1.4.0

- **Enhancement:** Clothing sizes keep the member’s actual size and show the inventory size that is available. Settings → Clothing has a write-in Stock table per category; **Available as** is filled from that, not the seeded body-size list. Dropdowns and staff views use labels like `XL (available L)`. Quantity is a later table related to Stock. Database 1.41.0. Closes [#31](https://github.com/ctucker1984/remember/issues/31).
- **Fix:** Hide the Bluehost plugin’s “Login with Bluehost” button on `wp-login.php` so members use username and password. Does not replace WordPress login or block Bluehost Account Manager → Log in to WordPress. Filter `remember_hide_hosting_sso` to keep the button.
- **Enhancement:** Nonced front-end Log out via Appearance → Menus (reMember box), a **Log out** block in the Site Editor Navigation, a Custom Link to `/remember-logout`, or `[remember_logout]`. The admin bar is hidden when the only WordPress role is Subscriber; other WP roles still see it. reMember roles do not affect the bar.
- **Enhancement:** Members can change their password on Edit Profile (current, new, confirm under Basic Information) with Save Profile. Leave the fields blank to keep the current password. Closes [#27](https://github.com/ctucker1984/remember/issues/27).
- **Security:** Event apply builds add-on names/descriptions and role labels as text, not HTML, so a product name cannot run markup in the member's browser. The dashboard already escaped these in PHP.
- **Security:** CSV exports prefix cells that start with `=`, `+`, `-`, or `@` so Excel/LibreOffice will not treat member-controlled text as a formula. Re-import strips that prefix so phones and IM handles round-trip.
- **Security:** Browser security headers on front, login, admin, and REST: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, HSTS on HTTPS, and a WordPress-compatible Content-Security-Policy. Filter `remember_security_headers` to tighten or disable. Does not replace headers the host already sends.
- **Security:** Unauthenticated `GET /wp-json/wp/v2/users` no longer returns login slugs. Editors/admins still can (block editor); everyone else gets 401.
- **Security:** XML-RPC stays available for Jetpack and the WordPress mobile app (`system.multicall` included). Pingback methods, the X-Pingback header, and open pings are removed (SSRF). Multicall brute-force is a server concern (fail2ban / rate-limit / WAF), not a plugin 403.
- **Security:** Debug logs no longer write to the public `/wp-content/debug.log` URL. reMember stores them in a web-blocked uploads directory, Apache/LiteSpeed deny HTTP GET of `debug.log`, and PHP’s error_log is redirected there when `WP_DEBUG_LOG` is the boolean default.
- **Enhancement:** Interests is limited to 2,000 characters (plain text) on registration, profile edit, and admin member edit, with a live counter. Closes [#29](https://github.com/ctucker1984/remember/issues/29).

## 1.3.7

- **Enhancement:** The event card printout uses a larger square photo at top left, with the display name and the first four opted-in custom fields in the column beside it. Remaining event-card fields continue in two columns under that row, then Interests.
- **Enhancement:** Assigned member numbers print on both sheets: under the display name on the confidential profile and the event card, and in the confidential footer.
- **Fix:** Member billing register follows Xero/QBO invoice totals: voided or deleted invoices are cancelled locally (zero balance, no ghost debit), deleted payments and credit notes drop off on sync, and remaining credits (Xero AmountCredited / QBO credit memos) are applied so Current Balance matches the provider.
- **Fix:** Opening a member profile always refreshes invoices, payments, and credits from Xero or QuickBooks (no 60-second cache).
- **Fix:** Settings → Xero shows **Reconnect Xero** while a connection is stored, and warns when the access token can no longer be refreshed. Previously only Disconnect was offered, so an expired token looked connected with no way to authorize again.
- **Fix:** Xero reconnect keeps the authorization error on the Xero tab (it was a 60-second flash notice), uses a stored OAuth state plus PKCE, and exchanges the code with the same redirect URI that started the flow so a completed reconnect actually replaces the tokens.
- **Fix:** Xero token exchange, refresh, and revoke send the same User-Agent as other Xero calls. identity.xero.com was returning HTTP 403 (Akamai) for WordPress’s default user-agent, which blocked reconnect and left the stored tokens expired. A failed reconnect notice is cleared once the live connection works again.

## 1.3.6

- **Enhancement:** Member profiles print in two formats, chosen from a **Print** menu on the profile. *Confidential profile* is the full staff record — identity, profile, emergency contact, and health on page one, interests and custom fields on page two — with red CONFIDENTIAL banners repeated on every page. *Event card* is meant for posting at an event: photo, display name, interests, and opted-in custom fields, with no contact details, address, roles, emergency contact, or health information. wp-admin chrome, action buttons, vetting cases, and the billing register stay off both. Save-as-PDF filenames use `{display_name}_member_full_profile_{yymmdd}.pdf` and `{display_name}_event_card_{yymmdd}.pdf`.
- **Enhancement:** Custom profile fields gain a **Show on event card** setting on the Custom Profile Fields screen, off by default, so a question like "What medications do you take?" never reaches a publicly posted card unless you opt it in. The flag round-trips through the custom fields CSV. Database 1.35.0.
- **Security:** Emergency contact and health information (dietary, allergies, medical) are gated behind `remember_access_emergency_contact` and `remember_access_health` (shown as Access Emergency Contact / Access Health Information). Without those caps, the fields are hidden on member view/edit, omitted from member and event CSV exports, and ignored on import and forged POSTs. Default grants: System Administrator and Vetting get both; Event Administrator gets health only (for event planning). Database 1.36.0; renamed from `remember_read_*` in 1.37.0.
- **Enhancement:** Role capability editing uses a module × Create/Read/Edit/Delete matrix, with settings, exports, emergency contact, and health listed under Other.
- **Security:** The member profile's billing register honors `remember_read_billing`. Staff without it (Vetting, for example) saw the full invoice, payment, and refund ledger with the running balance, even though the Billing screen was already hidden from them; the ledger is no longer built or rendered for them, and the QuickBooks/Xero sync buttons and their POST handlers now also require `remember_update_billing`. Vetting cases on the profile likewise require `remember_read_vetting`.
- **Security:** Privilege escalation hardening — role capability saves require `remember_update_roles` (the bare `update_capabilities` path had none), you can only grant caps you already hold, System Administrator cannot be deleted or edited except by a WordPress administrator, and member role assignment cannot add or remove roles whose caps you do not hold. Application billing mutations (invoice create on accept, reprocess, unwind/void) and payment UI require billing caps. Member CSV export respects attendees-only scoping. Mutation UI (Add Member, Accept/Decline, etc.) is hidden when the matching cap is missing.
- **Fix:** "None" sorts first in dietary, allergy, and medical accommodation checklists (profile, registration, and admin edit). Database 1.38.0.
- **Fix:** The member profile's top row sizes itself to the cards the viewer may see. Staff without emergency contact or health access no longer get a third-width profile card with empty space beside it.
- **Fix:** The confidential printout no longer strands the dietary/allergy/medical cards below the profile card with a dead gap after Emergency Contact. Emergency contact and health now share one column that stacks tightly beside the profile (the old layout leaned on a grid row span that collapses without explicit rows). The event card is unaffected.
- **Security:** Member profile printing is gated by `remember_print_confidential` and `remember_print_event_card` (Print Confidential Profile / Print Event Card). Without the matching cap the Print control is hidden, Ctrl/Cmd+P yields a permission notice instead of the sheet, and each format is offered only when allowed. Seeded to System Administrator and Event Administrator (not Vetting). Database 1.39.0.
- **Fix:** Settings → Plugin Version reports the installed files (`REMEMBER_VERSION`), and the stored `remember_version` option is synced on admin load after Upload → Replace. Silent reactivation was leaving the option on the previous release.

## 1.3.5

- **Enhancement:** Updates arrive in the WordPress dashboard. reMember checks GitHub Releases and offers the packaged `remember-x.y.z.zip`, so Plugins → Update works like any other plugin (requires WordPress 5.8+).
- **Fix:** Every rich-text field is Visual-only — the Visual/Code switcher is gone from events, locations, agreements, member edit, registration, and profile.

## 1.3.4

- **Enhancement:** Admin-assignable unique alphanumeric member number; members see it read-only. Closes [#21](https://github.com/ctucker1984/remember/issues/21).
- **Enhancement:** Event apply and dashboard application edits require profile-currency confirmation (phrase + profile saved within 24 hours); profile `updated_by` audit fields. Closes [#22](https://github.com/ctucker1984/remember/issues/22).
- **Fix:** Interests editors (profile, registration, admin) show Visual only — Code tab hidden.
- **Fix:** Long profile edit forms no longer clip custom fields or force horizontal overflow.
- **Fix:** Upload → Replace no longer fails on git/dev trees (`.git`, `dist`, etc.): deactivate earlier, purge non-release paths, chmod writable, retry clear when WordPress reports `files_not_writable`.

## 1.3.3

- **Security:** Attendees-only staff can no longer open arbitrary member profiles via `?view=` ID; detail and POST actions use the same shared-event scope as the list.
- **Security:** Setup wizard, Settings, and Products mutations re-check `remember_access_settings` (not nonce alone).
- **Fix:** Applications-only staff can open the admin dashboard (menu and page caps aligned).
- **Fix:** Event-role AJAX uses separate admin/front nonces; no longer trusts Referer or `manage_options` for the full role list.
- **Fix:** Staff ticket viewing uses `remember_read_applications` (replaced undefined `remember_view_applications`).
- **Enhancement:** Admin-managed Instant Messenger platforms (DB 1.32.0); Social Media + IM combined in a two-column **Platforms** settings tab. Closes [#20](https://github.com/ctucker1984/remember/issues/20).

## 1.3.2

- **Fix:** Country is required on registration and profile (defaults to US). Closes [#17](https://github.com/ctucker1984/remember/issues/17).
- **Enhancement:** Interests prompt asks what the member wants from the event. Closes [#16](https://github.com/ctucker1984/remember/issues/16).
- **Fix:** Time zone starts empty at registration and opens unfiltered; clearer importance copy. Closes [#13](https://github.com/ctucker1984/remember/issues/13).
- **Enhancement:** Dietary, medical, and allergy catalogs include None and require a response. Closes [#15](https://github.com/ctucker1984/remember/issues/15).
- **Enhancement:** Expanded allergies seed list (incl. pomegranate, grapefruit). Closes [#19](https://github.com/ctucker1984/remember/issues/19).
- **Enhancement:** Expanded dietary restrictions and medical accommodations for event planning coverage.
- **Enhancement:** Privacy copy explains sharing and encourages photo + IM for networking. Closes [#18](https://github.com/ctucker1984/remember/issues/18).
- **Enhancement:** Profile photo required at registration. Closes [#14](https://github.com/ctucker1984/remember/issues/14).
- **Enhancement:** Custom profile fields can be required only when an earlier pick-one / pick-several field matches chosen values. Closes [#11](https://github.com/ctucker1984/remember/issues/11).
- **Fix:** Load `dbDelta` before admin schema migrations so upgrades are not stalled mid-chain.
- **Fix:** Admin mobile — vetting, applications, billing, and related tables stack into labeled cards. Closes [#8](https://github.com/ctucker1984/remember/issues/8), [#9](https://github.com/ctucker1984/remember/issues/9), [#10](https://github.com/ctucker1984/remember/issues/10).
- **Chore:** Plugin header `Update URI` points at the GitHub repository.

## 1.3.1

- **Fix:** Custom fields on registration/profile no longer collide with the two-column register layout. Closes [#6](https://github.com/ctucker1984/remember/issues/6).
- **Fix:** Admin mobile layout — member/event headers stack; wide tables scroll on small screens. Closes [#7](https://github.com/ctucker1984/remember/issues/7).
- **Docs:** Slim root README; remove planning markdown from the release tree.

## 1.3.0

- Upload → Replace safely deactivates/reactivates the plugin during zip upgrades.
- Custom profile fields (text / single / multi-select); registration and profile.
- Agreements library with pinned event revisions and typed legal-name acknowledgement on apply.
- Allow reapply for declined/cancelled applications.
- Leaner admin member-edit required fields.
- Event participant CSV export (accepted applications).
- Import/Export capability; richer member CSV; custom fields definition import/export.

## 1.2.0

- Printable admission tickets and receipts; email on accept / paid / balance due.
- Rich text for locations, event description, Interests; registration/profile polish; per–role add-on max qty.
- Billing: email Xero/QBO invoice on accept; credit-note sync; release zip packaging (`remember/` root folder).

## 1.1.x

- Registration and admin photo cropper; Xero customer invoice links on the member register; safer payment sync; release packaging fixes.

## 1.1.0

- Billing provider selector (none / QuickBooks / Xero); full Xero path parallel to QuickBooks.

## 1.0.x

- Baseline: members, events, applications, vetting, QBO billing, roles, locations, products, shortcodes; display-name / privacy / convert-WP-user refinements in 1.0.1.
