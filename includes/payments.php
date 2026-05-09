<?php
if (!defined('ABSPATH')) exit;


/**
 * Verifica se uma URL do Mercado Pago aponta para ambiente sandbox/teste.
 */
function ots_is_mercadopago_sandbox_url($url) {
    $url = (string) $url;
    return $url !== '' && (stripos($url, 'sandbox') !== false || stripos($url, 'test_user') !== false || stripos($url, 'test') !== false && stripos($url, 'mercadopago') !== false);
}

/**
 * Cria uma nova preferência em produção, salva no anúncio e retorna o resultado.
 * Usado para evitar reaproveitar links antigos criados em sandbox.
 */
function ots_regenerate_ad_payment_link($ad_id) {
    global $wpdb;
    $ad_id = absint($ad_id);
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", $ad_id));
    if (!$ad) {
        return new WP_Error('ots_ad_not_found', 'Anúncio não encontrado.');
    }
    if ($ad->payment_method !== 'mercado_pago') {
        return new WP_Error('ots_invalid_method', 'Este anúncio não usa Mercado Pago.');
    }
    if (!in_array($ad->status, ['pending_payment'], true)) {
        return new WP_Error('ots_invalid_status', 'Este anúncio não está aguardando pagamento.');
    }

    $payment = ots_create_payment($ad_id, (float) $ad->amount, $ad->email);
    $wpdb->update($wpdb->prefix . 'ots_ads', [
        'payment_id' => $payment['payment_id'] ?? '',
        'payment_url' => $payment['url'] ?? '',
        'payment_status' => !empty($payment['ok']) ? 'created' : 'error',
        'updated_at' => current_time('mysql'),
    ], ['id' => $ad_id]);

    if (empty($payment['ok']) || empty($payment['url'])) {
        return new WP_Error('ots_mp_link_error', $payment['message'] ?? 'Não foi possível gerar o link de pagamento em produção.');
    }
    if (ots_is_mercadopago_sandbox_url($payment['url'])) {
        return new WP_Error('ots_mp_sandbox_link', 'O Mercado Pago retornou um link de sandbox. Confira se o Access Token salvo nas configurações é de produção e começa com APP_USR.');
    }
    return $payment;
}

