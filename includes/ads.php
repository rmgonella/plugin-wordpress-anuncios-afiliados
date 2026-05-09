<?php
if (!defined('ABSPATH')) exit;

add_action('init', 'ots_handle_create_ad');
add_action('init', 'ots_handle_update_rejected_ad');
add_action('init', 'ots_handle_user_toggle_ad_pause');

function ots_prepare_ad_file_upload($field_name, $required = true) {
    if (empty($_FILES[$field_name]['name'])) {
        return $required ? new WP_Error('missing_image', 'Envie uma imagem do anúncio.') : '';
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $file = $_FILES[$field_name];

    if (!empty($file['error'])) {
        return new WP_Error('upload_error', 'Erro no envio da imagem. Código: ' . absint($file['error']));
    }

    $max_size = 3 * MB_IN_BYTES;
    if (!empty($file['size']) && (int) $file['size'] > $max_size) {
        return new WP_Error('image_too_large', 'A imagem pode ter no máximo 3MB.');
    }

    $original_name = sanitize_file_name($file['name']);
    if (preg_match('/\.(php|phtml|phar|js|html?|svg)(\.|$)/i', $original_name)) {
        return new WP_Error('invalid_image_name', 'Nome ou extensão de arquivo não permitida. Envie JPG, PNG ou WEBP.');
    }

    $check = wp_check_filetype_and_ext($file['tmp_name'], $original_name, [
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'webp'     => 'image/webp',
    ]);

    if (empty($check['ext']) || empty($check['type'])) {
        return new WP_Error('invalid_image', 'Arquivo inválido. Envie uma imagem JPG, PNG ou WEBP válida.');
    }

    $image_size = @getimagesize($file['tmp_name']);
    if (!$image_size || empty($image_size[0]) || empty($image_size[1])) {
        return new WP_Error('invalid_image_binary', 'O arquivo enviado não parece ser uma imagem válida.');
    }

    $file['name'] = 'ots-ad-' . time() . '-' . wp_generate_password(8, false, false) . '.' . $check['ext'];
    $_FILES[$field_name] = $file;

    $upload = wp_handle_upload($file, [
        'test_form' => false,
        'mimes' => [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'webp'     => 'image/webp',
        ],
    ]);

    if (!$upload || !empty($upload['error'])) {
        return new WP_Error('upload_error', 'Erro ao enviar imagem: ' . ($upload['error'] ?? ''));
    }

    return esc_url_raw($upload['url']);
}


/**
 * Remove com segurança uma imagem de anúncio do diretório de uploads do WordPress.
 *
 * O plugin armazena a URL da imagem no banco. Por segurança, esta função só apaga
 * arquivos que estejam dentro do diretório oficial de uploads do site, evitando
 * remoção acidental de arquivos externos, de tema, plugins ou CDN.
 */
function ots_delete_ad_image_from_server($image_url) {
    $image_url = esc_url_raw($image_url);
    if (!$image_url) {
        return false;
    }

    $uploads = wp_get_upload_dir();
    if (empty($uploads['baseurl']) || empty($uploads['basedir'])) {
        return false;
    }

    $base_url = untrailingslashit($uploads['baseurl']);
    $base_dir = wp_normalize_path(untrailingslashit($uploads['basedir']));
    $image_url_no_query = strtok($image_url, '?');

    if (strpos($image_url_no_query, $base_url . '/') !== 0) {
        return false;
    }

    $relative_path = ltrim(substr($image_url_no_query, strlen($base_url)), '/');
    $relative_path = rawurldecode($relative_path);
    $file_path = wp_normalize_path($base_dir . '/' . $relative_path);

    if (strpos($file_path, $base_dir . '/') !== 0 || !is_file($file_path) || !is_writable($file_path)) {
        return false;
    }

    return @unlink($file_path);
}

function ots_calculate_clicks_from_amount($amount) {
    $minimum_amount = ots_default_price();
    $base_clicks = ots_default_clicks();
    return max(1, (int) floor(((float) $amount / $minimum_amount) * $base_clicks));
}

function ots_handle_create_ad() {
    if (empty($_POST['ots_submit_ad'])) return;
    if (empty($_POST['ots_create_ad_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ots_create_ad_nonce'])), 'ots_create_ad')) wp_die('Erro de segurança.');
    if (!ots_verify_turnstile()) ots_redirect_with_message(wp_get_referer() ?: home_url('/a/criar-anuncio/'), 'error', 'Confirme o captcha para continuar.');

    $title = sanitize_text_field(wp_unslash($_POST['ots_title'] ?? ''));
    $link = esc_url_raw(wp_unslash($_POST['ots_link'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['ots_email'] ?? ''));
    $payment_method = sanitize_key(wp_unslash($_POST['ots_payment_method'] ?? 'pix_manual'));
    if (!in_array($payment_method, ['mercado_pago', 'pix_manual'], true)) $payment_method = 'pix_manual';

    if (!$title || !$link || !$email) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/criar-anuncio/'), 'error', 'Preencha todos os campos obrigatórios.');
    }
    if (mb_strlen($title) > 85) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/criar-anuncio/'), 'error', 'O título pode ter no máximo 85 caracteres.');
    }

    $image_url = ots_prepare_ad_file_upload('ots_image', true);
    if (is_wp_error($image_url)) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/criar-anuncio/'), 'error', $image_url->get_error_message());
    }

    global $wpdb;
    $minimum_amount = ots_default_price();
    $amount_raw = sanitize_text_field(wp_unslash($_POST['ots_amount'] ?? $minimum_amount));
    $amount = (float) str_replace(',', '.', $amount_raw);
    if ($amount < $minimum_amount) {
        ots_redirect_with_message(wp_get_referer() ?: home_url('/a/criar-anuncio/'), 'error', 'O valor mínimo da recarga é ' . ots_money($minimum_amount) . '. Informe um valor igual ou maior.');
    }
    $clicks = ots_calculate_clicks_from_amount($amount);

    $owner_user_id = get_current_user_id() ?: 0;
    if (!$owner_user_id && $email) {
        $owner = get_user_by('email', $email);
        if ($owner && !empty($owner->ID)) $owner_user_id = (int) $owner->ID;
    }

    $wpdb->insert($wpdb->prefix . 'ots_ads', [
        'user_id' => $owner_user_id ?: null,
        'title' => $title,
        'link' => $link,
        'image_url' => $image_url,
        'email' => $email,
        'amount' => $amount,
        'total_clicks' => $clicks,
        'remaining_clicks' => $clicks,
        'status' => 'pending_payment',
        'payment_method' => $payment_method,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);
    $ad_id = (int) $wpdb->insert_id;

    if ($payment_method === 'mercado_pago') {
        $payment = ots_create_payment($ad_id, $amount, $email);
        $wpdb->update($wpdb->prefix . 'ots_ads', [
            'payment_id' => $payment['payment_id'] ?? '',
            'payment_url' => $payment['url'] ?? '',
            'payment_status' => !empty($payment['ok']) ? 'created' : 'error',
            'updated_at' => current_time('mysql'),
        ], ['id' => $ad_id]);
        ots_send_payment_email($ad_id, 'mercado_pago');
        if (!empty($payment['ok']) && !empty($payment['url'])) {
            ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'success', 'Anúncio cadastrado. Enviamos o link de pagamento para o e-mail informado. Você também pode pagar em Meus Anúncios.');
        }
        ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'error', 'Anúncio cadastrado, mas o Mercado Pago não gerou o link. Verifique o Access Token ou use Pix manual.');
    }

    ots_send_payment_email($ad_id, 'pix_manual');
    ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'success', 'Anúncio cadastrado. Enviamos as instruções de pagamento para o e-mail informado.');
}

