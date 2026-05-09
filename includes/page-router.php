<?php
if (!defined('ABSPATH')) exit;

/**
 * Força as páginas do painel /a/... a renderizarem o shortcode correto.
 * Isso evita conflito com temas que ignoram the_content() ou possuem template estático antigo.
 */
function ots_panel_shortcode_map() {
    return [
        'anuncios' => '[ots_anuncios]',
        'criar-anuncio' => '[ots_criar_anuncio]',
        'compartilhe-na-redes-sociais' => '[ots_compartilhar]',
        'meus-anuncios' => '[ots_meus_anuncios]',
        'programa-de-afiliados' => '[ots_programa_afiliados]',
        'usuarios' => '[ots_usuarios]',
        'alterar-senha' => '[ots_alterar_senha]',
    ];
}

function ots_current_panel_shortcode() {
    if (is_admin() || !is_page()) {
        return '';
    }

    global $post;
    if (!$post || empty($post->post_name)) {
        return '';
    }

    $map = ots_panel_shortcode_map();
    $slug = sanitize_title($post->post_name);

    if (!isset($map[$slug])) {
        return '';
    }

    // Só força quando a URL pertence ao painel /a/ ou quando a página tem o shortcode no conteúdo.
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $content = isset($post->post_content) ? $post->post_content : '';
    $has_shortcode = false;
    foreach ($map as $sc) {
        $tag = trim($sc, '[]');
        if (has_shortcode($content, $tag)) {
            $has_shortcode = true;
            break;
        }
    }

    if (strpos($uri, '/a/') !== false || $has_shortcode) {
        return $map[$slug];
    }

    return '';
}

add_filter('template_include', function ($template) {
    $shortcode = ots_current_panel_shortcode();
    if (!$shortcode) {
        return $template;
    }

    $plugin_template = OTS_PLUGIN_PATH . 'templates/ots-panel-page.php';
    if (file_exists($plugin_template)) {
        return $plugin_template;
    }

    return $template;
}, 99);

// Garante que os shortcodes sejam processados até em temas muito antigos.
add_filter('the_content', function ($content) {
    if (is_admin()) {
        return $content;
    }
    return do_shortcode($content);
}, 9);
