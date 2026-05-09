<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('Oferta TOP Site', 'Oferta TOP Site', ots_admin_capability(), 'ots-dashboard', 'ots_admin_ads_page', 'dashicons-megaphone', 56);
    add_submenu_page('ots-dashboard', 'Anúncios', 'Anúncios', ots_admin_capability(), 'ots-dashboard', 'ots_admin_ads_page');
    add_submenu_page('ots-dashboard', 'Configurações', 'Configurações', ots_admin_capability(), 'ots-settings', 'ots_admin_settings_page');
    add_submenu_page('ots-dashboard', 'Saques', 'Saques', ots_admin_capability(), 'ots-withdrawals', 'ots_admin_withdrawals_page');
    add_submenu_page('ots-dashboard', 'Antifraude', 'Antifraude', ots_admin_capability(), 'ots-antifraud', 'ots_admin_antifraud_page');
});

add_action('admin_post_ots_admin_ad_action', 'ots_admin_handle_ad_actions');
function ots_admin_handle_ad_actions() {
    if (!current_user_can(ots_admin_capability())) {
        wp_die('Você não tem permissão para executar esta ação.');
    }

    $action = sanitize_key(wp_unslash($_POST['ots_admin_action'] ?? ''));
    $ad_id = absint($_POST['ad_id'] ?? 0);
    if (!$ad_id || !$action || empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ots_admin_ad_' . $ad_id)) {
        wp_die('Ação inválida. Atualize a página e tente novamente.');
    }

    global $wpdb;
    if ($action === 'delete') {
        ots_admin_delete_ad($ad_id);
        wp_safe_redirect(admin_url('admin.php?page=ots-dashboard&deleted=1'));
        exit;
    }

    $map = [
        'approve' => 'active',
        'pause' => 'paused',
        'finish' => 'finished',
        'reject' => 'rejected',
        'mark_paid' => 'pending_review',
    ];

    if (isset($map[$action])) {
        $data = ['status' => $map[$action], 'updated_at' => current_time('mysql')];
        if ($action === 'reject') {
            $data['rejection_reason'] = 'Reprovado pelo administrador. Edite o anúncio em Meus Anúncios para solicitar nova validação.';
        }
        if ($action === 'mark_paid') {
            $data['payment_status'] = 'approved';
            $data['paid_at'] = current_time('mysql');
        }
        $wpdb->update($wpdb->prefix . 'ots_ads', $data, ['id' => $ad_id]);
        ots_log_event('admin_ad_' . $action, 'ad', $ad_id, 'Ação administrativa executada via POST');
    }

    wp_safe_redirect(admin_url('admin.php?page=ots-dashboard&updated=1'));
    exit;
}

function ots_admin_post_button($action, $id, $label, $class = 'button-link', $confirm = '') {
    $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:2px 4px 2px 0">';
    $html .= '<input type="hidden" name="action" value="ots_admin_ad_action">';
    $html .= '<input type="hidden" name="ots_admin_action" value="' . esc_attr($action) . '">';
    $html .= '<input type="hidden" name="ad_id" value="' . absint($id) . '">';
    $html .= wp_nonce_field('ots_admin_ad_' . absint($id), '_wpnonce', true, false);
    $onclick = $confirm ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '';
    $html .= '<button type="submit" class="' . esc_attr($class) . '"' . $onclick . '>' . esc_html($label) . '</button>';
    $html .= '</form>';
    return $html;
}

function ots_admin_delete_ad($ad_id) {
    if (!current_user_can('manage_options')) {
        wp_die('Você não tem permissão para excluir anúncios.');
    }

    global $wpdb;
    $ad_id = absint($ad_id);
    if (!$ad_id) return false;

    $ads_table = $wpdb->prefix . 'ots_ads';
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ads_table} WHERE id=%d", $ad_id));
    if (!$ad) return false;

    // Remove a imagem física somente se nenhum outro anúncio ativo/não excluído usa a mesma URL.
    if (!empty($ad->image_url) && function_exists('ots_delete_ad_image_from_server')) {
        $same_image = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ads_table} WHERE id<>%d AND image_url=%s AND status<>'deleted'",
            $ad_id,
            $ad->image_url
        ));
        if ($same_image === 0) {
            ots_delete_ad_image_from_server($ad->image_url);
        }
    }

    // Soft delete: preserva cliques, impressões e histórico financeiro para auditoria futura.
    $updated = $wpdb->update($ads_table, [
        'status' => 'deleted',
        'deleted_at' => current_time('mysql'),
        'deleted_by' => get_current_user_id(),
        'updated_at' => current_time('mysql'),
    ], ['id' => $ad_id]);

    ots_log_event('ad_deleted', 'ad', $ad_id, 'Anúncio movido para excluído com histórico preservado');
    return (bool) $updated;
}



