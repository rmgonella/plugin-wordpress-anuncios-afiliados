<?php
if (!defined('ABSPATH')) exit;

add_action('template_redirect', 'ots_handle_click_redirect', 1);
add_action('admin_post_ots_click', 'ots_handle_click_redirect');
add_action('admin_post_nopriv_ots_click', 'ots_handle_click_redirect');

function ots_is_click_request() {
    if (get_query_var('ots_click')) {
        return true;
    }

    if (isset($_GET['ots_click'])) {
        return true;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    return (bool) preg_match('#/(a/)?ots-click/?#', $uri);
}

function ots_click_url($ad_id, $affiliate_ref = 0) {
    // URL absoluta para evitar que o navegador transforme o link em /a/ots-click por engano.
    $args = ['ad' => absint($ad_id)];
    $affiliate_ref = absint($affiliate_ref);
    if ($affiliate_ref > 0) {
        $args['ref'] = $affiliate_ref;
    }
    return add_query_arg($args, home_url('/ots-click/'));
}


function ots_tracking_visitor_hash() {
    $ip = ots_get_user_ip();
    $ua = strtolower(sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $ua = preg_replace('/\s+/', ' ', $ua);
    $ua = substr($ua, 0, 240);

    return hash('sha256', $ip . '|' . $ua . '|' . wp_salt('auth'));
}

function ots_tracking_key($type, $ad_id, $visitor_hash, $bucket = '') {
    return hash('sha256', sanitize_key($type) . '|' . absint($ad_id) . '|' . $visitor_hash . '|' . $bucket . '|' . wp_salt('nonce'));
}

function ots_global_click_tracking_key($visitor_hash) {
    // Chave global: depois do primeiro clique válido, o mesmo visitante não consome cliques em nenhum outro anúncio.
    return ots_tracking_key('global_click', 0, $visitor_hash);
}


function ots_click_lock_exists($visitor_hash, $cookie_hash = '') {
    global $wpdb;
    $lock_key = ots_global_click_tracking_key($visitor_hash);
    $table = $wpdb->prefix . 'ots_click_locks';
    $exists_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists_table === $table) {
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE lock_key=%s OR visitor_hash=%s OR (%s <> '' AND cookie_hash=%s)",
            $lock_key,
            $visitor_hash,
            $cookie_hash,
            $cookie_hash
        ));
        if ($found > 0) return true;
    }

    // Compatibilidade com versões anteriores: a própria tabela de cliques também bloqueia globalmente.
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ots_clicks WHERE tracking_key=%s OR visitor_hash=%s OR (%s <> '' AND cookie_hash=%s)",
        $lock_key,
        $visitor_hash,
        $cookie_hash,
        $cookie_hash
    )) > 0;
}

function ots_create_click_lock($visitor_hash, $ad_id, $affiliate_user_id, $reason = 'valid_click') {
    global $wpdb;
    $table = $wpdb->prefix . 'ots_click_locks';
    $exists_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists_table !== $table) return true;

    $lock_key = ots_global_click_tracking_key($visitor_hash);
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table}
            (visitor_hash, lock_key, first_ad_id, affiliate_user_id, ip_address, user_agent, cookie_hash, reason, created_at)
         VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %s)",
        $visitor_hash,
        $lock_key,
        absint($ad_id),
        $affiliate_user_id ? absint($affiliate_user_id) : null,
        ots_get_user_ip(),
        sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ots_cookie_hash(),
        sanitize_key($reason),
        current_time('mysql')
    ));
    return (bool) $inserted;
}

function ots_valid_affiliate_ref($ref, $ad) {
    $ref = absint($ref);
    if (!$ref) return 0;
    if (!get_user_by('id', $ref)) return 0;
    if (!empty($ad->user_id) && absint($ad->user_id) === $ref) return 0;
    if (current_user_can(ots_admin_capability())) return 0;
    if (get_current_user_id()) return 0; // usuário da conta não contabiliza clique nem ganho.
    return $ref;
}

function ots_current_impression_bucket() {
    // Janela de 12 horas. Evita inflar impressões por F5, reabertura da página ou chamadas repetidas ao AJAX.
    return (string) floor(time() / (12 * HOUR_IN_SECONDS));
}

