<?php
if (!defined('ABSPATH')) exit;

function ots_tables_need_install() {
    global $wpdb;
    $ads = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'ots_ads'));
    return $ads !== $wpdb->prefix . 'ots_ads';
}

function ots_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $sql_ads = "CREATE TABLE {$wpdb->prefix}ots_ads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        title VARCHAR(120) NOT NULL,
        link TEXT NOT NULL,
        image_url TEXT NULL,
        email VARCHAR(190) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_clicks INT UNSIGNED NOT NULL DEFAULT 0,
        remaining_clicks INT UNSIGNED NOT NULL DEFAULT 0,
        clicks INT UNSIGNED NOT NULL DEFAULT 0,
        impressions INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT 'pending_payment',
        payment_method VARCHAR(40) NULL,
        payment_id VARCHAR(190) NULL,
        payment_url TEXT NULL,
        payment_status VARCHAR(80) NULL,
        payment_instructions_sent TINYINT(1) NOT NULL DEFAULT 0,
        rejection_reason TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY status (status),
        KEY email (email)
    ) $charset;";

    $sql_clicks = "CREATE TABLE {$wpdb->prefix}ots_clicks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ad_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        ip_address VARCHAR(80) NULL,
        user_agent TEXT NULL,
        cookie_hash VARCHAR(128) NULL,
        clicked_at DATETIME NOT NULL,
        visitor_hash VARCHAR(64) NULL,
        tracking_key VARCHAR(64) NULL,
        PRIMARY KEY (id),
        KEY ad_id (ad_id),
        KEY user_id (user_id),
        KEY clicked_at (clicked_at),
        KEY visitor_hash (visitor_hash),
        UNIQUE KEY tracking_key (tracking_key)
    ) $charset;";

    $sql_impressions = "CREATE TABLE {$wpdb->prefix}ots_impressions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ad_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        ip_address VARCHAR(80) NULL,
        user_agent TEXT NULL,
        cookie_hash VARCHAR(128) NULL,
        viewed_at DATETIME NOT NULL,
        visitor_hash VARCHAR(64) NULL,
        tracking_key VARCHAR(64) NULL,
        PRIMARY KEY (id),
        KEY ad_id (ad_id),
        KEY user_id (user_id),
        KEY viewed_at (viewed_at),
        KEY visitor_hash (visitor_hash),
        UNIQUE KEY tracking_key (tracking_key)
    ) $charset;";

    $sql_wallet = "CREATE TABLE {$wpdb->prefix}ots_wallet (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        ad_id BIGINT UNSIGNED NULL,
        type VARCHAR(60) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(40) NOT NULL DEFAULT 'approved',
        description TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY ad_id (ad_id),
        KEY status (status)
    ) $charset;";

    $sql_withdrawals = "CREATE TABLE {$wpdb->prefix}ots_withdrawals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        pix_key VARCHAR(255) NOT NULL,
        whatsapp VARCHAR(80) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset;";


    $sql_click_locks = "CREATE TABLE {$wpdb->prefix}ots_click_locks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        visitor_hash VARCHAR(64) NOT NULL,
        lock_key VARCHAR(64) NOT NULL,
        first_ad_id BIGINT UNSIGNED NULL,
        affiliate_user_id BIGINT UNSIGNED NULL,
        ip_address VARCHAR(80) NULL,
        user_agent TEXT NULL,
        cookie_hash VARCHAR(128) NULL,
        reason VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY lock_key (lock_key),
        KEY visitor_hash (visitor_hash),
        KEY first_ad_id (first_ad_id),
        KEY affiliate_user_id (affiliate_user_id)
    ) $charset;";

    $sql_event_logs = "CREATE TABLE {$wpdb->prefix}ots_event_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(80) NOT NULL,
        object_type VARCHAR(80) NULL,
        object_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NULL,
        ip_address VARCHAR(80) NULL,
        user_agent TEXT NULL,
        message TEXT NULL,
        context LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY event_type (event_type),
        KEY object_type (object_type),
        KEY object_id (object_id),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) $charset;";

    dbDelta($sql_ads);
    dbDelta($sql_clicks);
    dbDelta($sql_impressions);
    dbDelta($sql_wallet);
    dbDelta($sql_withdrawals);
    dbDelta($sql_click_locks);
    dbDelta($sql_event_logs);

    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'rejection_reason', 'TEXT NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'payment_instructions_sent', 'TINYINT(1) NOT NULL DEFAULT 0');

    ots_maybe_add_column($wpdb->prefix . 'ots_clicks', 'visitor_hash', 'VARCHAR(64) NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_clicks', 'tracking_key', 'VARCHAR(64) NULL');
    ots_maybe_add_index($wpdb->prefix . 'ots_clicks', 'visitor_hash', 'KEY visitor_hash (visitor_hash)');
    ots_maybe_add_index($wpdb->prefix . 'ots_clicks', 'tracking_key', 'UNIQUE KEY tracking_key (tracking_key)');

    ots_maybe_add_column($wpdb->prefix . 'ots_impressions', 'visitor_hash', 'VARCHAR(64) NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_impressions', 'tracking_key', 'VARCHAR(64) NULL');
    ots_maybe_add_index($wpdb->prefix . 'ots_impressions', 'visitor_hash', 'KEY visitor_hash (visitor_hash)');
    ots_maybe_add_index($wpdb->prefix . 'ots_impressions', 'tracking_key', 'UNIQUE KEY tracking_key (tracking_key)');


    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'mp_payment_id', 'VARCHAR(190) NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'payment_amount', 'DECIMAL(12,2) NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'payment_currency', 'VARCHAR(10) NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'paid_at', 'DATETIME NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'deleted_at', 'DATETIME NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_ads', 'deleted_by', 'BIGINT UNSIGNED NULL');
    ots_maybe_add_index($wpdb->prefix . 'ots_ads', 'mp_payment_id', 'KEY mp_payment_id (mp_payment_id)');

    ots_maybe_add_column($wpdb->prefix . 'ots_clicks', 'affiliate_user_id', 'BIGINT UNSIGNED NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_clicks', 'commission_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    ots_maybe_add_column($wpdb->prefix . 'ots_clicks', 'commission_status', "VARCHAR(40) NOT NULL DEFAULT 'none'");
    ots_maybe_add_index($wpdb->prefix . 'ots_clicks', 'affiliate_user_id', 'KEY affiliate_user_id (affiliate_user_id)');

    ots_maybe_add_column($wpdb->prefix . 'ots_withdrawals', 'paid_at', 'DATETIME NULL');
    ots_maybe_add_column($wpdb->prefix . 'ots_withdrawals', 'paid_by', 'BIGINT UNSIGNED NULL');

    update_option('ots_table_version', defined('OTS_TABLE_VERSION') ? OTS_TABLE_VERSION : '1.0.6');
}

function ots_maybe_add_column($table, $column, $definition) {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . esc_sql($table) . ' LIKE %s', $column));
    if (!$exists) {
        $wpdb->query('ALTER TABLE ' . esc_sql($table) . ' ADD ' . esc_sql($column) . ' ' . $definition);
    }
}


function ots_maybe_add_index($table, $index_name, $definition) {
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW INDEX FROM ' . esc_sql($table) . ' WHERE Key_name = %s', $index_name));
    if (!$exists) {
        $wpdb->query('ALTER TABLE ' . esc_sql($table) . ' ADD ' . $definition);
    }
}
