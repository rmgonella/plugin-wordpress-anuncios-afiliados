<?php
if (!defined('ABSPATH')) exit;

function ots_turnstile_enabled() {
    return ots_get_option('turnstile_site_key') && ots_get_option('turnstile_secret_key');
}

function ots_turnstile_widget() {
    $site_key = ots_get_option('turnstile_site_key');
    if (!$site_key) return '';
    return '<div class="cf-turnstile" data-sitekey="' . esc_attr($site_key) . '"></div><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}

function ots_verify_turnstile() {
    if (!ots_turnstile_enabled()) return true;
    $token = sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'] ?? ''));
    if (!$token) return false;
    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 20,
        'body' => [
            'secret' => ots_get_option('turnstile_secret_key'),
            'response' => $token,
            'remoteip' => ots_get_user_ip(),
        ],
    ]);
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($body['success']);
}

function ots_is_suspicious_user_agent() {
    $ua = strtolower(sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if (!$ua) return true;
    $blocked = ['bot', 'crawler', 'spider', 'curl', 'wget', 'python', 'scrapy'];
    foreach ($blocked as $needle) {
        if (strpos($ua, $needle) !== false) return true;
    }
    return false;
}