add_action('admin_post_ots_withdrawal_action', 'ots_admin_handle_withdrawal_actions');
function ots_admin_handle_withdrawal_actions() {
    if (!current_user_can(ots_admin_capability())) {
        wp_die('Você não tem permissão para executar esta ação.');
    }

    $action = sanitize_key(wp_unslash($_POST['ots_withdrawal_action'] ?? ''));
    $withdrawal_id = absint($_POST['withdrawal_id'] ?? 0);
    if (!$withdrawal_id || !$action || empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ots_withdrawal_' . $withdrawal_id)) {
        wp_die('Ação inválida. Atualize a página e tente novamente.');
    }

    global $wpdb;
    $allowed = [
        'mark_paid' => 'paid',
        'mark_pending' => 'pending',
        'cancel' => 'cancelled',
    ];

    if (isset($allowed[$action])) {
        $data = ['status' => $allowed[$action], 'updated_at' => current_time('mysql')];
        if ($action === 'mark_paid') {
            $data['paid_at'] = current_time('mysql');
            $data['paid_by'] = get_current_user_id();
        } elseif ($action === 'mark_pending') {
            $data['paid_at'] = null;
            $data['paid_by'] = null;
        }
        $wpdb->update($wpdb->prefix . 'ots_withdrawals', $data, ['id' => $withdrawal_id]);
        ots_log_event('withdrawal_' . $action, 'withdrawal', $withdrawal_id, 'Status de saque alterado via POST');
    }

    wp_safe_redirect(admin_url('admin.php?page=ots-withdrawals&updated=1'));
    exit;
}

function ots_withdrawal_post_button($action, $id, $label, $class = 'button', $confirm = '') {
    $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:2px 4px 2px 0">';
    $html .= '<input type="hidden" name="action" value="ots_withdrawal_action">';
    $html .= '<input type="hidden" name="ots_withdrawal_action" value="' . esc_attr($action) . '">';
    $html .= '<input type="hidden" name="withdrawal_id" value="' . absint($id) . '">';
    $html .= wp_nonce_field('ots_withdrawal_' . absint($id), '_wpnonce', true, false);
    $onclick = $confirm ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '';
    $html .= '<button type="submit" class="' . esc_attr($class) . '"' . $onclick . '>' . esc_html($label) . '</button>';
    $html .= '</form>';
    return $html;
}