function ots_register_impression($ad_id, $mode = 'normal') {
    $ad_id = absint($ad_id);
    if (!$ad_id || ots_is_suspicious_user_agent()) {
        return false;
    }

    global $wpdb;

    $ad = $wpdb->get_row($wpdb->prepare(
        "SELECT id, user_id, status, remaining_clicks FROM {$wpdb->prefix}ots_ads WHERE id=%d",
        $ad_id
    ));

    if (!$ad || $ad->status !== 'active' || (int) $ad->remaining_clicks <= 0) {
        return false;
    }

    $user_id = get_current_user_id() ?: null;

    // Usuário logado, administrador e dono do anúncio podem visualizar sem inflar métricas.
    // Regra reforçada: qualquer usuário da conta/site não contabiliza impressão nem clique.
    if ($user_id || current_user_can(ots_admin_capability())) {
        return false;
    }

    $ip = ots_get_user_ip();
    $cookie_hash = ots_cookie_hash();
    $visitor_hash = ots_tracking_visitor_hash();
    $ua = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $tracking_key = ots_tracking_key('impression', $ad_id, $visitor_hash, ots_current_impression_bucket());

    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}ots_impressions
            (ad_id, user_id, ip_address, user_agent, cookie_hash, viewed_at, visitor_hash, tracking_key)
         VALUES (%d, %s, %s, %s, %s, %s, %s, %s)",
        $ad_id,
        $user_id ?: null,
        $ip,
        $ua,
        $cookie_hash,
        current_time('mysql'),
        $visitor_hash,
        $tracking_key
    ));

    if ($inserted) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ots_ads SET impressions=impressions+1, updated_at=%s WHERE id=%d",
            current_time('mysql'),
            $ad_id
        ));
        return true;
    }

    return false;
}

