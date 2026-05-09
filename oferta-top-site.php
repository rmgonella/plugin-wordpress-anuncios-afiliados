<?php
/**
 * Plugin Name: Oferta TOP Site - Sistema de Anúncios e Afiliados
 * Plugin URI: https://ofertopsite.com.br/
 * Description: Sistema completo de anúncios pagos, recarga por Mercado Pago/Pix, compartilhamento, contagem de impressões/cliques, comissão de afiliados e painel por shortcode.
 * Version: 1.2.1
 * Author: Oferta TOP Site
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: oferta-top-site
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OTS_VERSION', '1.2.1');
define('OTS_PLUGIN_FILE', __FILE__);
define('OTS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('OTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OTS_TABLE_VERSION', '1.2.1');

require_once OTS_PLUGIN_PATH . 'includes/install.php';
require_once OTS_PLUGIN_PATH . 'includes/helpers.php';
require_once OTS_PLUGIN_PATH . 'includes/settings.php';
require_once OTS_PLUGIN_PATH . 'includes/security.php';
require_once OTS_PLUGIN_PATH . 'includes/affiliate.php';
require_once OTS_PLUGIN_PATH . 'includes/payments.php';
require_once OTS_PLUGIN_PATH . 'includes/ads.php';
require_once OTS_PLUGIN_PATH . 'includes/tracking.php';
require_once OTS_PLUGIN_PATH . 'includes/shortcodes.php';
require_once OTS_PLUGIN_PATH . 'includes/admin.php';

register_activation_hook(__FILE__, 'ots_install_tables');
register_activation_hook(__FILE__, 'ots_activate_plugin');

function ots_activate_plugin() {
    ots_install_tables();
    if (function_exists('ots_ensure_default_settings')) {
        ots_ensure_default_settings();
    }
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_action('admin_init', function () {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    if (function_exists('ots_tables_need_install') && (ots_tables_need_install() || get_option('ots_table_version') !== OTS_TABLE_VERSION)) {
        ots_install_tables();
    }
});


add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('ots-public', OTS_PLUGIN_URL . 'assets/css/public.css', [], OTS_VERSION);
    wp_enqueue_script('ots-public', OTS_PLUGIN_URL . 'assets/js/public.js', [], OTS_VERSION, true);
    wp_localize_script('ots-public', 'otsPublic', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'impressionNonce' => wp_create_nonce('ots_track_impression'),
    ]);
});

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style('ots-admin', OTS_PLUGIN_URL . 'assets/css/admin.css', [], OTS_VERSION);
});

add_action('init', function () {
    add_rewrite_rule('^ots-click/?$', 'index.php?ots_click=1', 'top');
    add_rewrite_rule('^a/ots-click/?$', 'index.php?ots_click=1', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'ots_click';
    return $vars;
});

require_once OTS_PLUGIN_PATH . 'includes/page-router.php';
