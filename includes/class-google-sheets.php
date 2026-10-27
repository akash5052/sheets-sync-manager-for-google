<?php
if (!defined('ABSPATH')) { exit; }

/** Google Sheets API client using user-authorized OAuth 2.0 (no downloadable private key). */
class WPGSS_Google_Sheets {
    private $settings = array();
    private $token = '';
    public function __construct($settings = array()) {
        // Always load persisted settings so a partial/stale caller cannot hide OAuth tokens.
        if (is_string($settings)) { $settings = array('credentials_json' => $settings); }
        $saved = get_option('wpgss_settings', array());
        $saved = is_array($saved) ? $saved : array();
        foreach (array('oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email') as $key) {
            $v = get_option('wpgss_' . $key, null);
            if ($v !== null && $v !== '') { $saved[$key] = $v; }
        }
        $provided = is_array($settings) ? $settings : array();
        $this->settings = array_merge($saved, $provided);
        // Empty values supplied by an old caller must not erase valid saved OAuth values.
        foreach (array('oauth_client_id','oauth_client_secret','oauth_refresh_token','oauth_access_token','oauth_token_expires_at','google_email') as $key) {
            if (isset($provided[$key]) && $provided[$key] === '' && !empty($saved[$key])) { $this->settings[$key] = $saved[$key]; }
        }
    }
    public function is_configured() {
        $has_refresh = !empty($this->settings['oauth_refresh_token']);
        $has_live_access = !empty($this->settings['oauth_access_token'])
            && !empty($this->settings['oauth_token_expires_at'])
            && (int) $this->settings['oauth_token_expires_at'] > time() + 60;
        // The OAuth callback is the source of truth for authorization. A token may
        // already be valid even if credentials were subsequently edited or omitted.
        return ($has_refresh || $has_live_access);
    }
    public function list_worksheets($spreadsheet_id) {
        $info = $this->spreadsheet_info($spreadsheet_id);
        if (is_wp_error($info)) { return $info; }
        $worksheets = array();
        foreach (($info['sheets'] ?? array()) as $sheet) {
            if (!empty($sheet['properties']['title'])) { $worksheets[] = $sheet['properties']['title']; }
        }
        return array('title' => $info['properties']['title'] ?? '', 'worksheets' => $worksheets);
    }
    private function access_token() {
        if ($this->token) { return $this->token; }
        if (!empty($this->settings['oauth_access_token']) && !empty($this->settings['oauth_token_expires_at']) && (int) $this->settings['oauth_token_expires_at'] > time() + 60) {
            $this->token = (string) $this->settings['oauth_access_token'];
            return $this->token;
        }
        if (empty($this->settings['oauth_refresh_token'])) {
            return new WP_Error('wpgss_oauth', __('Google account authorization is not saved or the access token expired. Reconnect your Google account and confirm that a refresh token is saved.', 'sheets-sync-manager-for-google'));
        }
        if (empty($this->settings['oauth_client_id']) || empty($this->settings['oauth_client_secret'])) {
            return new WP_Error('wpgss_oauth_credentials', __('Google authorization exists, but the OAuth Client ID or Client Secret is missing from plugin settings. Re-enter both, save, then test again.', 'sheets-sync-manager-for-google'));
        }
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array(
                'client_id' => $this->settings['oauth_client_id'],
                'client_secret' => $this->settings['oauth_client_secret'],
                'refresh_token' => $this->settings['oauth_refresh_token'],
                'grant_type' => 'refresh_token',
            ),
        ));
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            $message = !empty($body['error_description']) ? sanitize_text_field($body['error_description']) : __('Google authorization expired or was revoked. Reconnect the Google account and confirm the OAuth consent screen is configured correctly.', 'sheets-sync-manager-for-google');
            return new WP_Error('wpgss_token', $message);
        }
        $this->token = sanitize_text_field($body['access_token']);
        // Persist refreshed access token to reduce repeated refresh requests.
        $saved = get_option('wpgss_settings', array());
        if (!is_array($saved)) { $saved = array(); }
        $saved['oauth_access_token'] = $this->token;
        $saved['oauth_token_expires_at'] = time() + max(60, absint($body['expires_in'] ?? 3600));
        update_option('wpgss_settings', $saved, false);
        update_option('wpgss_oauth_access_token', $saved['oauth_access_token'], false);
        update_option('wpgss_oauth_token_expires_at', $saved['oauth_token_expires_at'], false);
        $this->settings = array_merge($this->settings, $saved);
        return $this->token;
    }
    private function request($method, $path, $body = null) {
        $token = $this->access_token();
        if (is_wp_error($token)) { return $token; }
        $args = array('method'=>$method,'timeout'=>25,'headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'));
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $response = wp_remote_request('https://sheets.googleapis.com/v4/'.ltrim($path,'/'), $args);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response); $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            if (isset($data['error']['message'])) {
                $message = sanitize_text_field($data['error']['message']);
            } else {
                /* translators: %d is the HTTP response status code returned by Google Sheets API. */
                $message = sprintf(__('Google Sheets API returned HTTP %d.', 'sheets-sync-manager-for-google'), $code);
            }
            $reason = '';
            if (!empty($data['error']['errors'][0]['reason'])) { $reason = sanitize_key($data['error']['errors'][0]['reason']); }
            if ($reason !== '') { $message .= ' (Google reason: ' . $reason . ').'; }
            if ($code === 401) { $message .= ' Authorization is invalid or expired; reconnect Google.'; }
            elseif ($code === 403) { $message .= ' Check that Google Sheets API is enabled and the connected account has Editor access to this spreadsheet.'; }
            elseif ($code === 404) { $message .= ' Check the spreadsheet ID and confirm the connected account can access the file.'; }
            return new WP_Error('wpgss_api', $message, array('status'=>$code, 'google_reason'=>$reason));
        }
        return is_array($data) ? $data : array();
    }
    public function spreadsheet_info($spreadsheet_id) { return $this->request('GET','spreadsheets/'.rawurlencode($spreadsheet_id).'?fields=spreadsheetId,properties(title),sheets(properties(title,sheetId))'); }
    public function append($spreadsheet_id,$sheet,$values) { $range=rawurlencode($this->quote_sheet($sheet).'!A:ZZ'); return $this->request('POST','spreadsheets/'.rawurlencode($spreadsheet_id).'/values/'.$range.':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',array('values'=>array($values))); }
    public function read_rows($spreadsheet_id,$sheet) { $range=rawurlencode($this->quote_sheet($sheet).'!A:ZZ'); $result=$this->request('GET','spreadsheets/'.rawurlencode($spreadsheet_id).'/values/'.$range); if(is_wp_error($result)){return $result;} return isset($result['values'])?$result['values']:array(); }
    public function update_row($spreadsheet_id,$sheet,$row_number,$values) { $range=rawurlencode($this->quote_sheet($sheet).'!A'.absint($row_number).':ZZ'.absint($row_number)); return $this->request('PUT','spreadsheets/'.rawurlencode($spreadsheet_id).'/values/'.$range.'?valueInputOption=RAW',array('values'=>array($values))); }
    public function create_sheet($spreadsheet_id,$title) { return $this->request('POST','spreadsheets/'.rawurlencode($spreadsheet_id).':batchUpdate',array('requests'=>array(array('addSheet'=>array('properties'=>array('title'=>$title)))))); }
    private function quote_sheet($sheet) { return "'".str_replace("'","''",$sheet)."'"; }
}
