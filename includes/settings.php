<?php
if (!defined('ABSPATH')) exit;

function ots_default_settings() {
    return [
        'mp_access_token' => '',
        'mp_public_key' => '',
        'turnstile_site_key' => '',
        'turnstile_secret_key' => '',
        'ad_price' => 60,
        'ad_clicks' => 60,
        'click_commission' => 0.50,
        'withdrawal_min_amount' => 30,
        'admin_pix_key' => '',
        'admin_whatsapp' => '',
        'payment_email_subject' => 'Pagamento do seu anúncio - Oferta TOP Site',
    ];
}

function ots_ensure_default_settings() {
    $defaults = ots_default_settings();
    $current = get_option('ots_settings', []);
    if (!is_array($current)) {
        $current = [];
    }

    $changed = false;
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $current) || $current[$key] === '') {
            $current[$key] = $value;
            $changed = true;
        }
    }

    if ($changed) {
        update_option('ots_settings', $current);
    }
}

add_action('admin_init', function () {
    ots_ensure_default_settings();
    register_setting('ots_settings_group', 'ots_settings', 'ots_sanitize_settings');
});

function ots_sanitize_settings($input) {
    $defaults = ots_default_settings();
    $current = get_option('ots_settings', []);
    if (!is_array($current)) $current = [];
    $clean = [];

    // Campos sensíveis: se o administrador deixar vazio, preserva o valor já salvo no banco.
    // As credenciais não ficam mais gravadas no código-fonte do plugin.
    $clean['mp_access_token'] = isset($input['mp_access_token']) && $input['mp_access_token'] !== ''
        ? sanitize_text_field($input['mp_access_token'])
        : sanitize_text_field($current['mp_access_token'] ?? '');
    $clean['mp_public_key'] = isset($input['mp_public_key']) && $input['mp_public_key'] !== ''
        ? sanitize_text_field($input['mp_public_key'])
        : sanitize_text_field($current['mp_public_key'] ?? '');
    $clean['turnstile_site_key'] = isset($input['turnstile_site_key']) && $input['turnstile_site_key'] !== ''
        ? sanitize_text_field($input['turnstile_site_key'])
        : sanitize_text_field($current['turnstile_site_key'] ?? '');
    $clean['turnstile_secret_key'] = isset($input['turnstile_secret_key']) && $input['turnstile_secret_key'] !== ''
        ? sanitize_text_field($input['turnstile_secret_key'])
        : sanitize_text_field($current['turnstile_secret_key'] ?? '');

    $clean['ad_price'] = max(60, (float) str_replace(',', '.', $input['ad_price'] ?? $defaults['ad_price']));
    $clean['ad_clicks'] = max(1, absint($input['ad_clicks'] ?? $defaults['ad_clicks']));
    $clean['click_commission'] = max(0, (float) str_replace(',', '.', $input['click_commission'] ?? $defaults['click_commission']));
    $clean['withdrawal_min_amount'] = max(30, (float) str_replace(',', '.', $input['withdrawal_min_amount'] ?? $defaults['withdrawal_min_amount']));
    $clean['admin_pix_key'] = sanitize_text_field($input['admin_pix_key'] ?? '');
    $clean['admin_whatsapp'] = sanitize_text_field($input['admin_whatsapp'] ?? '');
    $clean['payment_email_subject'] = sanitize_text_field($input['payment_email_subject'] ?? $defaults['payment_email_subject']);
    return $clean;
}

function ots_default_price() { return (float) ots_get_option('ad_price', ots_default_settings()['ad_price']); }
function ots_default_clicks() { return (int) ots_get_option('ad_clicks', ots_default_settings()['ad_clicks']); }
function ots_click_commission() { return (float) ots_get_option('click_commission', ots_default_settings()['click_commission']); }
function ots_withdrawal_min_amount() { return (float) ots_get_option('withdrawal_min_amount', ots_default_settings()['withdrawal_min_amount']); }
