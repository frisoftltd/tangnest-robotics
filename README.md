# Tangnest Robotics — Class & Payment Manager

A standalone WordPress plugin that manages physical robotics classes at Tangnest: which children are enrolled, how much their family owes each month, whether they paid, and who is behind. Payments go through IremboPay.

It is **not** a WooCommerce extension and **not** a Tutor LMS extension — it runs on a site where neither is installed.

## Status

v0.1.0 — skeleton. Plugin activates, defines its constants, and creates its four core tables (`wp_tr_families`, `wp_tr_students`, `wp_tr_programs`, `wp_tr_enrollments`) via a version-checked bootstrap that runs on both activation and `plugins_loaded` (deployments here are file copies, which don't fire activation hooks).

See the project spec for the full data model, feature set, and build order.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Changelog

### v0.8.0
- Changed: Families now select a single package that sets their price, product code and duration
- Changed: Programs renamed to Packages, with a notes field for distinguishing price tiers
- Removed: Per-child program fees and the custom family bundle override
- Changed: Course progress is now tracked per family, since siblings finish together
- New: Delete actions for packages and families, blocked where records depend on them

### v0.7.1
- Fix: IremboPay payments were never marked paid — the merchant account's one callback URL is owned by the WooCommerce IremboPay plugin and cannot be changed, so our webhook never arrived
- New: intercepts that shared webhook route, claims only payloads matching one of our own invoices, and leaves everything else completely untouched for the WooCommerce plugin — LMS course payments are unaffected
- New: admin warning when this plugin's webhook secret is set but the WooCommerce plugin's is empty, since IremboPay then sends no signature and every webhook would otherwise be rejected

### v0.7.0
- Fix: Pay now and payment schedule links in emails now sign the parent in automatically
- New: Emails (and the routine WhatsApp payment reminder) carry their own message token, independent of the WhatsApp access link
- New: Message links are valid for 14 days and refresh with every automatic send
- New: Families screen shows the access link and message link statuses separately

### v0.6.2
- New: Delete row action and bulk action on the Invoices screen, restricted to cancelled invoices only — never available on pending, overdue, paid or waived invoices, re-checked server-side regardless of what was rendered
- Every deletion is logged at info level with the invoice ID, family ID, period, amount and the admin who did it

### v0.6.1
- Fix: the "Create new parent" fields on the family edit screen were visible (and appeared editable) even while "Use existing WordPress user" was selected
- New: editable Email, First name and Last name for the linked existing parent, pre-filled from the WordPress user record — updates the WP user and the plugin's parent_name/parent_email meta on save, with a collision check against other users' emails
- Every parent email change is logged at info level with the family ID and both addresses

### v0.6.0
- Changed: Monthly fees are now set on the program, not per family
- New: Family amounts calculate automatically from enrolled children
- New: Bundle override for families with a negotiated total
- New: Add several children to a family in one screen
- New: Warnings when a program has no IremboPay product code
- Fix: Removed the confusing amount field from the student form

### v0.5.1
- Fix: the v0.3.6 diagnostic debug logging fired on every front-end page load site-wide (not just the dashboard), even with no access token present — now only logs when a token is actually in the request
- New: "Debug logging" toggle in Robotics → Settings, off by default — gates only debug-level log lines; info, warning and error lines always write
- New: daily log rotation deletes log files older than 30 days

### v0.5.0
- New: Parents can pay their invoice online with IremboPay, from their dashboard or straight from an email — the invoice marks itself paid automatically, no admin touch required
- New: IremboPay settings under Robotics → Settings (keys, payment account, expiry, webhook secret, master on/off toggle)
- New: Webhook endpoint reconciles payment status with idempotent handling — a retried delivery never double-counts a payment
- New: "Check payment status" row action on the Invoices screen as a manual fallback if a webhook is ever missed
- New: Invoices screen shows the IremboPay invoice number and transaction ID for online payments
- Manual recording (cash, bank, mobile money) is unchanged — this adds a payment route, it does not replace one

### v0.4.1
- Fix: Invoice summary totals now reflect the filtered view correctly
- Fix: Invoice rows were missing their actions
- New: Row actions always visible and restyled for readability
- New: Parents are emailed automatically when an invoice is created
- New: Automatic payment reminders before and after the due date, configurable
- New: Manual email and WhatsApp reminder actions on every invoice
- Fix: WhatsApp actions open in a new tab instead of navigating away from admin

### v0.4.0
- New: Monthly invoices generated automatically on each family's billing day
- New: Invoices admin screen with status filters and collection summary
- New: Record cash, bank and mobile money payments
- New: Parents see their payment schedule and history on their dashboard

### v0.3.6
- Diagnostic: full logging of every access-link rejection path
- Fix: log timestamps now use site (Kigali) time via `current_time( 'mysql' )` instead of UTC, so log lines and DB rows can be compared directly

### v0.3.5
- Fix: WhatsApp access-link messages ran together with no line breaks — `wp_redirect()` sanitizes the Location header and strips every `%0d`/`%0a` as an HTTP response-splitting defense, silently deleting the message's line breaks after they were correctly assembled. The WhatsApp send now issues a raw `Location` header instead, bypassing that sanitizer for this fully self-constructed URL
- Fix: the "this link is no longer active" notice now expires after 60 seconds (was 2 minutes) as a backstop, on top of the existing delete-on-read; confirmed the v0.3.4 WhatsApp-in-app-browser fix is intact in the working tree

### v0.3.4
- Fix: the v0.3.3 link-previewer filter was blocking real parents — WhatsApp's in-app browser sends a User-Agent containing "WhatsApp/", which matched the crawler check and silently dropped genuine taps. The filter now also requires the absence of "Mozilla" (real browsers, including in-app browsers, always send it; bare server-side preview fetchers never do), so it catches actual crawlers without catching real visits
- Every skipped previewer request is now debug-logged with method and User-Agent

### v0.3.3
- Fix: access-link generation verifies a reused cached token against the family's stored hash before handing it out, and mints a fresh token on any mismatch — closes a real failure mode where a stale pre-0.3.2 token cache (7-day TTL) could survive alongside a newer, different token
- Fix: one-time cleanup on upgrade removes any leftover `tr_access_raw_*` transients from before 0.3.2
- Hardening (not the cause of the above): link-preview crawlers (WhatsApp, Facebook, Twitter, Slack, Telegram) and HEAD requests no longer touch or consume access tokens; device-slot increment now only happens after the auth cookie is actually set; every token validation attempt is debug-logged with User-Agent and method

### v0.3.2
- Fix: sending an access link over a second channel no longer invalidates the one just sent — links are reused while still usable, with an explicit "Regenerate link" action to force a fresh one
- Fix: WhatsApp access-link sends are now logged (success and failure), matching the email channel
- Fix: the raw-token cache used for reuse is capped to the grace window (was 7 days) and cleared as soon as a token is consumed

### v0.3.1
- New: Passwordless parent access via a private link, sendable over WhatsApp
- New: Links bind to the devices that open them and stop working afterwards
- New: One-click resend, copy and revoke actions on the Families screen
- New: Link status column showing whether a parent has opened their link

### v0.3.0
- New: Parents receive a welcome email with a secure set-password link when registered
- New: Parent dashboard showing their children and course progress
- New: Robotics → Settings page for choosing the dashboard page
- New: Resend welcome email and WhatsApp row actions on the Families list

### v0.2.0
- New: Programs, Families, Students and Enrollments admin screens
- New: Family billing anchor set from the first child's enrollment date
- New: Admin notice when family composition changes and the monthly amount needs review

### v0.1.1
- New: GitHub Actions workflow builds the plugin zip on every `v*` tag
- New: Self-updater checks GitHub Releases and shows updates in WordPress
- New: "Check for Updates" link on the Plugins screen

### 0.1.0
- Initial skeleton: plugin bootstrap, constants, logger, DB schema for families/students/programs/enrollments.
