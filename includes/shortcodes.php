<?php
if (!defined('ABSPATH')) exit;

add_shortcode('ots_criar_anuncio', 'ots_sc_create_ad');
function ots_sc_create_ad() {
    global $wpdb;
    $edit_ad = null;
    $is_edit = false;

    if (!empty($_GET['ots_edit_ad']) && is_user_logged_in()) {
        $edit_id = absint($_GET['ots_edit_ad']);
        $maybe_ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d", $edit_id));
        if ($maybe_ad && function_exists('ots_user_can_edit_ad') && ots_user_can_edit_ad($maybe_ad)) {
            $edit_ad = $maybe_ad;
            $is_edit = true;
        }
    }

    ob_start();
    echo ots_layout_open();
    $price = ots_default_price();
    $clicks = ots_default_clicks();
    $title_value = $is_edit ? $edit_ad->title : '';
    $link_value = $is_edit ? $edit_ad->link : '';
    $email_value = $is_edit ? $edit_ad->email : (is_user_logged_in() ? wp_get_current_user()->user_email : '');
    ?>
    <section class="ots-section">
        <h1><?php echo $is_edit ? 'Editar anúncio reprovado' : 'Compartilhe na Redes Sociais'; ?></h1>
        <?php if ($is_edit): ?>
            <div class="ots-alert ots-alert-info">Ajuste as informações solicitadas e reenvie para nova validação. Você não precisa criar outro anúncio.</div>
            <?php if (!empty($edit_ad->rejection_reason)): ?><div class="ots-alert ots-alert-error"><strong>Motivo da reprovação:</strong> <?php echo esc_html($edit_ad->rejection_reason); ?></div><?php endif; ?>
        <?php else: ?>
            <p>Criação de anúncio com título com máximo de <strong>85 caracteres</strong>, link, imagem e valor para colocar no anúncio com mínimo de <strong><?php echo esc_html(ots_money($price)); ?></strong>, mostrando o valor estimado de <strong><?php echo esc_html($clicks); ?> cliques</strong>.</p>
        <?php endif; ?>
        <h2><?php echo $is_edit ? 'Reenviar anúncio para validação' : 'Criar Anúncio'; ?></h2>
        <form class="ots-form" method="post" enctype="multipart/form-data">
            <?php if ($is_edit): ?>
                <?php wp_nonce_field('ots_update_ad', 'ots_update_ad_nonce'); ?>
                <input type="hidden" name="ots_ad_id" value="<?php echo esc_attr($edit_ad->id); ?>">
            <?php else: ?>
                <?php wp_nonce_field('ots_create_ad', 'ots_create_ad_nonce'); ?>
            <?php endif; ?>
            <input type="text" name="ots_title" maxlength="85" placeholder="Título do anúncio" value="<?php echo esc_attr($title_value); ?>" required>
            <input type="url" name="ots_link" placeholder="Link do anúncio" value="<?php echo esc_attr($link_value); ?>" required>
            <?php if ($is_edit && !empty($edit_ad->image_url)): ?>
                <div class="ots-current-image"><span>Imagem atual</span><img src="<?php echo esc_url($edit_ad->image_url); ?>" alt=""></div>
            <?php endif; ?>
            <label class="ots-file"><input type="file" name="ots_image" accept="image/*" <?php echo $is_edit ? '' : 'required'; ?>><span><?php echo $is_edit ? 'Trocar imagem do anúncio (opcional)' : 'Selecionar uma imagem do anúncio'; ?></span></label>
            <?php if (!$is_edit): ?>
                <label for="ots_amount">Valor da recarga</label>
                <div class="ots-price ots-price-editable" data-base-price="<?php echo esc_attr($price); ?>" data-base-clicks="<?php echo esc_attr($clicks); ?>">
                    <span>R$</span>
                    <input id="ots_amount" type="number" name="ots_amount" min="<?php echo esc_attr($price); ?>" step="0.01" value="<?php echo esc_attr(number_format($price, 2, '.', '')); ?>" required>
                    <b>Duração estimada:</b> <strong id="ots_estimated_clicks"><?php echo esc_html($clicks); ?></strong> cliques no anúncio
                </div>
                <small class="ots-field-help">Valor mínimo: <strong><?php echo esc_html(ots_money($price)); ?></strong>. Você pode inserir qualquer valor acima do mínimo.</small>
                <h3>Forma de Pagamento:</h3>
                <div class="ots-payment-methods">
                    <label class="ots-payment-card">
                        <input type="radio" name="ots_payment_method" value="mercado_pago" <?php checked((bool) ots_get_option('mp_access_token'), true); ?> <?php disabled(!ots_get_option('mp_access_token')); ?>>
                        <span><strong>Mercado Pago</strong><small>Gerar link automático para pagar com Pix, cartão ou saldo Mercado Pago.</small></span>
                    </label>
                    <label class="ots-payment-card">
                        <input type="radio" name="ots_payment_method" value="pix_manual" <?php checked(!ots_get_option('mp_access_token'), true); ?>>
                        <span><strong>Pix manual</strong><small>Enviar chave Pix e instruções para o e-mail do anunciante.</small></span>
                    </label>
                </div>
                <?php if (!ots_get_option('mp_access_token')): ?>
                    <div class="ots-alert ots-alert-info">Mercado Pago está desativado porque o Access Token ainda não foi configurado no painel do plugin. O Pix manual continua funcionando.</div>
                <?php endif; ?>
            <?php endif; ?>
            <input type="email" name="ots_email" placeholder="E-mail para receber as instruções" value="<?php echo esc_attr($email_value); ?>" required>
            <?php if (!$is_edit) echo ots_turnstile_widget(); ?>
            <button type="submit" name="<?php echo $is_edit ? 'ots_update_ad' : 'ots_submit_ad'; ?>" value="1"><?php echo $is_edit ? 'Salvar e reenviar para validação' : 'Contratar'; ?></button>
        </form>
    </section>
    <?php
    echo ots_layout_close();
    return ob_get_clean();
}