function ots_user_can_edit_ad($ad) {
    if (!$ad || !is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;
    $user = wp_get_current_user();
    return ((int) $ad->user_id === get_current_user_id() || (!empty($ad->email) && strtolower($ad->email) === strtolower($user->user_email))) && $ad->status === 'rejected';
}


function ots_user_can_manage_ad_status($ad) {
    if (!$ad || !is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;
    $user = wp_get_current_user();
    return ((int) $ad->user_id === get_current_user_id() || (!empty($ad->email) && strtolower($ad->email) === strtolower($user->user_email)));
}

function ots_handle_user_toggle_ad_pause() {
    if (empty($_POST['ots_toggle_ad_pause'])) return;
    if (empty($_POST['ots_toggle_ad_pause_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ots_toggle_ad_pause_nonce'])), 'ots_toggle_ad_pause')) wp_die('Erro de segurança.');
    if (!is_user_logged_in()) wp_die('Você precisa estar logado.');

    global $wpdb;
    $ad_id = absint($_POST['ots_ad_id'] ?? 0);
    $requested_action = sanitize_key(wp_unslash($_POST['ots_pause_action'] ?? ''));
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", $ad_id));

    if (!ots_user_can_manage_ad_status($ad)) {
        ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'error', 'Você não tem permissão para alterar este anúncio.');
    }

    if ($requested_action === 'pause' && $ad->status === 'active') {
        $wpdb->update($wpdb->prefix . 'ots_ads', [
            'status' => 'paused',
            'updated_at' => current_time('mysql'),
        ], ['id' => $ad_id]);
        ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'success', 'Anúncio pausado com sucesso. Ele não será exibido enquanto estiver pausado.');
    }

    if ($requested_action === 'resume' && $ad->status === 'paused') {
        if ((int) $ad->remaining_clicks <= 0) {
            $wpdb->update($wpdb->prefix . 'ots_ads', [
                'status' => 'finished',
                'updated_at' => current_time('mysql'),
            ], ['id' => $ad_id]);
            ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'error', 'Este anúncio não pode ser despausado porque os cliques acabaram.');
        }

        $wpdb->update($wpdb->prefix . 'ots_ads', [
            'status' => 'active',
            'updated_at' => current_time('mysql'),
        ], ['id' => $ad_id]);
        ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'success', 'Anúncio despausado com sucesso. Ele voltou a ser exibido.');
    }

    ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'error', 'Este anúncio não está disponível para esta ação.');
}

