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

### 0.1.0
- Initial skeleton: plugin bootstrap, constants, logger, DB schema for families/students/programs/enrollments.
