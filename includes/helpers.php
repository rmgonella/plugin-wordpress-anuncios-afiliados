<?php
if (!defined('ABSPATH')) exit;

function ots_money($value) {
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function ots_get_option($key, $default = '') {
    if ($default === '' && function_exists('ots_default_settings')) {
        $defaults = ots_default_settings();
        if (array_key_exists($key, $defaults)) {
            $default = $defaults[$key];
        }
    }
    $options = get_option('ots_settings', []);
    return isset($options[$key]) && $options[$key] !== '' ? $options[$key] : $default;
}

function ots_get_user_ip() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $ip);
                $ip = trim($parts[0]);
            }
            return $ip;
        }
    }
    return '0.0.0.0';
}

function ots_cookie_hash() {
    $name = 'ots_visitor_id';
    if (empty($_COOKIE[$name])) {
        $value = wp_generate_uuid4();
        setcookie($name, $value, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[$name] = $value;
    }
    return hash('sha256', sanitize_text_field(wp_unslash($_COOKIE[$name])) . wp_salt('nonce'));
}



function ots_log_event($type, $object_type = '', $object_id = 0, $message = '', $context = []) {
    global $wpdb;
    $table = $wpdb->prefix . 'ots_event_logs';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) return false;

    return $wpdb->insert($table, [
        'event_type' => sanitize_key($type),
        'object_type' => sanitize_key($object_type),
        'object_id' => absint($object_id),
        'user_id' => get_current_user_id() ?: null,
        'ip_address' => ots_get_user_ip(),
        'user_agent' => sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'message' => sanitize_text_field($message),
        'context' => wp_json_encode($context),
        'created_at' => current_time('mysql'),
    ], ['%s','%s','%d','%d','%s','%s','%s','%s','%s']);
}

function ots_get_affiliate_ref_from_request() {
    $ref = absint($_REQUEST['ref'] ?? 0);
    if ($ref > 0) {
        setcookie('ots_affiliate_ref', (string) $ref, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE['ots_affiliate_ref'] = (string) $ref;
        return $ref;
    }
    return absint($_COOKIE['ots_affiliate_ref'] ?? 0);
}

function ots_status_label($status) {
    $labels = [
        'pending_payment' => 'Aguardando pagamento',
        'paid' => 'Pago',
        'pending_review' => 'Aguardando validação',
        'active' => 'Ativo',
        'paused' => 'Pausado',
        'finished' => 'Finalizado',
        'rejected' => 'Reprovado',
        'deleted' => 'Excluído',
    ];
    return $labels[$status] ?? ucfirst((string) $status);
}

function ots_redirect_with_message($url, $type, $message) {
    $url = add_query_arg([
        'ots_msg_type' => rawurlencode($type),
        'ots_msg' => rawurlencode($message),
    ], $url);
    wp_safe_redirect($url);
    exit;
}

function ots_flash_message() {
    if (empty($_GET['ots_msg'])) return '';
    $type = sanitize_key($_GET['ots_msg_type'] ?? 'info');
    $msg = sanitize_text_field(wp_unslash($_GET['ots_msg']));
    return '<div class="ots-alert ots-alert-' . esc_attr($type) . '">' . esc_html($msg) . '</div>';
}

function ots_render_sidebar() {
    return '<aside class="ots-sidebar"><h3>Menu</h3>' .
        '<a href="' . esc_url(home_url('/a/anuncios/')) . '">Anúncios</a>' .
        '<a href="' . esc_url(home_url('/a/usuarios/')) . '">Usuários</a>' .
        '<a href="' . esc_url(home_url('/a/compartilhe-na-redes-sociais/')) . '">Compartilhe na Redes Sociais</a>' .
        '<a href="' . esc_url(home_url('/a/meus-anuncios/')) . '">Meus Anúncios</a>' .
        '<a href="' . esc_url(home_url('/a/criar-anuncio/')) . '">Criar Anúncio</a>' .
        '<a href="' . esc_url(home_url('/a/programa-de-afiliados/')) . '">Programa de Afiliados</a>' .
        '<a href="' . esc_url(home_url('/a/alterar-senha/')) . '">Alterar Senha</a>' .
        '<a href="' . esc_url(wp_logout_url(home_url())) . '">Sair</a>' .
    '</aside>';
}

function ots_render_user_header() {
    if (!is_user_logged_in()) {
        return '<div class="ots-user-header">Olá visitante</div>';
    }
    $user = wp_get_current_user();
    $balance = function_exists('ots_get_user_balance') ? ots_get_user_balance($user->ID) : 0;
    return '<div class="ots-user-header">Olá ' . esc_html($user->display_name ?: $user->user_login) . ' <strong>Valor Ganho:</strong> ' . esc_html(ots_money($balance)) . '</div>';
}

function ots_layout_open() {
    return '<div class="ots-app">' . ots_render_sidebar() . '<main class="ots-main">' . ots_render_user_header() . '<hr class="ots-redline">' . ots_flash_message();
}

function ots_layout_close() {
    return '</main></div>';
}

function ots_admin_capability() {
    return 'manage_options';
}
