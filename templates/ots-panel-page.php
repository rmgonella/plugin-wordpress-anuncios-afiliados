<?php
if (!defined('ABSPATH')) exit;
$ots_shortcode = function_exists('ots_current_panel_shortcode') ? ots_current_panel_shortcode() : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('ots-standalone-panel'); ?>>
<?php if (function_exists('wp_body_open')) { wp_body_open(); } ?>
<?php
if ($ots_shortcode) {
    echo do_shortcode($ots_shortcode);
} else {
    while (have_posts()) {
        the_post();
        the_content();
    }
}
wp_footer();
?>
</body>
</html>