add_action('init', 'ots_handle_mp_payment_redirect');
function ots_handle_mp_payment_redirect() {
    if (empty($_GET['ots_pay_ad'])) return;
    $ad_id = absint($_GET['ots_pay_ad']);
    if (!$ad_id || empty($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ots_pay_ad_' . $ad_id)) {
        wp_die('Erro de segurança. Atualize a página e tente novamente.');
    }

    global $wpdb;
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", $ad_id));
    if (!$ad || !function_exists('ots_user_can_manage_ad_status') || !ots_user_can_manage_ad_status($ad)) {
        wp_die('Você não tem permissão para pagar este anúncio.');
    }

    $payment = ots_regenerate_ad_payment_link($ad_id);
    if (is_wp_error($payment)) {
        ots_redirect_with_message(home_url('/a/meus-anuncios/'), 'error', $payment->get_error_message());
    }
    // Importante: Mercado Pago é um domínio externo.
    // wp_safe_redirect() bloqueia hosts externos não liberados e usa admin_url() como fallback,
    // causando redirecionamento indevido para /wp-admin.
    // Aqui o link já foi validado, sanitizado e bloqueado caso seja sandbox/teste.
    $redirect_url = esc_url_raw($payment['url']);
    wp_redirect($redirect_url, 302, 'Oferta TOP Site');
    exit;
}

/**
 * Cria uma preferência de pagamento no Mercado Pago.
 * Retorna array padronizado para o fluxo do anúncio.
 */
function ots_create_payment($ad_id, $amount, $email) {
    $token = ots_get_option('mp_access_token');
    if (!$token) {
        return [
            'ok' => false,
            'method' => 'mercado_pago',
            'url' => '',
            'payment_id' => '',
            'message' => 'Mercado Pago não configurado. Informe o Access Token em Oferta TOP Site > Configurações.'
        ];
    }

    $body = [
        'items' => [[
            'title' => 'Recarga de anúncio - Oferta TOP Site #' . absint($ad_id),
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => (float) $amount,
        ]],
        'payer' => ['email' => $email],
        'external_reference' => (string) $ad_id,
        'notification_url' => rest_url('ots/v1/mercadopago-webhook'),
        'back_urls' => [
            'success' => home_url('/a/meus-anuncios/?payment=success&ad_id=' . absint($ad_id)),
            'failure' => home_url('/a/criar-anuncio/?payment=failure&ad_id=' . absint($ad_id)),
            'pending' => home_url('/a/meus-anuncios/?payment=pending&ad_id=' . absint($ad_id)),
        ],
        'auto_return' => 'approved',
        'statement_descriptor' => 'OFERTA TOP SITE',
    ];

    $response = wp_remote_post('https://api.mercadopago.com/checkout/preferences', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($body),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'method' => 'mercado_pago',
            'url' => '',
            'payment_id' => '',
            'message' => $response->get_error_message(),
        ];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $url = $data['init_point'] ?? '';
    $payment_id = $data['id'] ?? '';

    if (!$url) {
        return [
            'ok' => false,
            'method' => 'mercado_pago',
            'url' => '',
            'payment_id' => sanitize_text_field((string) $payment_id),
            'message' => 'O Mercado Pago não retornou o link de pagamento. Confira o Access Token.',
        ];
    }

    return [
        'ok' => true,
        'method' => 'mercado_pago',
        'url' => esc_url_raw($url),
        'payment_id' => sanitize_text_field((string) $payment_id),
        'message' => 'Link de pagamento gerado com sucesso.',
    ];
}

function ots_payment_method_label($method) {
    $labels = [
        'mercado_pago' => 'Mercado Pago',
        'pix_manual' => 'Pix manual',
    ];
    return $labels[$method] ?? 'Não informado';
}

function ots_send_payment_email($ad_id, $method = '') {
    global $wpdb;
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", absint($ad_id)));
    if (!$ad || empty($ad->email)) {
        return false;
    }

    $method = $method ?: ($ad->payment_method ?: 'pix_manual');
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = ots_get_option('payment_email_subject', 'Pagamento do seu anúncio - Oferta TOP Site');
    $amount = ots_money($ad->amount);
    $admin_pix = ots_get_option('admin_pix_key', '');
    $admin_whatsapp = ots_get_option('admin_whatsapp', '');
    $my_ads_url = home_url('/a/meus-anuncios/');

    $lines = [];
    $lines[] = 'Olá!';
    $lines[] = '';
    $lines[] = 'Recebemos o cadastro do seu anúncio no ' . $site_name . '.';
    $lines[] = '';
    $lines[] = 'Anúncio: ' . $ad->title;
    $lines[] = 'Valor: ' . $amount;
    $lines[] = 'Cliques contratados: ' . (int) $ad->total_clicks;
    $lines[] = 'Forma de pagamento escolhida: ' . ots_payment_method_label($method);
    $lines[] = '';

    if ($method === 'mercado_pago') {
        if (!empty($ad->payment_url)) {
            $lines[] = 'Para pagar com Mercado Pago/Pix/cartão, acesse o link abaixo:';
            $lines[] = $ad->payment_url;
        } else {
            $lines[] = 'Não foi possível gerar automaticamente o link do Mercado Pago.';
            if ($admin_pix) {
                $lines[] = 'Você pode pagar via Pix usando a chave: ' . $admin_pix;
            }
        }
    } else {
        $lines[] = 'Para pagar via Pix manual, use a chave abaixo:';
        $lines[] = 'Chave Pix: ' . ($admin_pix ?: 'Chave Pix ainda não configurada pelo administrador.');
        $lines[] = 'Após o pagamento, envie o comprovante para liberação do anúncio.';
    }

    if ($admin_whatsapp) {
        $lines[] = '';
        $lines[] = 'WhatsApp para enviar comprovante ou tirar dúvidas: ' . $admin_whatsapp;
    }

    $lines[] = '';
    $lines[] = 'Você pode acompanhar o anúncio em:';
    $lines[] = $my_ads_url;
    $lines[] = '';
    $lines[] = 'Seu anúncio será ativado após confirmação do pagamento.';

    $body = implode("\n", $lines);
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $sent = wp_mail($ad->email, $subject, $body, $headers);
    if ($sent) {
        $wpdb->update($wpdb->prefix . 'ots_ads', [
            'payment_instructions_sent' => 1,
            'updated_at' => current_time('mysql'),
        ], ['id' => absint($ad_id)]);
    }

    return $sent;
}

add_action('rest_api_init', function () {
    register_rest_route('ots/v1', '/mercadopago-webhook', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'ots_mercadopago_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function ots_mercadopago_webhook(WP_REST_Request $request) {
    if (strtoupper($request->get_method()) !== 'POST') {
        ots_log_event('mp_webhook_rejected', 'payment', 0, 'Webhook rejeitado por método diferente de POST');
        return new WP_REST_Response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $signature_header = $request->get_header('x-signature');
    $request_id_header = $request->get_header('x-request-id');
    if (empty($signature_header) && empty($request_id_header)) {
        ots_log_event('mp_webhook_warning', 'payment', 0, 'Webhook recebido sem cabeçalhos de assinatura do Mercado Pago. Pagamento será validado pela API.', [
            'remote_ip' => ots_get_user_ip(),
        ]);
    }

    $token = ots_get_option('mp_access_token');
    if (!$token) {
        ots_log_event('mp_webhook_error', 'payment', 0, 'Webhook Mercado Pago sem token configurado');
        return new WP_REST_Response(['ok' => false, 'message' => 'Missing token'], 500);
    }

    $params = $request->get_params();
    $payment_id = $params['data']['id'] ?? $params['id'] ?? $params['payment_id'] ?? null;
    $topic = sanitize_text_field($params['topic'] ?? $params['type'] ?? '');
    if (!$payment_id) {
        ots_log_event('mp_webhook_ignored', 'payment', 0, 'Webhook sem payment_id', ['params' => $params]);
        return new WP_REST_Response(['ok' => true, 'message' => 'No payment id'], 200);
    }

    $response = wp_remote_get('https://api.mercadopago.com/v1/payments/' . rawurlencode($payment_id), [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 30,
    ]);
    if (is_wp_error($response)) {
        ots_log_event('mp_webhook_error', 'payment', 0, 'Erro ao consultar pagamento: ' . $response->get_error_message(), ['payment_id' => $payment_id]);
        return new WP_REST_Response(['ok' => false], 500);
    }

    $http_code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if ($http_code < 200 || $http_code >= 300 || !is_array($data)) {
        ots_log_event('mp_webhook_error', 'payment', 0, 'Resposta inválida do Mercado Pago', ['payment_id' => $payment_id, 'http_code' => $http_code]);
        return new WP_REST_Response(['ok' => false], 500);
    }

    $status = sanitize_text_field($data['status'] ?? 'unknown');
    $ad_id = absint($data['external_reference'] ?? 0);
    $amount_paid = isset($data['transaction_amount']) ? (float) $data['transaction_amount'] : 0.0;
    $currency = sanitize_text_field($data['currency_id'] ?? '');
    $mp_payment_id = sanitize_text_field((string) ($data['id'] ?? $payment_id));

    global $wpdb;
    if (!$ad_id) {
        ots_log_event('mp_webhook_rejected', 'payment', 0, 'Pagamento sem external_reference de anúncio', ['payment_id' => $mp_payment_id, 'topic' => $topic]);
        return new WP_REST_Response(['ok' => true, 'message' => 'Missing ad reference'], 200);
    }

    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", $ad_id));
    if (!$ad) {
        ots_log_event('mp_webhook_rejected', 'ad', $ad_id, 'Anúncio não encontrado para pagamento', ['payment_id' => $mp_payment_id]);
        return new WP_REST_Response(['ok' => true, 'message' => 'Ad not found'], 200);
    }

    $duplicate_ad = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ots_ads WHERE mp_payment_id=%s AND id<>%d LIMIT 1",
        $mp_payment_id,
        $ad_id
    ));
    if ($duplicate_ad) {
        ots_log_event('mp_webhook_rejected', 'ad', $ad_id, 'Payment ID já usado em outro anúncio', ['payment_id' => $mp_payment_id, 'duplicate_ad' => $duplicate_ad]);
        return new WP_REST_Response(['ok' => true, 'message' => 'Duplicate payment'], 200);
    }

    $expected = (float) $ad->amount;
    $valid_amount = abs($amount_paid - $expected) < 0.01;
    $valid_currency = ($currency === 'BRL');
    $new_ad_status = $ad->status;

    if ($status === 'approved' && $valid_amount && $valid_currency) {
        // Pagamento confirmado, mas anúncio segue para validação manual antes de ficar ativo.
        $new_ad_status = in_array($ad->status, ['pending_payment', 'paid'], true) ? 'pending_review' : $ad->status;
        $payment_status = 'approved';
        $paid_at = current_time('mysql');
    } elseif (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
        $new_ad_status = 'pending_payment';
        $payment_status = $status;
        $paid_at = null;
    } else {
        $payment_status = $status;
        $paid_at = null;
    }

    if ($status === 'approved' && (!$valid_amount || !$valid_currency)) {
        $payment_status = 'invalid_amount_or_currency';
        $new_ad_status = 'pending_payment';
        ots_log_event('mp_webhook_rejected', 'ad', $ad_id, 'Pagamento aprovado com valor ou moeda inválidos', [
            'payment_id' => $mp_payment_id,
            'expected_amount' => $expected,
            'paid_amount' => $amount_paid,
            'currency' => $currency,
        ]);
    }

    $update = [
        'status' => $new_ad_status,
        'payment_status' => $payment_status,
        'mp_payment_id' => $mp_payment_id,
        'payment_amount' => $amount_paid,
        'payment_currency' => $currency,
        'updated_at' => current_time('mysql'),
    ];
    if ($paid_at) {
        $update['paid_at'] = $paid_at;
    }

    $wpdb->update($wpdb->prefix . 'ots_ads', $update, ['id' => $ad_id]);
    ots_log_event('mp_webhook_processed', 'ad', $ad_id, 'Webhook Mercado Pago processado', [
        'payment_id' => $mp_payment_id,
        'status' => $status,
        'payment_status' => $payment_status,
        'ad_status' => $new_ad_status,
        'paid_amount' => $amount_paid,
        'currency' => $currency,
    ]);

    return new WP_REST_Response(['ok' => true], 200);
}