add_shortcode('ots_anuncios', 'ots_sc_ads');
function ots_sc_ads() {
    $ads = ots_get_active_ads(50, false);
    ob_start();
    echo ots_layout_open();
    echo '<section class="ots-section"><h1>Anúncios</h1><p>Lista de total de anúncio aqui.</p>';
    if (!$ads) echo '<p>Nenhum anúncio ativo no momento.</p>';
    foreach ($ads as $ad) echo ots_render_ad_card($ad, false);
    echo '</section>' . ots_layout_close();
    return ob_get_clean();
}

add_shortcode('ots_compartilhar', 'ots_sc_share');
function ots_sc_share() {
    $ads = ots_get_active_ads(20, true);
    ob_start();
    echo ots_layout_open();
    echo '<section class="ots-section"><h1>Compartilhe na Redes Sociais</h1><p>Conteúdo de compartilhe nas redes sociais aqui.</p>';
    if (!$ads) echo '<p>Nenhum anúncio disponível para compartilhar.</p>';
    foreach ($ads as $ad) echo ots_render_ad_card($ad, true);
    echo '</section>' . ots_layout_close();
    return ob_get_clean();
}

function ots_render_ad_card($ad, $share = true) {
    $affiliate_ref = ($share && is_user_logged_in()) ? get_current_user_id() : 0;
    $click_url = function_exists('ots_click_url') ? ots_click_url($ad->id, $affiliate_ref) : add_query_arg(['ad' => absint($ad->id), 'ref' => absint($affiliate_ref)], home_url('/ots-click/'));
    $html = '<div class="ots-ad-card" data-ots-ad="' . esc_attr($ad->id) . '">';
    $html .= '<img src="' . esc_url($ad->image_url) . '" alt="">';
    $html .= '<div class="ots-ad-info"><h3>' . esc_html($ad->title) . '</h3><p>' . esc_html(wp_html_excerpt($ad->link, 55, '...')) . '</p>';
    $html .= '<p><strong>' . absint($ad->impressions) . '</strong> Impressões - <strong>' . absint($ad->clicks) . '</strong> Cliques</p>';
    $html .= '<a class="ots-btn" target="_blank" rel="noopener" href="' . esc_url($click_url) . '">Acessar anúncio</a>';
    if ($share) {
        $html .= '<div class="ots-share-actions">';
        $html .= '<a target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($click_url) . '">Facebook</a>';
        $html .= '<a target="_blank" rel="noopener" href="https://api.whatsapp.com/send?text=' . rawurlencode($ad->title . ' ' . $click_url) . '">WhatsApp</a>';
        $html .= '<button type="button" class="ots-copy" data-copy="' . esc_attr($click_url) . '">Copiar link</button>';
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

add_shortcode('ots_meus_anuncios', 'ots_sc_my_ads');
function ots_sc_my_ads() {
    if (!is_user_logged_in()) return ots_layout_open() . '<p>Você precisa estar logado para ver seus anúncios.</p>' . ots_layout_close();
    global $wpdb;

    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();
    $user_email = !empty($current_user->user_email) ? $current_user->user_email : '';

    // Correção v1.0.4:
    // Antes a página buscava somente por user_id. Em alguns sites o anúncio fica sem user_id
    // ou com user_id diferente, principalmente quando o anunciante informa apenas e-mail.
    // Agora buscamos por user_id OU pelo e-mail do usuário logado. Administrador vê todos
    // os anúncios para facilitar conferência e ativação.
    if (current_user_can('manage_options')) {
        $ads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ots_ads WHERE status<>'deleted' ORDER BY created_at DESC");
    } else {
        $ads = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ots_ads WHERE status<>'deleted' AND (user_id=%d OR email=%s) ORDER BY created_at DESC",
            $user_id,
            $user_email
        ));
    }

    ob_start();
    echo ots_layout_open();
    echo '<section class="ots-section"><h1>Meus Anúncios</h1>';
    if (current_user_can('manage_options')) {
        echo '<div class="ots-alert ots-alert-info">Você está acessando como administrador. Esta tela mostra todos os anúncios cadastrados para conferência.</div>';
    }
    if (!empty($_GET['payment'])) {
        echo '<div class="ots-alert ots-alert-success">Pagamento recebido pelo Mercado Pago. Se o anúncio ainda não aparecer como ativo, aguarde a confirmação automática ou peça ao administrador para verificar.</div>';
    }
    if (!$ads) echo '<p>Você ainda não criou anúncios.</p>';
    else {
        echo '<div class="ots-table-wrap"><table class="ots-table ots-my-ads-table"><thead><tr><th>Anúncio</th><th>E-mail</th><th>Status</th><th>Cliques</th><th>Impressões</th><th>Valor</th><th>Pagamento</th><th>Data</th><th>Ações</th></tr></thead><tbody>';
        foreach ($ads as $ad) {
            $payment_label = function_exists('ots_payment_method_label') ? ots_payment_method_label($ad->payment_method) : $ad->payment_method;
            $payment_action = 'Instruções por e-mail';
            if ($ad->payment_method === 'mercado_pago' && in_array($ad->status, ['pending_payment'], true) && function_exists('ots_user_can_manage_ad_status') && ots_user_can_manage_ad_status($ad)) {
                $pay_url = wp_nonce_url(add_query_arg('ots_pay_ad', absint($ad->id), home_url('/a/meus-anuncios/')), 'ots_pay_ad_' . absint($ad->id));
                $payment_action = '<a class="ots-btn ots-btn-small" href="' . esc_url($pay_url) . '">Pagar agora</a><br><small>Link de produção gerado na hora</small>';
            }
            $actions_list = [];
            if ($ad->status === 'rejected' && function_exists('ots_user_can_edit_ad') && ots_user_can_edit_ad($ad)) {
                $actions_list[] = '<a class="ots-btn ots-btn-small" href="' . esc_url(add_query_arg('ots_edit_ad', absint($ad->id), home_url('/a/criar-anuncio/'))) . '">Editar e reenviar</a>';
            }
            if (function_exists('ots_user_can_manage_ad_status') && ots_user_can_manage_ad_status($ad)) {
                if ($ad->status === 'active') {
                    $actions_list[] = '<form class="ots-inline-action-form" method="post">' .
                        wp_nonce_field('ots_toggle_ad_pause', 'ots_toggle_ad_pause_nonce', true, false) .
                        '<input type="hidden" name="ots_ad_id" value="' . absint($ad->id) . '">' .
                        '<input type="hidden" name="ots_pause_action" value="pause">' .
                        '<button class="ots-btn ots-btn-small ots-btn-warning" type="submit" name="ots_toggle_ad_pause" value="1">Pausar</button>' .
                    '</form>';
                } elseif ($ad->status === 'paused') {
                    $actions_list[] = '<form class="ots-inline-action-form" method="post">' .
                        wp_nonce_field('ots_toggle_ad_pause', 'ots_toggle_ad_pause_nonce', true, false) .
                        '<input type="hidden" name="ots_ad_id" value="' . absint($ad->id) . '">' .
                        '<input type="hidden" name="ots_pause_action" value="resume">' .
                        '<button class="ots-btn ots-btn-small ots-btn-success" type="submit" name="ots_toggle_ad_pause" value="1">Despausar</button>' .
                    '</form>';
                }
            }
            $actions = $actions_list ? '<div class="ots-actions-stack">' . implode('', $actions_list) . '</div>' : '—';
            $reason = (!empty($ad->rejection_reason) && $ad->status === 'rejected') ? '<small class="ots-reject-reason">' . esc_html($ad->rejection_reason) . '</small>' : '';
            echo '<tr><td data-label="Anúncio"><div class="ots-table-ad"><img src="' . esc_url($ad->image_url) . '" alt=""><span>' . esc_html($ad->title) . '</span></div></td><td data-label="E-mail">' . esc_html($ad->email) . '</td><td data-label="Status"><span class="ots-status ots-status-' . esc_attr($ad->status) . '">' . esc_html(ots_status_label($ad->status)) . '</span>' . $reason . '</td><td data-label="Cliques">' . absint($ad->clicks) . '/' . absint($ad->total_clicks) . '<br>Restam: ' . absint($ad->remaining_clicks) . '</td><td data-label="Impressões">' . absint($ad->impressions) . '</td><td data-label="Valor">' . esc_html(ots_money($ad->amount)) . '</td><td data-label="Pagamento">' . esc_html($payment_label) . '<br>' . $payment_action . '</td><td data-label="Data">' . esc_html(mysql2date('d/m/Y H:i', $ad->created_at)) . '</td><td data-label="Ações">' . $actions . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>' . ots_layout_close();
    return ob_get_clean();
}

add_shortcode('ots_programa_afiliados', 'ots_sc_affiliate');
function ots_sc_affiliate() {
    if (!is_user_logged_in()) return ots_layout_open() . '<p>Você precisa estar logado para acessar o programa de afiliados.</p>' . ots_layout_close();
    global $wpdb;
    $user_id = get_current_user_id();
    $balance = ots_get_user_balance($user_id);
    $wallet = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_wallet WHERE user_id=%d ORDER BY created_at DESC LIMIT 30", $user_id));
    $withdrawal_min = function_exists('ots_withdrawal_min_amount') ? ots_withdrawal_min_amount() : 30;
    ob_start();
    echo ots_layout_open();
    ?>
    <section class="ots-section">
        <h1>Programa de Afiliados</h1>
        <p><strong>Valor ganho:</strong> <?php echo esc_html(ots_money($balance)); ?></p>
        <p><strong>Chave Pix:</strong> <?php echo esc_html(ots_get_option('admin_pix_key', 'Configure no painel')); ?></p>
        <p><strong>Whatsapp:</strong> <?php echo esc_html(ots_get_option('admin_whatsapp', 'Configure no painel')); ?></p>
        <p>Programas de afiliados por clique PPC paga comissão de <strong><?php echo esc_html(ots_money(ots_click_commission())); ?></strong> por clique quando usuários clicam em anúncio compartilhado nas redes sociais.</p>
        <p>A comissão só é gerada por link de afiliado com referência. Usuários logados, administradores e visitantes que já tiveram um clique contabilizado em qualquer anúncio não geram novo clique nem ganho.</p>
        <h2>Solicitar saque</h2>
        <p class="ots-muted">Saque mínimo: <strong><?php echo esc_html(ots_money($withdrawal_min)); ?></strong>. Você pode solicitar qualquer valor igual ou acima do mínimo, desde que não ultrapasse seu saldo disponível.</p>
        <?php if ($balance < $withdrawal_min): ?>
            <div class="ots-alert ots-alert-info">Você ainda não possui saldo suficiente para solicitar saque. Saldo mínimo necessário: <?php echo esc_html(ots_money($withdrawal_min)); ?>.</div>
        <?php endif; ?>
        <form class="ots-form ots-small-form" method="post">
            <?php wp_nonce_field('ots_withdrawal', 'ots_withdrawal_nonce'); ?>
            <input type="number" min="<?php echo esc_attr($withdrawal_min); ?>" max="<?php echo esc_attr($balance); ?>" step="0.01" name="amount" placeholder="Valor do saque" required <?php disabled($balance < $withdrawal_min); ?>>
            <input name="pix_key" placeholder="Sua chave Pix" required <?php disabled($balance < $withdrawal_min); ?>>
            <input name="whatsapp" placeholder="WhatsApp" <?php disabled($balance < $withdrawal_min); ?>>
            <button type="submit" name="ots_withdrawal_submit" value="1" <?php disabled($balance < $withdrawal_min); ?>>Solicitar saque</button>
        </form>
        <h2>Histórico de ganhos</h2>
        <?php if (!$wallet): ?><p>Nenhum ganho registrado ainda.</p><?php else: ?>
        <table class="ots-table"><thead><tr><th>Data</th><th>Descrição</th><th>Valor</th></tr></thead><tbody>
        <?php foreach ($wallet as $row): ?><tr><td><?php echo esc_html(mysql2date('d/m/Y H:i', $row->created_at)); ?></td><td><?php echo esc_html($row->description); ?></td><td><?php echo esc_html(ots_money($row->amount)); ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
    </section>
    <?php
    echo ots_layout_close();
    return ob_get_clean();
}

add_shortcode('ots_alterar_senha', 'ots_sc_change_password');
function ots_sc_change_password() {
    return ots_layout_open() . '<section class="ots-section"><h1>Alterar Senha</h1><p>Use o recurso padrão do WordPress para recuperar ou alterar sua senha.</p><a class="ots-btn" href="' . esc_url(wp_lostpassword_url()) . '">Alterar senha</a></section>' . ots_layout_close();
}

add_shortcode('ots_usuarios', 'ots_sc_users');
function ots_sc_users() {
    if (!current_user_can('list_users')) return ots_layout_open() . '<p>Área restrita ao administrador.</p>' . ots_layout_close();
    $users = get_users(['number' => 100]);
    $html = ots_layout_open() . '<section class="ots-section"><h1>Usuários</h1><table class="ots-table"><thead><tr><th>Nome</th><th>E-mail</th><th>Saldo</th></tr></thead><tbody>';
    foreach ($users as $u) $html .= '<tr><td>' . esc_html($u->display_name) . '</td><td>' . esc_html($u->user_email) . '</td><td>' . esc_html(ots_money(ots_get_user_balance($u->ID))) . '</td></tr>';
    return $html . '</tbody></table></section>' . ots_layout_close();
}
