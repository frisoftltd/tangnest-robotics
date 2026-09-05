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
