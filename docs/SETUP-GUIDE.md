# Sheets Sync Manager for Google — Complete Setup Guide (v1.0.0)

This guide explains exactly where to get the Google keys and how to connect the plugin. You do **not** need a Service Account JSON private key. This plugin uses OAuth 2.0 with a **Web application** OAuth client.

The same steps are also shown on **Sheets Sync → Connect Google & Sheet** in WordPress.

## Before you start

- Use a WordPress administrator account.
- Your website should load consistently on HTTPS (do not mix `www` and non-`www`).
- The Google account you connect must have **Editor** access to the target spreadsheet.
- Back up your website before replacing an earlier plugin version.

## Important: install the correct plugin folder

The ZIP must install to `wp-content/plugins/sheets-sync-manager-for-google/`. If an earlier build created a separate folder, WordPress may still be running the old copy. Back up first, deactivate all copies of this plugin, remove the obsolete plugin folders through WordPress or File Manager, then install this ZIP. Activate only **one** copy. Check Plugins to confirm the version is **1.0.0**.

## Step 1 — Create or select a Google Cloud project

1. Open https://console.cloud.google.com/ and sign in with the Google account you use to manage the integration.
2. Use the project selector at the top to select an existing project or click **New Project**.
3. Give the project a clear name such as `WordPress Sheets Sync` and create it.
4. Ensure this project is selected for the next steps.

## Step 2 — Enable Google Sheets API

1. Open https://console.cloud.google.com/apis/library/sheets.googleapis.com.
2. Confirm the correct project is selected.
3. Click **Enable**. If it already says Manage, it is enabled.

## Step 3 — Configure the OAuth consent screen

Google's UI may label this **Google Auth Platform** or **OAuth consent screen**.

1. Open https://console.cloud.google.com/auth/overview (or search for “Google Auth Platform” in the Cloud Console).
2. Configure the app name, support email, and developer contact email.
3. Choose **External** if the Google account connecting the plugin is not part of your Google Workspace organization. Choose **Internal** only when all users are in the same Workspace organization and that option is available.
4. If the app is in **Testing** mode, open **Audience** / **Test users** and add the exact Google account you will connect in WordPress.
5. Save the settings.

Google may show an unverified-app warning for an app that has not gone through verification. Only continue if you control the Cloud project and trust the app you configured.

## Step 4 — Create the OAuth Client ID and Secret

1. Open https://console.cloud.google.com/auth/clients (or APIs & Services → Credentials).
2. Click **Create Client** / **Create Credentials → OAuth client ID**.
3. Choose **Web application** as the application type. Do not choose Desktop app, Android, iOS, or Service account.
4. Enter a name, e.g. `WordPress production site`.
5. Keep this browser tab open. In WordPress, open **Sheets Sync → Connect Google & Sheet**.
6. Copy the exact **Authorized redirect URI** displayed by the plugin. It looks like `https://YOUR-DOMAIN/wp-admin/admin-post.php?action=wpgss_oauth_callback`.
7. In Google Cloud, under **Authorized redirect URIs**, click **Add URI** and paste the exact value. It must match scheme (`https`), hostname (`www` or no `www`), path, and query string exactly. Do not put it under Authorized JavaScript origins.
8. Create the client. Copy the **Client ID** and **Client Secret** shown by Google. If Google lets you download a JSON file, you may keep it as a private backup; do not upload or share it publicly. This plugin asks for the Client ID and Client Secret values (`web.client_id` / `web.client_secret`).

## Step 5 — Save keys in WordPress

1. In WordPress Admin, open **Sheets Sync → Connect Google & Sheet**.
2. Paste the full OAuth **Client ID** (usually ends in `.apps.googleusercontent.com`).
3. Paste the OAuth **Client Secret**. Click **Save OAuth settings**.
4. Copy the redirect URI from this page again and compare it character-for-character with the URI in Google Cloud.
5. Click **Connect Google account** and choose the Google account that has Editor access to the sheet.
6. Accept the requested spreadsheet access.
7. Return to WordPress. The connection should show the connected Google account and a saved refresh token.

### If Google says `redirect_uri_mismatch`

The redirect URI in Google Cloud is not an exact match. Copy the URI from the plugin page again. Check for `http` vs `https`, `www` vs non-`www`, staging vs production domain, and trailing characters. Save the Google Cloud client and wait a minute before retrying.

### If the plugin says no token was saved

1. In WordPress Plugins, verify only one copy is active and its version is 1.0.0.
2. Start the connection from the same website hostname you use in WordPress Settings → General. Do not start at `www.example.com` and return to `example.com` or vice versa.
3. Confirm the connected Google account is listed as a Test user if the OAuth app is in Testing mode.
4. Reconnect. If Google authorized successfully but no refresh token is returned, remove the app's prior grant from https://myaccount.google.com/connections and connect again.
5. Open **Sheets Sync → Diagnostics** and read **Recent diagnostic events**.
6. If the error says the database option could not be saved, ask hosting support to check WordPress database write errors and any object-cache/security plugin that filters or blocks `wpgss_settings` options.

## Step 6 — Choose your spreadsheet

1. Open the spreadsheet in Google Sheets.
2. Copy the full URL, for example `https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit`.
3. Paste the URL or ID into **Google Sheets URL or ID** and click **Save spreadsheet**.
4. Click **Test connection**. A successful result confirms that the Google account can access this spreadsheet through the API.
5. Click **Load worksheet tabs** and select the destination tab for each supported integration.

If the test returns HTTP 403, confirm that the Google Sheets API is enabled and the connected Google account has Editor access. If it returns HTTP 404, check the spreadsheet ID and account access. If it returns HTTP 401, reconnect Google.

## Step 7 — Enable sync sources and test

1. Open **What to sync**.
2. Enable only the integrations you need and save.
3. Send one test Elementor Pro Form or Contact Form 7 submission, or create a test WooCommerce order.
4. Open **Activity & tools** and **Diagnostics** to review the queue and error messages.
5. Use **Process queue now** to trigger queued jobs manually if needed.

This release supports Elementor Pro Forms, Contact Form 7, WooCommerce orders/order items, and registered customer updates. WPForms, Fluent Forms, Gravity Forms, complete custom field mapping, and historical form-entry import are not implemented in this build.

## Security and privacy

- Never share the Client Secret, access token, refresh token, or downloaded credentials JSON publicly.
- Restrict WordPress administrator access and use HTTPS.
- Export only data you have permission to transfer to Google Sheets.
- Use staging for first-time tests. Keep backups before replacing plugin versions.
- Deactivation is intended to retain settings, logs, and queued records. Still test in staging before production.

## What the plugin can and cannot guarantee

The package can validate its PHP syntax and archive structure locally, but it cannot validate your live Google Cloud project, hosting database, firewall, WordPress cache, or Google account permissions from this development environment. A successful connection must be confirmed with the plugin's **Test connection** action on your site.