function ots_render_click_challenge($ad, $error = '') {
    nocache_headers();
    status_header(200);

    $ad_id = absint($ad->id);
    if (!$error) {
        ots_register_impression($ad_id, 'click_landing');
    }
    $action_url = ots_click_url($ad_id);
    $site_key = ots_get_option('turnstile_site_key');
    $has_turnstile = !empty($site_key);
    $title = wp_strip_all_tags($ad->title);
    $link_preview = wp_trim_words($ad->link, 10);

    ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Confirmar acesso ao anúncio</title>
    <?php if ($has_turnstile): ?>
        <script>
            function otsTurnstileSuccess(token) {
                var btn = document.getElementById('ots-confirm-btn');
                var tokenField = document.querySelector('input[name="cf-turnstile-response"]');
                if (btn && token) {
                    btn.disabled = false;
                    btn.classList.remove('ots-disabled');
                }
                if (tokenField && token) {
                    tokenField.value = token;
                }
            }
            function otsTurnstileExpired() {
                var btn = document.getElementById('ots-confirm-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('ots-disabled');
                }
            }
            function otsTurnstileError() {
                var err = document.getElementById('ots-turnstile-error');
                var loading = document.getElementById('ots-turnstile-loading');
                if (loading) loading.style.display = 'none';
                if (err) err.style.display = 'block';
            }
            window.addEventListener('load', function () {
                setTimeout(function () {
                    var loading = document.getElementById('ots-turnstile-loading');
                    var iframe = document.querySelector('#ots-turnstile-widget iframe');
                    if (loading && iframe) loading.style.display = 'none';
                    if (loading && !iframe) {
                        loading.innerHTML = 'Aguardando Cloudflare Turnstile...';
                    }
                }, 1800);
            });
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <style>
        html,body{margin:0;padding:0;background:#fff;color:#374151;font-family:Arial,Helvetica,sans-serif}
        .ots-click-page{min-height:100vh;display:flex;background:#fff}
        .ots-click-sidebar{width:295px;background:#f4f5f6;min-height:100vh;padding:28px 22px;box-sizing:border-box;flex:0 0 295px}
        .ots-click-sidebar h3{font-family:Georgia,serif;font-size:22px;color:#343a40;border-bottom:3px solid #990000;padding-bottom:14px;margin:0 0 30px}
        .ots-click-sidebar a{display:block;padding:15px 0;border-bottom:1px solid #ddd;color:#333;text-decoration:none;text-transform:uppercase;letter-spacing:1px;font-family:Georgia,serif;font-size:13px}
        .ots-click-main{flex:1;padding:70px 7vw 60px;box-sizing:border-box;max-width:1100px}
        .ots-user-header{font-size:15px;margin-bottom:18px}
        .ots-redline{border:0;border-top:4px solid #990000;margin:0 0 60px}
        .ots-click-section h1{font-family:Georgia,serif;color:#343a40;font-size:30px;border-bottom:3px solid #990000;display:inline-block;padding-bottom:14px;margin:0 0 34px}
        .ots-alert{padding:14px 16px;border-radius:5px;margin:0 0 20px;border-left:4px solid #dc2626;background:#fff5f5;color:#7f1d1d}
        .ots-card{display:flex;gap:22px;align-items:flex-start;border-bottom:1px solid #e5e7eb;padding:0 0 24px;margin-bottom:24px;max-width:850px}
        .ots-card img{width:300px;max-width:100%;height:175px;object-fit:cover;background:#f3f4f6}
        .ots-card h2{font-size:22px;color:#222;margin:0 0 10px;line-height:1.2}
        .ots-card p{font-size:15px;line-height:1.5;margin:8px 0;color:#6b7280}
        .ots-form{max-width:850px;margin-top:10px}
        .ots-turnstile-area{min-height:82px;margin:18px 0 22px;padding:14px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;display:flex;align-items:center;justify-content:flex-start}
        #ots-turnstile-widget{min-width:300px;min-height:65px}.cf-turnstile iframe{display:block!important}
        .ots-loading{color:#6b7280;font-size:14px}
        .ots-turnstile-error{display:none;color:#991b1b;font-size:14px;margin-top:8px}
        .ots-btn{display:inline-block;border:2px solid #990000;color:#990000;background:#fff;padding:14px 28px;text-transform:uppercase;font-weight:700;letter-spacing:1px;border-radius:4px;text-decoration:none;cursor:pointer}
        .ots-btn:hover{background:#990000;color:#fff}.ots-btn.ots-disabled,.ots-btn:disabled{opacity:.45;cursor:not-allowed;background:#f3f4f6;color:#777;border-color:#bbb}
        .ots-note{font-size:14px;color:#6b7280;margin-top:18px}.ots-no-key{border:1px solid #f59e0b;background:#fffbeb;color:#92400e;padding:12px;border-radius:6px}
        @media(max-width:900px){.ots-click-page{display:block}.ots-click-sidebar{width:auto;min-height:auto}.ots-click-main{padding:30px 20px}.ots-card{display:block}.ots-card img{width:100%;height:auto;margin-bottom:16px}.ots-redline{margin-bottom:35px}}
    </style>
</head>
<body>
<div class="ots-click-page">
    <aside class="ots-click-sidebar">
        <h3>Menu</h3>
        <a href="<?php echo esc_url(home_url('/a/anuncios/')); ?>">Anúncios</a>
        <a href="<?php echo esc_url(home_url('/a/usuarios/')); ?>">Usuários</a>
        <a href="<?php echo esc_url(home_url('/a/compartilhe-na-redes-sociais/')); ?>">Compartilhe na Redes Sociais</a>
        <a href="<?php echo esc_url(home_url('/a/meus-anuncios/')); ?>">Meus Anúncios</a>
        <a href="<?php echo esc_url(home_url('/a/criar-anuncio/')); ?>">Criar Anúncio</a>
        <a href="<?php echo esc_url(home_url('/a/programa-de-afiliados/')); ?>">Programa de Afiliados</a>
        <a href="<?php echo esc_url(home_url('/a/alterar-senha/')); ?>">Alterar Senha</a>
        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">Sair</a>
    </aside>

    <main class="ots-click-main">
        <?php echo ots_render_user_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <hr class="ots-redline">

        <section class="ots-click-section">
            <h1>Confirme para acessar o anúncio</h1>

            <?php if ($error): ?>
                <div class="ots-alert"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <div class="ots-card">
                <?php if (!empty($ad->image_url)): ?>
                    <img src="<?php echo esc_url($ad->image_url); ?>" alt="">
                <?php endif; ?>
                <div>
                    <h2><?php echo esc_html($title); ?></h2>
                    <p><?php echo esc_html($link_preview); ?></p>
                    <p>Para contabilizar o clique com segurança, marque o Cloudflare Turnstile abaixo e depois clique em <strong>Confirmar e acessar anúncio</strong>.</p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url($action_url); ?>" class="ots-form">
                <?php wp_nonce_field('ots_confirm_click_' . $ad_id, 'ots_click_nonce'); ?>
                <input type="hidden" name="ad" value="<?php echo esc_attr($ad_id); ?>">
                <input type="hidden" name="ots_confirm_click" value="1">

                <?php if ($has_turnstile): ?>
                    <div class="ots-turnstile-area">
                        <div>
                            <div id="ots-turnstile-loading" class="ots-loading">Carregando verificação Cloudflare Turnstile...</div>
                            <div
                                id="ots-turnstile-widget"
                                class="cf-turnstile"
                                data-sitekey="<?php echo esc_attr($site_key); ?>"
                                data-callback="otsTurnstileSuccess"
                                data-expired-callback="otsTurnstileExpired"
                                data-error-callback="otsTurnstileError"
                                data-theme="light"
                                data-size="normal"
                            ></div>
                            <div id="ots-turnstile-error" class="ots-turnstile-error">O Turnstile não carregou. Erro comum: Site Key inválida ou não vinculada ao domínio ofertatopsite.com.br. Crie uma chave do tipo Managed para este domínio no painel da Cloudflare.</div>
                            <noscript>Ative o JavaScript para confirmar o Cloudflare Turnstile.</noscript>
                        </div>
                    </div>
                    <button id="ots-confirm-btn" class="ots-btn ots-disabled" type="submit" disabled>Confirmar e acessar anúncio</button>
                <?php else: ?>
                    <div class="ots-no-key">Cloudflare Turnstile não está configurado. Cadastre a Site Key e a Secret Key em Oferta TOP Site &gt; Configurações.</div>
                    <button id="ots-confirm-btn" class="ots-btn" type="submit">Confirmar e acessar anúncio</button>
                <?php endif; ?>
            </form>
        </section>
    </main>
</div>
</body>
</html>
    <?php
    exit;
}

function ots_handle_click_redirect() {
    if (!ots_is_click_request()) return;

    $ad_id = absint($_REQUEST['ad'] ?? 0);
    if (!$ad_id) wp_die('Anúncio inválido.');

    global $wpdb;
    $ad = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ots_ads WHERE id=%d AND status='active'", $ad_id));
    if (!$ad || (int) $ad->remaining_clicks <= 0) wp_die('Anúncio não disponível.');

    $ref = ots_get_affiliate_ref_from_request();

    $needs_turnstile = ots_turnstile_enabled();
    $is_confirmed_request = isset($_POST['ots_confirm_click']);

    if ($needs_turnstile) {
        if (!$is_confirmed_request) {
            ots_render_click_challenge($ad);
        }

        $nonce_ok = isset($_POST['ots_click_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ots_click_nonce'])), 'ots_confirm_click_' . $ad_id);
        if (!$nonce_ok) {
            ots_render_click_challenge($ad, 'Sessão expirada. Confirme novamente para acessar o anúncio.');
        }

        if (!ots_verify_turnstile()) {
            ots_log_event('click_blocked', 'ad', $ad_id, 'Turnstile inválido', ['reason' => 'turnstile_failed']);
            ots_render_click_challenge($ad, 'Confirmação do Cloudflare Turnstile inválida. Tente novamente.');
        }
    }

    $ip = ots_get_user_ip();
    $cookie_hash = ots_cookie_hash();
    $visitor_hash = ots_tracking_visitor_hash();
    $tracking_key = ots_global_click_tracking_key($visitor_hash);
    $user_id = get_current_user_id() ?: null;
    $ua = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $is_account_user = (bool) $user_id;
    $is_admin = current_user_can(ots_admin_capability());
    $affiliate_user_id = ots_valid_affiliate_ref($ref, $ad);

    $blocked_reason = '';
    if ($is_account_user) {
        $blocked_reason = 'logged_user_no_count';
    } elseif ($is_admin) {
        $blocked_reason = 'admin_no_count';
    } elseif (ots_is_suspicious_user_agent()) {
        $blocked_reason = 'suspicious_user_agent';
    } elseif (ots_click_lock_exists($visitor_hash, $cookie_hash)) {
        $blocked_reason = 'global_click_already_counted';
    }

    if ($blocked_reason) {
        ots_log_event('click_blocked', 'ad', $ad_id, 'Clique não contabilizado: ' . $blocked_reason, [
            'reason' => $blocked_reason,
            'visitor_hash' => $visitor_hash,
            'cookie_hash' => $cookie_hash,
            'ref' => $ref,
            'affiliate_user_id' => $affiliate_user_id,
        ]);
        wp_redirect(esc_url_raw($ad->link));
        exit;
    }

    // Trava global antes de consumir saldo. Se outro clique com o mesmo visitante já entrou, não contabiliza.
    if (!ots_create_click_lock($visitor_hash, $ad_id, $affiliate_user_id, 'valid_click')) {
        ots_log_event('click_blocked', 'ad', $ad_id, 'Clique não contabilizado: trava global existente', [
            'reason' => 'global_lock_exists',
            'visitor_hash' => $visitor_hash,
            'cookie_hash' => $cookie_hash,
        ]);
        wp_redirect(esc_url_raw($ad->link));
        exit;
    }

    $commission_amount = $affiliate_user_id ? (float) ots_click_commission() : 0.0;
    $commission_status = $affiliate_user_id && $commission_amount > 0 ? 'pending_credit' : 'none';

    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}ots_clicks
            (ad_id, user_id, ip_address, user_agent, cookie_hash, clicked_at, visitor_hash, tracking_key, affiliate_user_id, commission_amount, commission_status)
         VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %f, %s)",
        $ad_id,
        null,
        $ip,
        $ua,
        $cookie_hash,
        current_time('mysql'),
        $visitor_hash,
        $tracking_key,
        $affiliate_user_id ?: null,
        $commission_amount,
        $commission_status
    ));

    if ($inserted) {
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ots_ads
             SET clicks=clicks+1, remaining_clicks=IF(remaining_clicks>0, remaining_clicks-1, 0), updated_at=%s
             WHERE id=%d AND remaining_clicks > 0 AND status='active'",
            current_time('mysql'),
            $ad_id
        ));

        if ($updated) {
            if ($affiliate_user_id && $commission_amount > 0) {
                $credited = ots_add_affiliate_commission($affiliate_user_id, $ad_id, $commission_amount, 'Comissão por clique válido via link de afiliado');
                if ($credited) {
                    $wpdb->update($wpdb->prefix . 'ots_clicks', ['commission_status' => 'credited'], ['tracking_key' => $tracking_key]);
                }
            }
            ots_log_event('click_counted', 'ad', $ad_id, 'Clique único global contabilizado', [
                'visitor_hash' => $visitor_hash,
                'affiliate_user_id' => $affiliate_user_id,
                'commission_amount' => $commission_amount,
            ]);
            ots_maybe_finish_ad($ad_id);
        } else {
            // Hardening v1.2.1: se o último clique foi consumido em paralelo por outra requisição,
            // desfaz a trava e remove o registro não contabilizado para não bloquear injustamente o visitante.
            $wpdb->delete($wpdb->prefix . 'ots_clicks', ['tracking_key' => $tracking_key]);
            $wpdb->delete($wpdb->prefix . 'ots_click_locks', ['lock_key' => ots_global_click_tracking_key($visitor_hash)]);
            ots_log_event('click_blocked', 'ad', $ad_id, 'Clique não contabilizado por corrida de saldo ou anúncio indisponível', [
                'reason' => 'race_or_no_remaining_clicks',
                'visitor_hash' => $visitor_hash,
            ]);
        }
    }

    wp_redirect(esc_url_raw($ad->link));
    exit;
}

add_action('wp_ajax_ots_track_impression', 'ots_track_impression_ajax');
add_action('wp_ajax_nopriv_ots_track_impression', 'ots_track_impression_ajax');
function ots_track_impression_ajax() {
    $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
    if (!$nonce || !wp_verify_nonce($nonce, 'ots_track_impression')) {
        wp_send_json_error(['message' => 'Falha de segurança.'], 403);
    }

    $ad_id = absint($_POST['ad_id'] ?? 0);
    if (!$ad_id) {
        wp_send_json_error(['message' => 'Anúncio inválido.'], 400);
    }

    $registered = ots_register_impression($ad_id, 'normal');
    wp_send_json_success(['registered' => (bool) $registered]);
}
