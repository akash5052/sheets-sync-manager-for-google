# Sheets Sync Manager for Google v1.0.0

A WordPress plugin for synchronizing supported form submissions and WooCommerce records to Google Sheets through Google OAuth 2.0.

## Read first

**Start with `docs/SETUP-GUIDE.md`.** It explains where to create the Google Cloud project, enable the Sheets API, configure the OAuth consent screen, create the Web application Client ID/Secret, add the exact redirect URI, connect the Google account, and troubleshoot errors.

## Current supported workflows

- Google OAuth 2.0 (Web application client ID + client secret; no service-account private key required).
- Google Sheets API connection test and worksheet discovery.
- Elementor Pro Forms and Contact Form 7 submissions.
- WooCommerce orders, order items, status updates, and registered customer updates.
- Background queue, retry handling, activity/diagnostics view, and manual queue processing.
- Source-key-based updates for supported WooCommerce records and formula-injection mitigation.

## Installation

1. Back up the website/database.
2. Deactivate and remove any older copies of this plugin. Ensure only one plugin folder named `sheets-sync-manager-for-google` is installed and active.
3. Upload this ZIP in **Plugins → Add New Plugin → Upload Plugin**.
4. Confirm the version shown in WordPress is **1.0.0**.
5. Follow `docs/SETUP-GUIDE.md`.

## Limitations

WPForms, Fluent Forms, Gravity Forms, full custom field mapping, and historical form-entry imports are not implemented. Live OAuth and Google Sheets API testing must be performed on the target site.