function ots_handle_update_rejected_ad() {
    if (empty($_POST['ots_update_ad'])) return;
    if (empty($_POST['ots_update_ad_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ots_update_ad_nonce'])), 'ots_update_ad')) wp_die('Erro de segurança.');
    if (!is_user_logged_in()) wp_die('Você precisa estar logado.');

    global $wpdb;
    $ad_id = absint($_POST['ots_ad_id'] ?? 0);
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", $ad_id));
    if (!ots_user_can_edit_ad($ad)) {
        ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'error', 'Este anúncio não está disponível para edição.');
    }

    $title = sanitize_text_field(wp_unslash($_POST['ots_title'] ?? ''));
    $link = esc_url_raw(wp_unslash($_POST['ots_link'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['ots_email'] ?? ''));
    if (!$title || !$link || !$email || mb_strlen($title) > 85) {
        ots_redirect_with_message(add_query_arg('ots_edit_ad', $ad_id, home_url('/a/criar-anuncio/')), 'error', 'Revise título, link e e-mail. O título deve ter até 85 caracteres.');
    }

    $data = [
        'title' => $title,
        'link' => $link,
        'email' => $email,
        'status' => ($ad->payment_status === 'approved' || in_array($ad->status, ['paid', 'active', 'rejected'], true)) ? 'pending_review' : 'pending_payment',
        'rejection_reason' => '',
        'updated_at' => current_time('mysql'),
    ];

    $new_image = ots_prepare_ad_file_upload('ots_image', false);
    if (is_wp_error($new_image)) {
        ots_redirect_with_message(add_query_arg('ots_edit_ad', $ad_id, home_url('/a/criar-anuncio/')), 'error', $new_image->get_error_message());
    }
    if ($new_image) {
        $data['image_url'] = $new_image;
    }

    $updated = $wpdb->update($wpdb->prefix . 'ots_ads', $data, ['id' => $ad_id]);

    // Se o usuário trocou a imagem do anúncio, remove a imagem antiga do servidor
    // para não deixar arquivos órfãos ocupando espaço.
    if ($updated !== false && $new_image && !empty($ad->image_url) && $new_image !== $ad->image_url) {
        ots_delete_ad_image_from_server($ad->image_url);
    }

    ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'success', 'Anúncio editado e reenviado para nova validação.');
}

function ots_get_active_ads($limit = 20, $random = false) {
    global $wpdb;
    $order = $random ? 'RAND()' : 'created_at DESC';
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE status='active' AND remaining_clicks > 0 ORDER BY $order LIMIT %d", absint($limit)));
}

function ots_maybe_finish_ad($ad_id) {
    global $wpdb;
    $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT remaining_clicks FROM {$wpdb->prefix}ots_ads WHERE id=%d", $ad_id));
    if ($remaining <= 0) {
        $wpdb->update($wpdb->prefix . 'ots_ads', ['status' => 'finished', 'updated_at' => current_time('mysql')], ['id' => $ad_id]);
    }
}
