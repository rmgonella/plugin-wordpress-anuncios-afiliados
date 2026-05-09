<?php
if (!defined('ABSPATH')) exit;

function ots_add_affiliate_commission($user_id, $ad_id, $amount, $description = '') {
    if (!$user_id || $amount <= 0) return false;
    global $wpdb;
    return $wpdb->insert($wpdb->prefix . 'ots_wallet', [
        'user_id' => absint($user_id),
        'ad_id' => $ad_id ? absint($ad_id) : null,
        'type' => 'click_commission',
        'amount' => (float) $amount,
        'status' => 'approved',
        'description' => $description ?: 'Comissão por clique válido',
    ], ['%d', '%d', '%s', '%f', '%s', '%s']);
}

function ots_get_user_balance($user_id) {
    global $wpdb;
    $income = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}ots_wallet WHERE user_id=%d AND status='approved'", $user_id));
    $withdrawals = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}ots_withdrawals WHERE user_id=%d AND status IN ('pending','paid')", $user_id));
    return max(0, $income - $withdrawals);
}

add_action('init', 'ots_handle_withdrawal_request');
function ots_handle_withdrawal_request() {
    if (empty($_POST['ots_withdrawal_submit'])) return;
    if (!is_user_logged_in()) wp_die('Você precisa estar logado.');
    if (empty($_POST['ots_withdrawal_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ots_withdrawal_nonce'])), 'ots_withdrawal')) wp_die('Erro de segurança.');

    $user_id = get_current_user_id();
    $amount = (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['amount'] ?? '0')));
    $pix = sanitize_text_field(wp_unslash($_POST['pix_key'] ?? ''));
    $whatsapp = sanitize_text_field(wp_unslash($_POST['whatsapp'] ?? ''));
    $balance = ots_get_user_balance($user_id);
    $minimum = function_exists('ots_withdrawal_min_amount') ? ots_withdrawal_min_amount() : 30;

    if ($amount < $minimum) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/programa-de-afiliados/'), 'error', 'O valor mínimo para saque é de ' . ots_money($minimum) . '.');
    }

    if ($amount > $balance) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/programa-de-afiliados/'), 'error', 'Valor de saque superior ao saldo disponível.');
    }
    if (!$pix) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/programa-de-afiliados/'), 'error', 'Informe uma chave Pix.');
    }

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'ots_withdrawals', [
        'user_id' => $user_id,
        'amount' => $amount,
        'pix_key' => $pix,
        'whatsapp' => $whatsapp,
        'status' => 'pending',
    ]);
    ots_redirect_with_message(home_url('/a/programa-de-afiliados/'), 'success', 'Solicitação de saque enviada para análise.');
}
