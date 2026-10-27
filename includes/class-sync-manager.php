<?php
if (!defined('ABSPATH')) { exit; }

class WPGSS_Sync_Manager {
    private static $instance;
    private $table;
    public static function instance() { if (!self::$instance) { self::$instance = new self(); } return self::$instance; }
    private function __construct() {
        global $wpdb; $this->table = $wpdb->prefix . 'wpgss_queue';
        add_action('wpgss_process_queue', array($this, 'process_queue'));
        add_action('elementor_pro/forms/new_record', array($this, 'elementor_submission'), 10, 2);
        add_action('wpcf7_mail_sent', array($this, 'cf7_submission'));
        add_action('woocommerce_checkout_order_processed', array($this, 'woocommerce_order'), 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'store_api_order'), 20, 1);
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 20, 4);
        add_action('woocommerce_new_order', array($this, 'woocommerce_order_created'), 20, 2);
        add_action('user_register', array($this, 'user_changed'), 20, 1);
        add_action('profile_update', array($this, 'user_changed'), 20, 1);
        add_action('woocommerce_created_customer', array($this, 'user_changed'), 20, 1);
    }
    public static function activate() {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'wpgss_queue'; $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key varchar(191) NOT NULL,
            source_type varchar(40) NOT NULL,
            source_id varchar(100) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id), UNIQUE KEY job_key (job_key), KEY status_created (status,created_at)
        ) $charset;";
        dbDelta($sql);
        if (!wp_next_scheduled('wpgss_process_queue')) { wp_schedule_event(time() + 60, 'wpgss_minute', 'wpgss_process_queue'); }
        add_option('wpgss_settings', array('spreadsheet_id'=>'','oauth_client_id'=>'','oauth_client_secret'=>'','oauth_refresh_token'=>'','oauth_access_token'=>'','elementor_enabled'=>1,'cf7_enabled'=>1,'woocommerce_orders_enabled'=>1,'woocommerce_customers_enabled'=>1,'orders_sheet'=>'Orders','items_sheet'=>'Order Items','customers_sheet'=>'Customers','forms_sheet'=>'Contact Forms','max_retries'=>5));
    }
    public static function deactivate() {
        // Only stop this plugin's scheduled runner. Keep settings, logs and queued jobs for a safe reactivation.
        wp_clear_scheduled_hook('wpgss_process_queue');
    }

    private function settings() {
        $s = get_option('wpgss_settings', array());
        if (!is_array($s)) { $s = array(); }
        foreach (array('oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email') as $key) {
            $v = get_option('wpgss_' . $key, null);
            if ($v !== null && $v !== '') { $s[$key] = $v; }
        }
        return $s;
    }
    private function bust_queue_count_cache() {
        foreach ( array( 'pending', 'processing', 'completed', 'failed', 'retry' ) as $status ) {
            wp_cache_delete( 'wpgss_queue_count_' . $status, 'wpgss' );
        }
    }
    private function queue($type, $id, $payload, $key = '') {
        global $wpdb; $now = current_time('mysql');
        $key = $key ?: $type . ':' . $id . ':' . md5(wp_json_encode($payload));
        // Custom plugin queue table — not available via WP APIs. %i is the identifier placeholder (WP 6.2+).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO %i (job_key,source_type,source_id,payload,status,attempts,created_at,updated_at) VALUES (%s,%s,%s,%s,'pending',0,%s,%s) ON DUPLICATE KEY UPDATE status=IF(payload <> VALUES(payload),'pending',IF(status='processing','pending',status)), payload=VALUES(payload), updated_at=VALUES(updated_at)",
                $this->table,
                $key,
                $type,
                (string) $id,
                wp_json_encode( $payload ),
                $now,
                $now
            )
        );
        $this->bust_queue_count_cache();
        if (!wp_next_scheduled('wpgss_process_queue')) { wp_schedule_single_event(time() + 10, 'wpgss_process_queue'); }
    }
    private function safe_value($value) {
        if (is_array($value) || is_object($value)) { $value = wp_json_encode($value); }
        $value = (string) $value;
        // Neutralize spreadsheet formulas from untrusted source data.
        if (preg_match('/^[\s]*[=+@]/', $value) || preg_match('/^[\s]*-[0-9]/', $value)) { $value = "'" . $value; }
        return $value;
    }
    public function elementor_submission($record, $ajax_handler) {
        $s = $this->settings(); if (empty($s['elementor_enabled'])) { return; }
        $raw = $record->get('fields'); $fields = array();
        if (is_array($raw)) { foreach ($raw as $id => $field) { $label = isset($field['title']) ? $field['title'] : $id; $fields[sanitize_key($id)] = $this->safe_value(isset($field['value']) ? $field['value'] : ''); } }
        $form_name = method_exists($record, 'get_form_settings') ? $record->get_form_settings('form_name') : 'Elementor Form';
        $payload = array_merge(array('Form Name'=>$form_name ?: 'Elementor Form','Form ID'=>method_exists($record,'get_form_settings') ? $record->get_form_settings('form_id') : '', 'Submitted At'=>current_time('mysql'), 'Source URL'=>isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '', 'WordPress User ID'=>get_current_user_id()), $fields);
        $id = wp_generate_uuid4(); $this->queue('elementor_form', $id, array('sheet'=>$s['forms_sheet'] ?? 'Contact Forms','data'=>$payload,'record_key'=>'elementor:' . $id));
    }
    public function cf7_submission($contact_form) {
        $s = $this->settings(); if (empty($s['cf7_enabled'])) { return; }
        if (!class_exists('WPCF7_Submission')) { return; }
        $submission = WPCF7_Submission::get_instance(); if (!$submission) { return; }
        $posted = $submission->get_posted_data(); $data = array('Form Name'=>method_exists($contact_form,'title') ? $contact_form->title() : 'Contact Form 7','Form ID'=>method_exists($contact_form,'id') ? $contact_form->id() : '', 'Submitted At'=>current_time('mysql'), 'Source URL'=>isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '');
        foreach ((array)$posted as $key=>$value) { if (strpos((string)$key, '_') === 0 || in_array($key, array('g-recaptcha-response'), true)) { continue; } $data[sanitize_key($key)] = $this->safe_value($value); }
        $id = wp_generate_uuid4(); $this->queue('contact_form_7', $id, array('sheet'=>$s['forms_sheet'] ?? 'Contact Forms','data'=>$data,'record_key'=>'cf7:' . $id));
    }
    public function woocommerce_order($order_id, $posted_data = array(), $order = null) { $this->sync_order($order_id); }
    public function store_api_order($order) { if (is_object($order) && method_exists($order,'get_id')) { $this->sync_order($order->get_id()); } }
    public function woocommerce_order_created($order_id, $order = null) { $this->sync_order($order_id); }
    public function order_status_changed($order_id, $old_status, $new_status, $order) { $this->sync_order($order_id); }
    private function sync_order($order_id) {
        $s = $this->settings(); if (empty($s['woocommerce_orders_enabled']) || !function_exists('wc_get_order')) { return; }
        $order = wc_get_order($order_id); if (!$order) { return; }
        $items = array(); foreach ($order->get_items() as $item_id=>$item) {
            $product = $item->get_product();
            $items[] = array('Order ID'=>$order->get_id(),'Order Item ID'=>$item_id,'Product ID'=>$item->get_product_id(),'Product Name'=>$item->get_name(),'SKU'=>$product ? $product->get_sku() : '', 'Variation ID'=>$item->get_variation_id(),'Quantity'=>$item->get_quantity(),'Line Total'=>$item->get_total(),'Line Tax'=>$item->get_total_tax());
        }
        $coupons = array(); foreach ($order->get_coupon_codes() as $coupon) { $coupons[] = $coupon; }
        $data = array(
            'Order ID'=>$order->get_id(),'Order Number'=>$order->get_order_number(),'Order Date'=>$order->get_date_created() ? $order->get_date_created()->date('c') : '', 'Order Status'=>$order->get_status(), 'Customer ID'=>$order->get_customer_id(), 'Customer Email'=>$order->get_billing_email(), 'First Name'=>$order->get_billing_first_name(),'Last Name'=>$order->get_billing_last_name(),'Billing Phone'=>$order->get_billing_phone(),
            'Billing Address'=>$order->get_formatted_billing_address(),'Shipping Address'=>$order->get_formatted_shipping_address(),'Currency'=>$order->get_currency(),'Subtotal'=>$order->get_subtotal(),'Discount'=>$order->get_discount_total(),'Tax'=>$order->get_total_tax(),'Shipping'=>$order->get_shipping_total(),'Total'=>$order->get_total(),'Payment Method'=>$order->get_payment_method_title(),'Transaction ID'=>$order->get_transaction_id(),'Coupon Codes'=>implode(', ', $coupons),'Products'=>implode(', ', array_map(function($i){return $i['Product Name'] . ' x ' . $i['Quantity'];},$items))
        );
        $safe = array(); foreach ($data as $k=>$v) { $safe[$k] = $this->safe_value($v); }
        $this->queue('woocommerce_order', $order->get_id(), array('sheet'=>$s['orders_sheet'] ?? 'Orders','data'=>$safe,'record_key'=>'order:' . $order->get_id()), 'order:' . $order->get_id());
        foreach ($items as $item) { $item_key = 'order_item:' . $item['Order Item ID']; $this->queue('woocommerce_order_item', $item['Order Item ID'], array('sheet'=>$s['items_sheet'] ?? 'Order Items','data'=>array_map(array($this,'safe_value'),$item),'record_key'=>$item_key), $item_key); }
        if ($order->get_customer_id()) { $this->user_changed($order->get_customer_id()); }
    }
    public function user_changed($user_id) {
        $s = $this->settings(); if (empty($s['woocommerce_customers_enabled'])) { return; }
        $user = get_userdata($user_id); if (!$user) { return; }
        $customer = function_exists('wc_get_customer') ? wc_get_customer($user_id) : false;
        $data = array('WordPress User ID'=>$user->ID,'WooCommerce Customer ID'=>$user->ID,'Email'=>$user->user_email,'Username'=>$user->user_login,'First Name'=>get_user_meta($user->ID,'first_name',true),'Last Name'=>get_user_meta($user->ID,'last_name',true),'Billing Phone'=>get_user_meta($user->ID,'billing_phone',true),'Billing Address'=>trim(get_user_meta($user->ID,'billing_address_1',true).' '.get_user_meta($user->ID,'billing_address_2',true)),'Billing City'=>get_user_meta($user->ID,'billing_city',true),'Billing State'=>get_user_meta($user->ID,'billing_state',true),'Billing Postcode'=>get_user_meta($user->ID,'billing_postcode',true),'Billing Country'=>get_user_meta($user->ID,'billing_country',true),'Shipping Address'=>trim(get_user_meta($user->ID,'shipping_address_1',true).' '.get_user_meta($user->ID,'shipping_address_2',true)),'Registration Date'=>$user->user_registered,'Role'=>implode(', ',(array)$user->roles),'Last Profile Update'=>current_time('mysql'));
        if ($customer && method_exists($customer,'get_order_count')) { $data['Total Orders']=$customer->get_order_count(); $data['Total Spending']=$customer->get_total_spent(); }
        foreach ($data as $k=>$v) { $data[$k] = $this->safe_value($v); }
        $this->queue('woocommerce_customer', $user->ID, array('sheet'=>$s['customers_sheet'] ?? 'Customers','data'=>$data,'record_key'=>'customer:' . $user->ID), 'customer:' . $user->ID);
    }

    public function process_queue() {
        global $wpdb; $s = $this->settings(); $client = new WPGSS_Google_Sheets($s);
        if (empty($s['spreadsheet_id']) || !$client->is_configured()) {
            $this->log('configuration_error', empty($s['spreadsheet_id']) ? 'Queue paused: spreadsheet ID is not configured.' : 'Queue paused: Google OAuth refresh/access token is missing. Reconnect Google and test the spreadsheet.');
            return;
        }
        $max_retries = max(1, absint($s['max_retries'] ?? 5));
        // Custom plugin queue table — not available via WP APIs. %i is the identifier placeholder (WP 6.2+).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE status IN ('pending','retry') AND attempts < %d ORDER BY id ASC LIMIT 20",
                $this->table,
                $max_retries
            )
        );
        foreach ( (array) $jobs as $job ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update($this->table, array('status'=>'processing','attempts'=>$job->attempts+1,'updated_at'=>current_time('mysql')), array('id'=>$job->id));
            $payload = json_decode($job->payload, true); $result = $this->upsert($client, $s['spreadsheet_id'], $payload);
            if (is_wp_error($result)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($this->table, array('status'=>($job->attempts + 1 >= max(1, absint($s['max_retries'] ?? 5)) ? 'failed' : 'retry'),'last_error'=>substr($result->get_error_message(),0,1000),'updated_at'=>current_time('mysql')), array('id'=>$job->id));
                $this->log('error', $job->source_type . ' #' . $job->source_id . ': ' . $result->get_error_message());
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update($this->table, array('status'=>'completed','last_error'=>'','updated_at'=>current_time('mysql')), array('id'=>$job->id));
                $this->log('success', $job->source_type . ' #' . $job->source_id . ' synchronized.');
            }
        }
        $this->bust_queue_count_cache();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE status IN ('pending','retry')",
                $this->table
            )
        );
        if ( $remaining > 0 ) { wp_schedule_single_event(time()+30, 'wpgss_process_queue'); }
    }
    private function upsert($client, $spreadsheet_id, $payload) {
        if (empty($payload['sheet']) || empty($payload['data']) || !is_array($payload['data'])) { return new WP_Error('wpgss_payload','Invalid synchronization payload.'); }
        $sheet = sanitize_text_field($payload['sheet']); $key = isset($payload['record_key']) ? $payload['record_key'] : '';
        $rows = $client->read_rows($spreadsheet_id, $sheet);
        if (is_wp_error($rows)) {
            // Create only when the tab is genuinely missing; do not mask permission/API errors.
            $read_error = $rows;
            $info = $client->spreadsheet_info($spreadsheet_id);
            if (is_wp_error($info)) { return $info; }
            $exists = false;
            foreach (($info['sheets'] ?? array()) as $sh) { if (($sh['properties']['title'] ?? '') === $sheet) { $exists = true; break; } }
            if (!$exists) {
                $created = $client->create_sheet($spreadsheet_id, $sheet);
                if (is_wp_error($created)) { return $created; }
                $rows = array();
            } else {
                return $read_error;
            }
        }
        $headers = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : array();
        if (!$headers) {
            $headers = array_keys($payload['data']); $headers[] = '_sync_source_id';
            $append = $client->append($spreadsheet_id, $sheet, $headers); if (is_wp_error($append)) { return $append; }
            $rows = array($headers);
        }
        if (!in_array('_sync_source_id', $headers, true)) {
            $headers[] = '_sync_source_id';
            // Add a source identifier column without rewriting the sheet.
            $col = $this->column_letter(count($headers));
            $put = $client->update_row($spreadsheet_id, $sheet, 1, $headers); if (is_wp_error($put)) { return $put; }
            $rows[0] = $headers;
        }
        $values = array(); foreach ($headers as $header) { $values[] = ($header === '_sync_source_id') ? $key : (isset($payload['data'][$header]) ? $payload['data'][$header] : ''); }
        $key_index = array_search('_sync_source_id', $headers, true); $target_row = 0;
        foreach ($rows as $index=>$row) { if ($index === 0) { continue; } if (isset($row[$key_index]) && (string)$row[$key_index] === (string)$key) { $target_row = $index + 1; break; } }
        return $target_row ? $client->update_row($spreadsheet_id, $sheet, $target_row, $values) : $client->append($spreadsheet_id, $sheet, $values);
    }
    private function column_letter($number) { $letter=''; while ($number > 0) { $mod = ($number - 1) % 26; $letter = chr(65 + $mod) . $letter; $number = (int)(($number - $mod) / 26); } return $letter; }
    public function log($type, $message) { $logs = get_option('wpgss_logs', array()); array_unshift($logs, array('time'=>current_time('mysql'),'type'=>sanitize_key($type),'message'=>sanitize_text_field($message))); update_option('wpgss_logs', array_slice($logs,0,100), false); }
    public function test_connection() {
        $s = $this->settings();
        if (empty($s['spreadsheet_id'])) { return new WP_Error('wpgss_sheet', 'Enter a Google spreadsheet URL or ID and save it before testing.'); }
        $client = new WPGSS_Google_Sheets($s);
        $result = $client->spreadsheet_info($s['spreadsheet_id']);
        if (is_wp_error($result)) {
            $this->log('connection_error', $result->get_error_message());
        }
        return $result;
    }
}
add_filter('cron_schedules', function($schedules) { $schedules['wpgss_minute'] = array('interval'=>60,'display'=>__('Every minute (Sheets Sync for Google)','sheets-sync-manager-for-google')); return $schedules; });