function ots_admin_ads_page() {
    global $wpdb;
    $ads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ots_ads WHERE status<>'deleted' ORDER BY created_at DESC LIMIT 200");
    echo '<div class="wrap"><h1>Oferta TOP Site - Anúncios</h1>';
    if (!empty($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Ação executada.</p></div>';
    if (!empty($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Anúncio excluído com segurança. O histórico foi preservado para auditoria.</p></div>';
    echo '<p>Gerencie aprovação, reprovação, exclusão e conferência dos anúncios enviados pelos usuários.</p>';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Anúncio</th><th>E-mail</th><th>Status</th><th>Cliques</th><th>Impressões</th><th>Valor</th><th>Pagamento</th><th>Ações</th></tr></thead><tbody>';
    if (!$ads) {
        echo '<tr><td colspan="9">Nenhum anúncio cadastrado.</td></tr>';
    }
    foreach ($ads as $ad) {
        $actions = [];
        foreach ([ 'approve' => 'Aprovar', 'mark_paid' => 'Marcar pago', 'pause' => 'Pausar', 'reject' => 'Reprovar', 'finish' => 'Finalizar' ] as $key => $label) {
            $actions[] = ots_admin_post_button($key, $ad->id, $label);
        }
        if (current_user_can('manage_options')) {
            $actions[] = ots_admin_post_button('delete', $ad->id, 'Excluir', 'button-link-delete', 'Tem certeza que deseja excluir este anúncio? O histórico será preservado e a imagem será removida apenas se não estiver em uso.');
        }
        echo '<tr>';
        echo '<td>#' . absint($ad->id) . '</td>';
        echo '<td>'; if (!empty($ad->image_url)) echo '<img src="' . esc_url($ad->image_url) . '" style="width:70px;height:45px;object-fit:cover;margin-right:8px;vertical-align:middle" alt="">'; echo '<strong>' . esc_html($ad->title) . '</strong><br><small>' . esc_html(wp_html_excerpt($ad->link, 80, '...')) . '</small></td>';
        echo '<td>' . esc_html($ad->email) . '</td>';
        echo '<td>' . esc_html(ots_status_label($ad->status)) . '</td>';
        echo '<td>' . absint($ad->clicks) . '/' . absint($ad->total_clicks) . '<br>Restam: ' . absint($ad->remaining_clicks) . '</td>';
        echo '<td>' . absint($ad->impressions) . '</td>';
        echo '<td>' . esc_html(ots_money($ad->amount)) . '</td>';
        echo '<td>' . esc_html(ots_payment_method_label($ad->payment_method)) . '<br><small>' . esc_html($ad->payment_status ?: '-') . '</small></td>';
        echo '<td>' . implode(' ', $actions) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function ots_admin_settings_page() {
    $opts = get_option('ots_settings', []);
    echo '<div class="wrap"><h1>Oferta TOP Site - Configurações</h1><p><strong>Segurança v1.2.1:</strong> as credenciais do Mercado Pago não ficam mais gravadas no código do plugin. Deixe campos sensíveis em branco para preservar o valor já salvo.</p><form method="post" action="options.php">';
    settings_fields('ots_settings_group');
    echo '<table class="form-table" role="presentation"><tbody>';
    ots_admin_field($opts, 'mp_access_token', 'Mercado Pago Access Token', 'password');
    ots_admin_field($opts, 'mp_public_key', 'Mercado Pago Public Key');
    ots_admin_field($opts, 'turnstile_site_key', 'Cloudflare Turnstile Site Key');
    ots_admin_field($opts, 'turnstile_secret_key', 'Cloudflare Turnstile Secret Key', 'password');
    ots_admin_field($opts, 'ad_price', 'Valor mínimo do anúncio');
    ots_admin_field($opts, 'withdrawal_min_amount', 'Valor mínimo para saque');
    ots_admin_field($opts, 'ad_clicks', 'Cliques do pacote mínimo');
    ots_admin_field($opts, 'click_commission', 'Comissão por clique');
    ots_admin_field($opts, 'admin_pix_key', 'Chave Pix do administrador');
    ots_admin_field($opts, 'admin_whatsapp', 'WhatsApp do administrador');
    ots_admin_field($opts, 'payment_email_subject', 'Assunto do e-mail de pagamento');
    echo '</tbody></table>';
    submit_button('Salvar configurações');
    echo '</form></div>';
}

function ots_admin_field($opts, $key, $label, $type = 'text') {
    $value = isset($opts[$key]) ? $opts[$key] : '';
    $field_value = $type === 'password' ? '' : $value;
    $note = '';
    if ($type === 'password' && $value !== '') {
        $note = '<p class="description">Valor sensível já salvo. Deixe em branco para manter, ou digite um novo valor para substituir.</p>';
    }
    echo '<tr><th scope="row"><label for="ots_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="ots_' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="ots_settings[' . esc_attr($key) . ']" value="' . esc_attr($field_value) . '" autocomplete="off">' . $note . '</td></tr>';
}

function ots_admin_withdrawals_page() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT w.*, u.user_email, u.display_name FROM {$wpdb->prefix}ots_withdrawals w LEFT JOIN {$wpdb->users} u ON u.ID=w.user_id ORDER BY w.created_at DESC LIMIT 200");
    echo '<div class="wrap"><h1>Solicitações de Saque</h1>';
    if (!empty($_GET['updated'])) echo '<div class="notice notice-success is-dismissible"><p>Status do saque atualizado.</p></div>';
    echo '<p>Use as ações para alterar uma solicitação de <strong>pendente</strong> para <strong>pago</strong> após realizar o Pix ao afiliado.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Usuário</th><th>Valor</th><th>Pix</th><th>WhatsApp</th><th>Status</th><th>Data</th><th>Ações</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="7">Nenhuma solicitação.</td></tr>';
    foreach ($rows as $r) {
        $actions = [];
        if ($r->status !== 'paid') {
            $actions[] = ots_withdrawal_post_button('mark_paid', $r->id, 'Marcar como pago', 'button button-primary', 'Confirmar que este saque já foi pago via Pix?');
        }
        if ($r->status !== 'pending') {
            $actions[] = ots_withdrawal_post_button('mark_pending', $r->id, 'Voltar para pendente');
        }
        if ($r->status !== 'cancelled' && $r->status !== 'paid') {
            $actions[] = ots_withdrawal_post_button('cancel', $r->id, 'Cancelar', 'button', 'Cancelar esta solicitação de saque?');
        }
        $status_label = $r->status === 'paid' ? 'Pago' : ($r->status === 'pending' ? 'Pendente' : ($r->status === 'cancelled' ? 'Cancelado' : $r->status));
        echo '<tr><td>' . esc_html($r->display_name ?: $r->user_email) . '</td><td>' . esc_html(ots_money($r->amount)) . '</td><td>' . esc_html($r->pix_key) . '</td><td>' . esc_html($r->whatsapp) . '</td><td><strong>' . esc_html($status_label) . '</strong></td><td>' . esc_html(mysql2date('d/m/Y H:i', $r->created_at)) . '</td><td>' . implode(' ', $actions) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}


function ots_admin_antifraud_page() {
    global $wpdb;
    $locks = $wpdb->get_results("SELECT l.*, u.display_name AS affiliate_name, u.user_email AS affiliate_email FROM {$wpdb->prefix}ots_click_locks l LEFT JOIN {$wpdb->users} u ON u.ID=l.affiliate_user_id ORDER BY l.created_at DESC LIMIT 100");
    $logs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ots_event_logs ORDER BY created_at DESC LIMIT 150");
    echo '<div class="wrap"><h1>Antifraude e Auditoria</h1>';
    echo '<p>Esta tela mostra a trava global de cliques e eventos de segurança/financeiros registrados pelo plugin.</p>';
    echo '<h2>Trava global de cliques</h2>';
    echo '<p>Regra: depois do primeiro clique válido, o mesmo visitante não consome novos cliques em nenhum anúncio.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Anúncio inicial</th><th>Afiliado creditado</th><th>IP</th><th>Motivo</th><th>Visitor hash</th></tr></thead><tbody>';
    if (!$locks) echo '<tr><td colspan="6">Nenhuma trava registrada.</td></tr>';
    foreach ($locks as $lock) {
        $affiliate = $lock->affiliate_user_id ? ('#' . absint($lock->affiliate_user_id) . ' ' . ($lock->affiliate_name ?: $lock->affiliate_email)) : '-';
        echo '<tr><td>' . esc_html(mysql2date('d/m/Y H:i', $lock->created_at)) . '</td><td>#' . absint($lock->first_ad_id) . '</td><td>' . esc_html($affiliate) . '</td><td>' . esc_html($lock->ip_address) . '</td><td>' . esc_html($lock->reason) . '</td><td><code>' . esc_html(substr($lock->visitor_hash, 0, 16)) . '...</code></td></tr>';
    }
    echo '</tbody></table>';

    echo '<h2 style="margin-top:30px">Eventos recentes</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Tipo</th><th>Objeto</th><th>Usuário</th><th>IP</th><th>Mensagem</th></tr></thead><tbody>';
    if (!$logs) echo '<tr><td colspan="6">Nenhum evento registrado.</td></tr>';
    foreach ($logs as $log) {
        echo '<tr><td>' . esc_html(mysql2date('d/m/Y H:i', $log->created_at)) . '</td><td><code>' . esc_html($log->event_type) . '</code></td><td>' . esc_html($log->object_type . ' #' . $log->object_id) . '</td><td>' . ($log->user_id ? '#' . absint($log->user_id) : '-') . '</td><td>' . esc_html($log->ip_address) . '</td><td>' . esc_html($log->message) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
