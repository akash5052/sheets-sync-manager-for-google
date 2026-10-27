=== Sheets Sync Manager for Google ===
Contributors: akash5052
Tags: google sheets, elementor, contact form 7, woocommerce, sync
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Google Sheets through OAuth and synchronize supported Elementor Pro Forms, Contact Form 7, and WooCommerce records.

== Description ==

Sheets Sync Manager for Google connects WordPress to Google Sheets using OAuth 2.0 and keeps supported form and WooCommerce data in sync with your selected spreadsheet worksheets.

== Installation ==
1. Upload the ZIP from Plugins > Add New Plugin > Upload Plugin.
2. Activate and open Sheets Sync in the WordPress admin.
3. Follow docs/SETUP-GUIDE.md to configure Google OAuth and select worksheet tabs.

== Supported integrations ==
Elementor Pro Forms, Contact Form 7, WooCommerce orders, order items, and registered customers.

WPForms, Fluent Forms, Gravity Forms, custom field mapping, and historical form-entry imports are not implemented in this release.

== Changelog ==

= 1.0.0 =
* Initial release.
* Google OAuth 2.0 connection (Web application Client ID and Secret).
* Sync Elementor Pro Forms, Contact Form 7, WooCommerce orders, order items, and registered customers.
* Background queue, retries, activity log, and diagnostics.
* On-screen setup guide for creating OAuth credentials in Google Cloud.
