<?php
if (!defined('ABSPATH')) { exit; }

/** Friendly WordPress admin UI and OAuth flow. */
class WPGSS_Admin {
    private static $instance;
    public static function instance() { if (!self::$instance) { self::$instance = new self(); } return self::$instance; }
    private function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        foreach (array('wpgss_oauth_start','wpgss_oauth_callback','wpgss_test_connection','wpgss_load_sheets','wpgss_sync_now','wpgss_retry_failed','wpgss_import_orders','wpgss_disconnect') as $action) {
            add_action('admin_post_'.$action, array($this, $action));
        }
        // Google may return without the WordPress login cookie. Verify one-time state before accepting callback.
        add_action('admin_post_nopriv_wpgss_oauth_callback', array($this, 'wpgss_oauth_callback'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }
    public function menu() { add_menu_page('Sheets Sync','Sheets Sync','manage_options','wpgss-sync',array($this,'page'),'dashicons-media-spreadsheet',56); }
    public function assets($hook) {
        if ($hook !== 'toplevel_page_wpgss-sync') return;
        wp_register_style('wpgss-admin', false, array(), WPGSS_VERSION); wp_enqueue_style('wpgss-admin'); wp_add_inline_style('wpgss-admin',$this->css());
    }
    public function register_settings() { register_setting('wpgss_settings_group','wpgss_settings',array($this,'sanitize_settings')); }
    public function sanitize_settings($input) {
        $old = get_option('wpgss_settings', array()); if (!is_array($old)) $old = array(); if (!is_array($input)) $input = array(); $out = array_merge($old,$input);
        $raw = trim(sanitize_text_field($input['spreadsheet_id'] ?? ($old['spreadsheet_id'] ?? '')));
        if (preg_match('~spreadsheets/d/([a-zA-Z0-9_-]+)~',$raw,$m)) $raw = $m[1];
        $out['spreadsheet_id'] = preg_replace('/[^a-zA-Z0-9_-]/','',$raw);
        $out['oauth_client_id'] = sanitize_text_field($input['oauth_client_id'] ?? ($old['oauth_client_id'] ?? ''));
        $secret = isset($input['oauth_client_secret']) ? trim(wp_unslash($input['oauth_client_secret'])) : '';
        $out['oauth_client_secret'] = $secret !== '' ? sanitize_text_field($secret) : ($old['oauth_client_secret'] ?? '');
        $credentials_changed = (!empty($old['oauth_client_id']) && $old['oauth_client_id'] !== $out['oauth_client_id']) || ($secret !== '' && !empty($old['oauth_client_secret']) && $old['oauth_client_secret'] !== $out['oauth_client_secret']);
        foreach (array('elementor_enabled','cf7_enabled','woocommerce_orders_enabled','woocommerce_customers_enabled') as $key) { if (!empty($input['wpgss_integrations_form'])) { $out[$key] = !empty($input[$key]) ? 1 : 0; } elseif (array_key_exists($key, $input)) { $out[$key] = !empty($input[$key]) ? 1 : 0; } else { $out[$key] = !empty($old[$key]) ? 1 : 0; } }
        foreach (array('forms_sheet'=>'Contact Forms','orders_sheet'=>'Orders','items_sheet'=>'Order Items','customers_sheet'=>'Customers') as $key=>$default) $out[$key] = sanitize_text_field($input[$key] ?? ($old[$key] ?? $default));
        $out['worksheet_names'] = isset($old['worksheet_names']) && is_array($old['worksheet_names']) ? $old['worksheet_names'] : array();
        $out['max_retries'] = min(10,max(1,absint($input['max_retries'] ?? ($old['max_retries'] ?? 5))));
        // OAuth tokens and account details are managed only by the OAuth callback.
        $out['oauth_refresh_token'] = $credentials_changed ? '' : ($old['oauth_refresh_token'] ?? '');
        $out['oauth_access_token'] = $credentials_changed ? '' : ($old['oauth_access_token'] ?? '');
        $out['oauth_token_expires_at'] = $credentials_changed ? 0 : absint($old['oauth_token_expires_at'] ?? 0);
        $out['google_email'] = $credentials_changed ? '' : ($old['google_email'] ?? '');
        $out['oauth_last_error'] = $credentials_changed ? '' : sanitize_text_field($old['oauth_last_error'] ?? '');
        return $out;
    }
    private function settings() {
        $s = get_option('wpgss_settings', array());
        if (!is_array($s)) { $s = array(); }
        // OAuth values are also stored as independent options. This avoids a third-party
        // filter or stale cached aggregate option hiding tokens after Google's callback.
        foreach (array('oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email') as $key) {
            $v = get_option('wpgss_' . $key, null);
            if ($v !== null && $v !== '') { $s[$key] = $v; }
        }
        return $s;
    }
    private function save_oauth_settings($settings) {
        $settings = is_array($settings) ? $settings : array();
        $token_keys = array('oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email');
        foreach ($token_keys as $key) {
            if (array_key_exists($key, $settings)) {
                $value = $settings[$key];
                if ($key === 'oauth_token_expires_at') { $value = absint($value); }
                // update_option() returns false when the value is unchanged; read-back is authoritative.
                update_option('wpgss_' . $key, $value, false);
            }
        }
        // Keep the aggregate option in sync, but independent token options are authoritative.
        update_option('wpgss_settings', $settings, false);
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        foreach (array('oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email') as $key) {
            wp_cache_delete('wpgss_' . $key, 'options');
        }
        $ok = true;
        foreach (array('oauth_access_token','oauth_token_expires_at') as $key) {
            $expected = $settings[$key] ?? '';
            $actual = get_option('wpgss_' . $key, null);
            if ($actual === null || (string) $actual !== (string) $expected) { $ok = false; }
        }
        return $ok;
    }
    private function notice() {
        $key = 'wpgss_admin_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return;
        }
        delete_transient( $key );
        $type = ( ( $notice['type'] ?? '' ) === 'error' ) ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( (string) $notice['message'] ) ) . '</p></div>';
    }
    private function redirect_notice($message,$type='success',$tab='connect') {
        set_transient(
            'wpgss_admin_notice_' . get_current_user_id(),
            array(
                'message' => sanitize_text_field( (string) $message ),
                'type'    => ( $type === 'error' ) ? 'error' : 'success',
            ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( add_query_arg( array( 'page' => 'wpgss-sync', 'tab' => sanitize_key( $tab ) ), admin_url( 'admin.php' ) ) );
        exit;
    }
    private function action_button($action,$label,$class='button') { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wpgss-inline-form">'; wp_nonce_field($action); echo '<input type="hidden" name="action" value="'.esc_attr($action).'"><button class="button '.esc_attr($class).'">'.esc_html($label).'</button></form>'; }
    /** Fixed custom-table name derived only from the trusted $wpdb prefix. */
    private function queue_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wpgss_queue';
    }
    private function bust_queue_count_cache() {
        foreach ( array( 'pending', 'processing', 'completed', 'failed', 'retry' ) as $status ) {
            wp_cache_delete( 'wpgss_queue_count_' . $status, 'wpgss' );
        }
    }
    public function page() {
        if (!current_user_can('manage_options')) return;
        $s=$this->settings();
        // Display-only tab switch; capability checked above. No form mutation occurs here.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
        if (!in_array($tab,array('dashboard','connect','integrations','activity','diagnostics'),true)) $tab='dashboard';
        $connected = !empty($s['oauth_refresh_token']) || (!empty($s['oauth_access_token']) && !empty($s['oauth_token_expires_at']) && (int) $s['oauth_token_expires_at'] > time() + 60); $sheet_ready=!empty($s['spreadsheet_id']);
        global $wpdb; $table=$this->queue_table_name(); $counts=array();
        foreach ( array( 'pending', 'processing', 'completed', 'failed', 'retry' ) as $status ) {
            $cache_key = 'wpgss_queue_count_' . $status;
            $count     = wp_cache_get( $cache_key, 'wpgss' );
            if ( false === $count ) {
                // Custom plugin queue table — not available via WP APIs. %i is the identifier placeholder (WP 6.2+).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status ) );
                wp_cache_set( $cache_key, $count, 'wpgss', 30 );
            }
            $counts[ $status ] = (int) $count;
        }
        $logs=get_option('wpgss_logs',array());
        echo '<div class="wrap wpgss"><header class="wpgss-hero"><div><span class="wpgss-kicker">WORDPRESS INTEGRATION</span><h1>Sheets Sync for Google</h1><p>Connect once, choose what to send, and let your website keep your spreadsheet updated.</p></div><span class="dashicons dashicons-media-spreadsheet"></span></header>';
        $this->notice(); echo '<nav class="wpgss-nav">'; foreach(array('dashboard'=>'Home','connect'=>'Connect Google & Sheet','integrations'=>'What to sync','activity'=>'Activity & tools','diagnostics'=>'Diagnostics') as $key=>$label) echo '<a class="'.($tab===$key?'active':'').'" href="'.esc_url(add_query_arg(array('page'=>'wpgss-sync','tab'=>$key),admin_url('admin.php'))).'">'.esc_html($label).'</a>'; echo '</nav>';
        if ($tab==='dashboard') {
            echo '<div class="wpgss-grid"><main><section class="wpgss-card"><h2>Setup checklist</h2><p>Follow these steps in order. You only need to connect your account once.</p>';
            $this->check_step('1','Connect Google account',$connected?'Connected'.(!empty($s['google_email'])?' as '.$s['google_email']:''):'Authorize access to Google Sheets',$connected,admin_url('admin.php?page=wpgss-sync&tab=connect'));
            $this->check_step('2','Choose your spreadsheet',$sheet_ready?'Spreadsheet ID saved':'Paste your spreadsheet URL or ID',$sheet_ready,admin_url('admin.php?page=wpgss-sync&tab=connect'));
            $this->check_step('3','Choose worksheet tabs',!empty($s['worksheet_names'])?'Worksheets loaded':'Load worksheets and select destinations',!empty($s['worksheet_names']),admin_url('admin.php?page=wpgss-sync&tab=connect'));
            $this->check_step('4','Enable integrations','Select the data sources you want to sync',!empty($s['elementor_enabled'])||!empty($s['cf7_enabled'])||!empty($s['woocommerce_orders_enabled'])||!empty($s['woocommerce_customers_enabled']),admin_url('admin.php?page=wpgss-sync&tab=integrations'));
            echo '</section><section class="wpgss-card"><div class="wpgss-card-head"><div><h2>Sync activity</h2><p>Queue status for your latest records.</p></div><a href="'.esc_url(admin_url('admin.php?page=wpgss-sync&tab=activity')).'">View activity →</a></div><div class="wpgss-stats">'; foreach(array('pending'=>'Waiting','processing'=>'In progress','completed'=>'Synced','failed'=>'Failed') as $key=>$label) echo '<div><strong>'.esc_html($counts[$key]).'</strong><span>'.esc_html($label).'</span></div>'; echo '</div></section></main><aside><section class="wpgss-card"><h2>Connection status</h2><p><span class="wpgss-dot '.($connected&&$sheet_ready?'good':'').' "></span><strong>'.($connected&&$sheet_ready?'Credentials saved':'Setup incomplete').'</strong></p><p class="wpgss-muted">'.($connected&&$sheet_ready?'Run a connection test to confirm live access.':'Connect Google and select the spreadsheet you want to use.').'</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=wpgss-sync&tab=connect')).'">'.($connected&&$sheet_ready?'Check connection':'Finish setup').'</a></section><section class="wpgss-card"><h2>Quick guide</h2><ol><li>Create an OAuth client in Google Cloud (Web application).</li><li>Add the exact redirect URI shown on the Connect page.</li><li>Enable Google Sheets API.</li><li>Authorize the Google account that can edit your sheet.</li></ol></section></aside></div>';
        } elseif ($tab==='connect') { $this->connect_page($s); }
        elseif ($tab==='integrations') { $this->integrations_page($s); }
        elseif ($tab==='activity') { $this->activity_page($counts,$logs); }
        else { $this->diagnostics_page($s, $counts, $logs); }
        echo '<p class="wpgss-foot">Google OAuth tokens are stored in WordPress options. Restrict administrator access and keep your OAuth client secret private.</p></div>';
    }
    private function check_step($n,$title,$desc,$done,$url) { echo '<div class="wpgss-step"><span class="wpgss-step-num '.($done?'done':'').'">'.($done?'✓':esc_html( (string) $n )).'</span><div><strong>'.esc_html($title).'</strong><p>'.esc_html($desc).'</p><a href="'.esc_url($url).'">'.esc_html($done?'Review':'Set up').' →</a></div></div>'; }

    /**
     * Step-by-step OAuth / Google Cloud setup instructions shown on the Connect tab.
     *
     * @param string $redirect_uri Exact WordPress redirect URI for Google Cloud.
     */
    private function oauth_setup_guide( $redirect_uri ) {
        $guide_url = plugins_url( 'docs/SETUP-GUIDE.md', WPGSS_FILE );
        echo '<section class="wpgss-card wpgss-guide">';
        echo '<h2>How to create OAuth Client ID &amp; Secret (step by step)</h2>';
        echo '<p>Follow these steps once in Google Cloud, then paste the Client ID and Secret into the form below. You do <strong>not</strong> need a Service Account JSON key.</p>';
        echo '<ol class="wpgss-guide-steps">';
        echo '<li><strong>Create a Google Cloud project</strong><br>';
        echo 'Open <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>, sign in, and create or select a project (for example “WordPress Sheets Sync”).</li>';
        echo '<li><strong>Enable Google Sheets API</strong><br>';
        echo 'Open <a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener noreferrer">Google Sheets API</a> and click <strong>Enable</strong>.</li>';
        echo '<li><strong>Configure the OAuth consent screen</strong><br>';
        echo 'Open <a href="https://console.cloud.google.com/auth/overview" target="_blank" rel="noopener noreferrer">Google Auth Platform / OAuth consent screen</a>. Set app name, support email, and developer contact. Choose <strong>External</strong> (unless everyone is in your Google Workspace). If the app is in <strong>Testing</strong> mode, add your Google account under <strong>Test users</strong>.</li>';
        echo '<li><strong>Create an OAuth Client (Web application)</strong><br>';
        echo 'Open <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener noreferrer">Credentials / Clients</a> → <strong>Create Client</strong> → choose <strong>Web application</strong> (not Desktop, Android, iOS, or Service account).</li>';
        echo '<li><strong>Add this exact Authorized redirect URI</strong><br>';
        echo 'Under <strong>Authorized redirect URIs</strong>, click <strong>Add URI</strong> and paste this value exactly (https, domain, path, and query must match):</li>';
        echo '</ol>';
        echo '<div class="wpgss-field wpgss-guide-uri"><label>Copy this redirect URI into Google Cloud</label>';
        echo '<input class="regular-text code" readonly value="' . esc_attr( $redirect_uri ) . '" onclick="this.select()">';
        echo '<p class="description">Do not put this under Authorized JavaScript origins. A mismatch causes Google’s <code>redirect_uri_mismatch</code> error.</p></div>';
        echo '<ol class="wpgss-guide-steps" start="6">';
        echo '<li><strong>Copy Client ID and Client Secret</strong><br>';
        echo 'After creating the client, copy the <strong>Client ID</strong> and <strong>Client Secret</strong> from Google Cloud.</li>';
        echo '<li><strong>Save them in WordPress (form below)</strong><br>';
        echo 'Paste Client ID and Client Secret into the fields below → click <strong>Save OAuth settings</strong> → then click <strong>Connect Google account</strong> and approve access with the Google account that can edit your spreadsheet.</li>';
        echo '<li><strong>Finish spreadsheet setup</strong><br>';
        echo 'On this same page: paste your spreadsheet URL or ID → <strong>Save spreadsheet</strong> → <strong>Test connection</strong> → <strong>Load worksheet tabs</strong> → choose destinations and save.</li>';
        echo '</ol>';
        echo '<details class="wpgss-guide-help"><summary>Troubleshooting common issues</summary><ul>';
        echo '<li><strong>redirect_uri_mismatch</strong> — Copy the URI from this page again. Check https vs http, www vs non-www, and staging vs production domain.</li>';
        echo '<li><strong>Access blocked / app not verified</strong> — Continue only if you own the Cloud project. In Testing mode, add your account as a test user.</li>';
        echo '<li><strong>Connected but no refresh token</strong> — Remove the app from <a href="https://myaccount.google.com/connections" target="_blank" rel="noopener noreferrer">Google Account → Connections</a>, then click Reconnect Google account here.</li>';
        echo '<li><strong>HTTP 403 on Test connection</strong> — Enable Sheets API and give the connected Google account Editor access on the spreadsheet.</li>';
        echo '</ul></details>';
        echo '<p class="wpgss-muted">Full written guide (same steps in more detail): <a href="' . esc_url( $guide_url ) . '" target="_blank" rel="noopener noreferrer">docs/SETUP-GUIDE.md</a></p>';
        echo '</section>';
    }

    private function connect_page($s) {
        $connected = !empty($s['oauth_refresh_token']) || (!empty($s['oauth_access_token']) && !empty($s['oauth_token_expires_at']) && (int) $s['oauth_token_expires_at'] > time() + 60); $redirect=admin_url('admin-post.php?action=wpgss_oauth_callback');
        $this->oauth_setup_guide( $redirect );
        echo '<section class="wpgss-card"><h2>1. Connect your Google account</h2><p>Paste the OAuth Client ID and Client Secret from Google Cloud below. This method does not require a Service Account JSON key.</p><form method="post" action="options.php">'; settings_fields('wpgss_settings_group');
        echo '<div class="wpgss-field"><label for="wpgss_client_id">OAuth Client ID</label><input id="wpgss_client_id" class="regular-text" name="wpgss_settings[oauth_client_id]" value="'.esc_attr($s['oauth_client_id']??'').'" placeholder="Paste OAuth Client ID from Google Cloud" required></div>';
        echo '<div class="wpgss-field"><label for="wpgss_client_secret">OAuth Client Secret</label><input type="password" id="wpgss_client_secret" class="regular-text" name="wpgss_settings[oauth_client_secret]" value="" autocomplete="new-password" placeholder="'.(!empty($s['oauth_client_secret'])?'Saved — leave blank to keep current secret':'Paste client secret').'"/><p class="description">The saved secret is not displayed. Leave blank to keep the current one.</p></div>';
        echo '<div class="wpgss-field"><label>Authorized redirect URI <small>(copy exactly)</small></label><input class="regular-text code" readonly value="'.esc_attr($redirect).'" onclick="this.select()"><p class="description">In Google Cloud → APIs &amp; Services → Credentials → your OAuth client → Authorized redirect URIs, add this exact URI.</p></div>';
        echo '<p class="wpgss-muted">Before connecting: enable Google Sheets API and configure the OAuth consent screen. If the app is in Testing mode, add your Google account as a test user.</p>';
        if ($connected && empty($s['oauth_refresh_token'])) {
            echo '<p class="notice notice-warning inline"><strong>Temporary connection:</strong> Google authorized this account but did not provide a refresh token. It may stop working when the access token expires. Reconnect with offline access enabled.</p>';
        } submit_button('Save OAuth settings','primary','submit',false); echo '</form><hr><p><strong>Google account:</strong> '.($connected?'Connected'.(!empty($s['google_email'])?' — '.esc_html($s['google_email']):''):'Not connected').'</p>';
        if (!$connected && !empty($s['oauth_last_error'])) echo '<p class="notice notice-error inline"><strong>Last connection issue:</strong> '.esc_html($s['oauth_last_error']).'</p>';
        echo '<p class="description">Diagnostic: '.(!empty($s['oauth_refresh_token']) ? 'refresh token saved — connection renews automatically' : (!empty($s['oauth_access_token']) ? 'temporary access token saved (reconnect recommended for a lasting connection)' : 'no OAuth token saved')).'.</p>';
        if (!empty($s['oauth_client_id'])&&!empty($s['oauth_client_secret'])) $this->action_button('wpgss_oauth_start',$connected?'Reconnect Google account':'Connect Google account','button-primary');
        if ($connected) $this->action_button('wpgss_disconnect','Disconnect Google account','button-secondary');
        echo '</section><section class="wpgss-card"><h2>2. Select the exact spreadsheet</h2><p>Paste the URL from your browser or just the spreadsheet ID. The plugin will use this spreadsheet only.</p><form method="post" action="options.php">'; settings_fields('wpgss_settings_group');
        echo '<div class="wpgss-field"><label for="wpgss_spreadsheet">Google Sheets URL or ID</label><input id="wpgss_spreadsheet" class="regular-text" name="wpgss_settings[spreadsheet_id]" value="'.esc_attr($s['spreadsheet_id']??'').'" placeholder="Paste spreadsheet URL or ID" required></div>';
        echo '<p class="wpgss-muted">Current spreadsheet: <strong>'.esc_html($s['spreadsheet_title']??($s['spreadsheet_id']?'ID saved, not yet tested':'None selected')).'</strong></p>'; submit_button('Save spreadsheet','primary','submit',false); echo '</form><div class="wpgss-actions">'; $this->action_button('wpgss_test_connection','Test connection','button-primary'); $this->action_button('wpgss_load_sheets','Load worksheet tabs','button-secondary'); echo '</div>';
        if (!empty($s['worksheet_names'])) echo '<p class="wpgss-success">Found '.count($s['worksheet_names']).' worksheet tab(s). Choose a destination for each integration below.</p>';
        echo '</section><section class="wpgss-card"><h2>3. Choose destination worksheet tabs</h2><p>These dropdowns are populated from the spreadsheet above. If you change the spreadsheet, click “Load worksheet tabs” again.</p><form method="post" action="options.php">'; settings_fields('wpgss_settings_group');
        foreach(array('forms_sheet'=>'Form submissions','orders_sheet'=>'WooCommerce orders','items_sheet'=>'WooCommerce order items','customers_sheet'=>'WooCommerce customers') as $key=>$label) { echo '<div class="wpgss-field"><label for="'.esc_attr($key).'">'.esc_html($label).'</label>'; if (!empty($s['worksheet_names'])) { echo '<select id="'.esc_attr($key).'" name="wpgss_settings['.esc_attr($key).']">'; foreach($s['worksheet_names'] as $name) echo '<option value="'.esc_attr($name).'" '.selected($s[$key]??'',$name,false).'>'.esc_html($name).'</option>'; echo '</select>'; } else { echo '<input id="'.esc_attr($key).'" name="wpgss_settings['.esc_attr($key).']" value="'.esc_attr($s[$key]??$label).'">'; } echo '</div>'; }
        submit_button('Save worksheet destinations','primary','submit',false); echo '</form><p class="description">A missing worksheet tab is created automatically when the first record is synced.</p></section>';
    }
    private function integrations_page($s) {
        echo '<form method="post" action="options.php" class="wpgss-card">'; settings_fields('wpgss_settings_group'); echo '<input type="hidden" name="wpgss_settings[wpgss_integrations_form]" value="1"><h2>Choose what to sync</h2><p>Turn on only the data you want sent to the selected spreadsheet.</p>';
        $cards=array('elementor_enabled'=>array('Elementor Pro Forms','New submissions from Elementor Pro Forms.'),'cf7_enabled'=>array('Contact Form 7','Submissions are sent after Contact Form 7 mail is sent.'),'woocommerce_orders_enabled'=>array('WooCommerce orders','Order totals, status, customer details, and product summary.'),'woocommerce_customers_enabled'=>array('WooCommerce customers','Registered customer account and billing details.'));
        foreach($cards as $key=>$v) echo '<label class="wpgss-toggle"><input type="checkbox" name="wpgss_settings['.esc_attr($key).']" value="1" '.checked(!empty($s[$key]),true,false).'><span class="wpgss-switch"></span><span><strong>'.esc_html($v[0]).'</strong><small>'.esc_html($v[1]).'</small></span></label>';
        echo '<details class="wpgss-advanced"><summary>Advanced settings</summary><div class="wpgss-field"><label for="wpgss_retries">Retry attempts</label><select id="wpgss_retries" name="wpgss_settings[max_retries]">'; $r=absint($s['max_retries']??5); for($i=1;$i<=10;$i++) echo '<option value="'.esc_attr((string)$i).'" '.selected($r,$i,false).'>'.esc_html((string)$i).'</option>'; echo '</select></div></details>'; submit_button('Save sync settings','primary','submit',false); echo '<p class="wpgss-muted">This build implements Elementor Pro Forms, Contact Form 7, WooCommerce orders, and registered customers. WPForms, Fluent Forms, and Gravity Forms are not yet implemented.</p></form>';
    }
    private function activity_page($counts,$logs) {
        echo '<section class="wpgss-card"><h2>Manual tools</h2><p>Use these if you need to process queued data or recover failed jobs.</p><div class="wpgss-actions">'; $this->action_button('wpgss_sync_now','Process queue now','button-primary'); $this->action_button('wpgss_retry_failed','Retry failed jobs','button-secondary'); $this->action_button('wpgss_import_orders','Queue up to 100 historical orders','button-secondary'); echo '</div><div class="wpgss-stats">'; foreach(array('pending'=>'Waiting','processing'=>'In progress','completed'=>'Synced','failed'=>'Failed','retry'=>'Retrying') as $key=>$label) echo '<div><strong>'.esc_html($counts[$key]).'</strong><span>'.esc_html($label).'</span></div>'; echo '</div></section><section class="wpgss-card"><h2>Recent activity</h2><div class="wpgss-table"><table class="widefat striped"><thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead><tbody>';
        if (!$logs) echo '<tr><td colspan="3">No activity yet. Test the connection or send a test form submission.</td></tr>'; else foreach(array_slice($logs,0,40) as $log) echo '<tr><td>'.esc_html($log['time']??'').'</td><td>'.esc_html($log['type']??'').'</td><td>'.esc_html($log['message']??'').'</td></tr>';
        echo '</tbody></table></div></section>';
    }
    public function wpgss_oauth_start() {
        if (!current_user_can('manage_options') || !check_admin_referer('wpgss_oauth_start')) wp_die('Unauthorized'); $s=$this->settings();
        if (empty($s['oauth_client_id'])||empty($s['oauth_client_secret'])) $this->redirect_notice('Save the OAuth Client ID and Client Secret first.','error');
        $state=wp_generate_password(48,false,false);
        $state_key = hash('sha256', $state);
        $state_data = array('user_id'=>get_current_user_id(),'created'=>time());
        // Store state in both transient and a non-autoloaded option. Some hosts/object caches
        // drop transients between the outbound redirect and Google's callback.
        set_transient('wpgss_oauth_state_'.$state_key, $state_data, 15*MINUTE_IN_SECONDS);
        update_option('wpgss_oauth_state_'.$state_key, $state_data, false);
        $url=add_query_arg(array('client_id'=>$s['oauth_client_id'],'redirect_uri'=>admin_url('admin-post.php?action=wpgss_oauth_callback'),'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/userinfo.email','access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'true','state'=>$state), 'https://accounts.google.com/o/oauth2/v2/auth'); wp_safe_redirect($url); exit;
    }
    public function wpgss_oauth_callback() {
        /*
         * Google redirects here with ?state=&code= (or ?error=). CSRF is enforced via the
         * one-time hashed state stored when Connect started — not a WordPress nonce.
         */
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $google_error_raw = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $state_key = $state !== '' ? 'wpgss_oauth_state_' . hash('sha256', $state) : '';
        $state_data = $state_key !== '' ? get_transient($state_key) : false;
        if (!is_array($state_data) && $state !== '') { $state_data = get_option('wpgss_oauth_state_'.hash('sha256', $state), false); }
        if ($state_key !== '') delete_transient($state_key);
        if ($state !== '') delete_option('wpgss_oauth_state_'.hash('sha256', $state));
        if (!is_array($state_data) || empty($state_data['user_id']) || empty($state) || (time() - absint($state_data['created'] ?? 0)) > 15 * MINUTE_IN_SECONDS) {
            $message = 'OAuth callback reached WordPress, but its security state was missing or expired. No token was saved. Start Connect again; if this repeats, check caching/security plugins and ensure the site uses one consistent HTTPS/www domain.';
            $this->record_diagnostic('oauth_error', $message);
            $this->redirect_notice($message, 'error');
        }
        $uid = absint($state_data['user_id']);
        $oauth_user = get_user_by('id', $uid);
        if (!$oauth_user || !user_can($oauth_user, 'manage_options')) $this->redirect_notice('The administrator who started this connection is no longer authorized. Sign in as an administrator and connect again.','error');
        if ($google_error_raw !== '') { $s = $this->settings(); $s['oauth_last_error'] = 'Google authorization failed: ' . $google_error_raw; update_option('wpgss_settings', $s, false); $this->record_diagnostic('oauth_error', $s['oauth_last_error']); $this->redirect_notice($s['oauth_last_error'], 'error'); }
        $s = $this->settings();
        if (!$code) { $err = 'Google did not return an authorization code. Check the OAuth consent screen, redirect URI, and Google account authorization.'; $s['oauth_last_error'] = $err; update_option('wpgss_settings', $s, false); $this->record_diagnostic('oauth_error', $err); $this->redirect_notice($err, 'error'); }
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout'=>25,
            'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),
            'body'=>array('code'=>$code,'client_id'=>$s['oauth_client_id'] ?? '',
                'client_secret'=>$s['oauth_client_secret'] ?? '',
                'redirect_uri'=>admin_url('admin-post.php?action=wpgss_oauth_callback'),
                'grant_type'=>'authorization_code')
        ));
        if (is_wp_error($response)) {
            $err = 'Could not contact Google: '.$response->get_error_message();
            $s['oauth_last_error'] = $err; update_option('wpgss_settings',$s,false);
            $this->record_diagnostic('oauth_error', $err);
            $this->redirect_notice($err,'error');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response)<200 || wp_remote_retrieve_response_code($response)>=300 || empty($body['access_token'])) {
            $err = 'Google token exchange failed (HTTP '.absint(wp_remote_retrieve_response_code($response)).'): '.sanitize_text_field($body['error_description'] ?? $body['error'] ?? 'Check OAuth client credentials, consent screen, and exact redirect URI.');
            $s['oauth_last_error'] = $err; update_option('wpgss_settings',$s,false);
            $this->record_diagnostic('oauth_error', $err);
            $this->redirect_notice($err,'error');
        }
        if (!empty($body['refresh_token'])) $s['oauth_refresh_token'] = sanitize_text_field($body['refresh_token']);
        $s['oauth_access_token'] = sanitize_text_field($body['access_token']);
        $s['oauth_token_expires_at'] = time() + max(60, absint($body['expires_in'] ?? 3600));
        $s['oauth_last_error'] = '';
        $email_response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', array('timeout'=>15,'headers'=>array('Authorization'=>'Bearer '.$s['oauth_access_token'])));
        if (!is_wp_error($email_response)) {
            $u = json_decode(wp_remote_retrieve_body($email_response), true);
            if (!empty($u['email'])) $s['google_email'] = sanitize_email($u['email']);
        }
        $saved_ok = $this->save_oauth_settings($s);
        if (!$saved_ok) {
            global $wpdb;
            $db_detail = !empty($wpdb->last_error) ? ' Database reported an error while saving plugin options.' : '';
            $err = 'Google returned an access token, but WordPress could not verify the dedicated OAuth token options after saving.' . $db_detail . ' Check the database error log and any persistent object-cache or security plugin. No token value was written to the diagnostic log.';
            $this->record_diagnostic('oauth_error', $err);
            $this->redirect_notice($err, 'error', 'diagnostics');
        }
        if (empty($s['oauth_refresh_token'])) {
            $err = 'Google returned a temporary access token but no refresh token. The plugin cannot maintain a long-lived connection. In Google Cloud, set the OAuth consent screen to External and add this Google account as a test user if the app is in Testing. Then remove the app from Google Account → Security → Your connections to third-party apps, and click Reconnect Google account again. If the app is Internal, use an account from that Workspace organization.';
            $s['oauth_last_error'] = $err; update_option('wpgss_settings', $s, false); $this->record_diagnostic('oauth_error', $err);
            $this->redirect_notice($err, 'error');
        }
        $this->redirect_notice('Google account connected successfully'.(!empty($s['google_email']) ? ' as '.$s['google_email'] : '').'. Now test access to your selected spreadsheet.','success');
    }
    public function wpgss_disconnect() { if (!current_user_can('manage_options')||!check_admin_referer('wpgss_disconnect')) wp_die('Unauthorized'); $s=$this->settings(); unset($s['oauth_refresh_token'],$s['oauth_access_token'],$s['oauth_token_expires_at'],$s['google_email'],$s['oauth_last_error']); foreach (array('oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email') as $key) { delete_option('wpgss_'.$key); } update_option('wpgss_settings',$s,false); $this->redirect_notice('Google account disconnected.','success'); }
    private function record_diagnostic($type, $message) { $logs=get_option('wpgss_logs',array()); if(!is_array($logs)) $logs=array(); array_unshift($logs,array('time'=>current_time('mysql'),'type'=>sanitize_key($type),'message'=>sanitize_text_field($message))); update_option('wpgss_logs',array_slice($logs,0,200),false); }
    public function wpgss_test_connection() { if (!current_user_can('manage_options')||!check_admin_referer('wpgss_test_connection')) wp_die('Unauthorized'); $result=WPGSS_Sync_Manager::instance()->test_connection(); if (is_wp_error($result)) { $this->record_diagnostic('connection_error', $result->get_error_message()); $this->redirect_notice($result->get_error_message(),'error','diagnostics'); } $s=$this->settings(); $s['spreadsheet_title']=$result['properties']['title']??'Connected spreadsheet'; update_option('wpgss_settings',$s,false); $this->redirect_notice('Connection successful. Google can access “'.$s['spreadsheet_title'].'”.','success'); }
    public function wpgss_load_sheets() { if (!current_user_can('manage_options')||!check_admin_referer('wpgss_load_sheets')) wp_die('Unauthorized'); $s=$this->settings(); if (empty($s['spreadsheet_id'])) $this->redirect_notice('Paste and save a spreadsheet URL or ID first.','error'); $client=new WPGSS_Google_Sheets($s); $result=$client->list_worksheets($s['spreadsheet_id']); if (is_wp_error($result)) $this->redirect_notice($result->get_error_message(),'error'); $s['worksheet_names']=$result['worksheets']; $s['spreadsheet_title']=$result['title']; foreach(array('forms_sheet'=>'Contact Forms','orders_sheet'=>'Orders','items_sheet'=>'Order Items','customers_sheet'=>'Customers') as $key=>$default) if (empty($s[$key])||!in_array($s[$key],$s['worksheet_names'],true)) $s[$key]=in_array($default,$s['worksheet_names'],true)?$default:($s['worksheet_names'][0]??$default); update_option('wpgss_settings',$s,false); $this->redirect_notice('Loaded '.count($result['worksheets']).' worksheet tabs from “'.$result['title'].'”. Save destinations below.'); }
    public function wpgss_sync_now() { if (!current_user_can('manage_options')||!check_admin_referer('wpgss_sync_now')) wp_die('Unauthorized'); WPGSS_Sync_Manager::instance()->process_queue(); $this->redirect_notice('Queue processing was triggered. Review Activity & tools for results.','success','activity'); }
    public function wpgss_retry_failed() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wpgss_retry_failed' ) ) {
            wp_die( 'Unauthorized' );
        }
        global $wpdb;
        $table = $this->queue_table_name();
        // Custom plugin queue table — not available via WP APIs. %i is the identifier placeholder (WP 6.2+).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = 'retry', attempts = 0, updated_at = %s WHERE status = 'failed'",
                $table,
                current_time( 'mysql' )
            )
        );
        $this->bust_queue_count_cache();
        WPGSS_Sync_Manager::instance()->process_queue();
        $this->redirect_notice( 'Failed jobs have been queued for retry.', 'success', 'activity' );
    }
    public function wpgss_import_orders() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wpgss_import_orders' ) ) {
            wp_die( 'Unauthorized' );
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            $this->redirect_notice( 'WooCommerce is not active.', 'error', 'activity' );
        }
        $ids = wc_get_orders(
            array(
                'limit'   => 100,
                'return'  => 'ids',
                'orderby' => 'date',
                'order'   => 'ASC',
            )
        );
        $sync = WPGSS_Sync_Manager::instance();
        foreach ( $ids as $id ) {
            // Call the plugin sync path directly (avoids inventing/firing unprefixed hooks).
            $sync->woocommerce_order_created( $id );
        }
        $this->bust_queue_count_cache();
        $this->redirect_notice( 'Queued up to 100 historical orders. Check Activity & tools for progress.', 'success', 'activity' );
    }
    private function diagnostics_page($s, $counts, $logs) {
        global $wpdb;
        $table = $this->queue_table_name();
        // Custom plugin queue table presence check.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        $checks = array(
            array('WordPress version', get_bloginfo('version'), version_compare(get_bloginfo('version'), '6.2', '>=')),
            array('PHP version', PHP_VERSION, version_compare(PHP_VERSION, '7.4', '>=')),
            array('WordPress HTTP API', function_exists('wp_remote_post') ? 'Available' : 'Missing', function_exists('wp_remote_post')),
            array('OpenSSL extension', extension_loaded('openssl') ? 'Available' : 'Missing', extension_loaded('openssl')),
            array('Queue database table', $table_exists ? 'Present' : 'Missing — deactivate/activate plugin to recreate it', $table_exists),
            array('Google OAuth Client ID', !empty($s['oauth_client_id']) ? 'Saved' : 'Not saved', !empty($s['oauth_client_id'])),
            array('Google OAuth Client Secret', !empty($s['oauth_client_secret']) ? 'Saved (hidden)' : 'Not saved', !empty($s['oauth_client_secret'])),
            array('Google refresh token', !empty($s['oauth_refresh_token']) ? 'Saved' : 'Not saved — reconnect Google', !empty($s['oauth_refresh_token'])),
            array('Spreadsheet ID', !empty($s['spreadsheet_id']) ? 'Saved' : 'Not saved', !empty($s['spreadsheet_id'])),
            array('Debug logging', (defined('WP_DEBUG') && WP_DEBUG) ? 'Enabled' : 'Disabled', true),
        );
        echo '<section class="wpgss-card"><h2>System health checks</h2><p>These checks do not expose your client secret or OAuth tokens. A green row means the local prerequisite is present; it does not by itself prove that Google API access works.</p><div class="wpgss-table"><table class="widefat striped"><thead><tr><th>Check</th><th>Result</th><th>Status</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            echo '<tr><td>'.esc_html($check[0]).'</td><td>'.esc_html($check[1]).'</td><td><strong style="color:'.($check[2]?'#16804a':'#b32d2e').'">'.($check[2]?'OK':'ACTION REQUIRED').'</strong></td></tr>';
        }
        echo '</tbody></table></div><h3>Live Google test</h3><p>This sends a real request to the Google Sheets API using the saved authorization.</p>';
        $this->action_button('wpgss_test_connection','Test Google + spreadsheet access','button-primary');
        echo '<h3>Queue counts</h3><p>Waiting: <strong>'.absint($counts['pending']??0).'</strong> · Retrying: <strong>'.absint($counts['retry']??0).'</strong> · Failed: <strong>'.absint($counts['failed']??0).'</strong> · Completed: <strong>'.absint($counts['completed']??0).'</strong></p>';
        echo '<h3>Recent diagnostic events</h3><p>Full messages are shown below. Never share a screenshot containing client secrets or tokens.</p><div class="wpgss-table"><table class="widefat striped"><thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead><tbody>';
        $shown = 0;
        foreach ((array)$logs as $log) {
            $type = (string)($log['type'] ?? '');
            if (stripos($type, 'error') === false && stripos($type, 'oauth') === false && stripos($type, 'connection') === false) continue;
            echo '<tr><td>'.esc_html($log['time']??'').'</td><td>'.esc_html($type).'</td><td>'.esc_html($log['message']??'').'</td></tr>';
            if (++$shown >= 30) break;
        }
        if (!$shown) echo '<tr><td colspan="3">No diagnostic errors recorded yet. Run the live Google test above.</td></tr>';
        echo '</tbody></table></div>';
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            echo '<p class="description">WordPress debug logging is enabled. Check the configured debug log for PHP fatal errors. Plugin diagnostics cannot intercept every fatal error safely.</p>';
        } else {
            echo '<div class="notice notice-warning inline"><p>WordPress debug logging is not enabled. If the site crashes, enable WP_DEBUG and WP_DEBUG_LOG temporarily in wp-config.php, reproduce the issue, then inspect wp-content/debug.log. Do not leave WP_DEBUG_DISPLAY enabled on a live site.</p></div>';
        }
        echo '</section>';
    }
    private function css() { return '.wpgss{max-width:1180px;margin:22px 20px 40px 0;color:#1d2327}.wpgss *{box-sizing:border-box}.wpgss-hero{display:flex;justify-content:space-between;align-items:center;gap:20px;background:#153c32;color:#fff;padding:27px 30px;border-radius:14px;margin:18px 0 20px}.wpgss-hero h1{color:#fff;font-size:28px;margin:8px 0}.wpgss-hero p{color:#d7e8df;margin:0}.wpgss-hero>.dashicons{font-size:44px;width:44px;height:44px;color:#cce9d8}.wpgss-kicker{font-size:11px;letter-spacing:1.5px;font-weight:700;color:#b8dec9}.wpgss-nav{display:flex;gap:4px;overflow:auto;border-bottom:1px solid #dcdcde;margin-bottom:22px}.wpgss-nav a{padding:13px 15px;text-decoration:none;color:#50575e;font-weight:600;white-space:nowrap;border-bottom:3px solid transparent}.wpgss-nav a.active{border-color:#228153;color:#153c32}.wpgss-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(270px,1fr);gap:20px;align-items:start}.wpgss-grid main,.wpgss-grid aside{display:grid;gap:20px;min-width:0}.wpgss-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;margin:0 0 20px;min-width:0;box-shadow:0 2px 7px #00000005}.wpgss-grid .wpgss-card{margin:0}.wpgss-card h2{font-size:18px;margin:0 0 7px}.wpgss-card p{line-height:1.55;color:#646970}.wpgss-card-head{display:flex;justify-content:space-between;gap:15px;align-items:start}.wpgss-card-head a{text-decoration:none;font-weight:600}.wpgss-step{display:flex;gap:14px;padding:17px 0;border-top:1px solid #f0f0f1}.wpgss-step:first-of-type{margin-top:12px}.wpgss-step-num{width:33px;height:33px;border-radius:50%;background:#f0f0f1;display:grid;place-items:center;font-weight:700;flex-shrink:0}.wpgss-step-num.done{background:#e2f3e8;color:#17663b}.wpgss-step strong{display:block;margin:1px 0 3px}.wpgss-step p{margin:0 0 5px}.wpgss-step a{font-size:13px;font-weight:600;text-decoration:none}.wpgss-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:17px}.wpgss-stats>div{padding:14px;background:#f7f9f8;border:1px solid #edf0ee;border-radius:8px;display:flex;flex-direction:column;gap:5px}.wpgss-stats strong{font-size:24px;color:#153c32}.wpgss-stats span{font-size:12px;color:#646970}.wpgss-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#dba617;margin-right:8px}.wpgss-dot.good{background:#218653}.wpgss-muted,.wpgss-foot{font-size:13px;color:#646970}.wpgss-foot{margin-top:20px}.wpgss-field{margin:18px 0}.wpgss-field>label{display:block;font-weight:650;margin-bottom:8px}.wpgss-field input.regular-text,.wpgss-field select{width:100%;max-width:760px;padding:10px 12px;border:1px solid #c3c4c7;border-radius:7px}.wpgss-field .description{margin-top:7px}.wpgss-card hr{border:0;border-top:1px solid #eee;margin:22px 0}.wpgss-inline-form{display:inline-block;margin:0 8px 8px 0}.wpgss-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.wpgss-toggle{display:flex;align-items:center;gap:14px;padding:16px;border:1px solid #e2e5e3;border-radius:9px;margin:12px 0;cursor:pointer}.wpgss-toggle input{position:absolute;opacity:0}.wpgss-toggle>span:last-child{display:flex;flex-direction:column;gap:4px;flex:1}.wpgss-toggle small{font-size:12px;color:#646970}.wpgss-switch{width:38px;height:22px;border-radius:20px;background:#c3c4c7;position:relative;flex-shrink:0}.wpgss-switch:after{content:"";position:absolute;left:3px;top:3px;width:16px;height:16px;background:white;border-radius:50%;transition:transform .15s}.wpgss-toggle input:checked+.wpgss-switch{background:#218653}.wpgss-toggle input:checked+.wpgss-switch:after{transform:translateX(16px)}.wpgss-toggle input:focus-visible+.wpgss-switch{outline:2px solid #2271b1;outline-offset:3px}.wpgss-advanced{padding:14px;background:#f8f9f9;border:1px solid #e6e8e7;border-radius:8px;margin:20px 0}.wpgss-advanced summary{font-weight:600;cursor:pointer}.wpgss-table{overflow:auto}.wpgss-table table{border:0}.wpgss-table td,.wpgss-table th{padding:12px}.wpgss-success{padding:10px 12px;background:#edf8f0;border-radius:7px;color:#17663b!important}.wpgss .button-primary{background:#176b48;border-color:#176b48}.wpgss .button-primary:hover{background:#105438;border-color:#105438}.wpgss-guide{border-color:#b8dec9;background:linear-gradient(180deg,#f4faf6 0%,#fff 48px)}.wpgss-guide-steps{margin:14px 0 0;padding-left:22px;color:#1d2327}.wpgss-guide-steps li{margin:0 0 14px;line-height:1.55}.wpgss-guide-steps a{font-weight:600}.wpgss-guide-uri{margin-top:4px;padding:14px;background:#edf8f0;border:1px solid #cce9d8;border-radius:8px}.wpgss-guide-uri input{max-width:100%!important;background:#fff}.wpgss-guide-help{margin-top:16px;padding:12px 14px;background:#f8f9f9;border:1px solid #e6e8e7;border-radius:8px}.wpgss-guide-help summary{font-weight:600;cursor:pointer}.wpgss-guide-help ul{margin:10px 0 0;padding-left:18px}.wpgss-guide-help li{margin:0 0 8px;line-height:1.5}@media(max-width:900px){.wpgss-grid{grid-template-columns:1fr}.wpgss-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.wpgss{margin-right:10px}.wpgss-hero{padding:20px}.wpgss-hero h1{font-size:23px}.wpgss-card{padding:17px}.wpgss-stats{gap:7px}.wpgss-stats>div{padding:11px}}'; }
}
