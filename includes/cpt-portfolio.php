<?php
if (!defined('ABSPATH')) {
    exit;
}

function register_portfolio_cpt() {
    // Custom Post Type: Portfolio
    register_post_type('portfolio', [
        'label' => 'Portfolio',
        'public' => true,
        'menu_icon' => 'dashicons-format-gallery',
        'supports' => ['title', 'thumbnail'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'portfolio'],
        'show_in_rest' => true,
    ]);

    // Taxonomy: Portfolio Type
    register_taxonomy('tipo_portafolio', 'portfolio', [
        'label' => 'Project Type',
        'hierarchical' => true,
        'public' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'portfolio-type'],
    ]);
}
add_action('init', 'register_portfolio_cpt');

/**
 * Fix the Project Type dropdown on the Portfolio admin list.
 *
 * Some admin taxonomy filters submit the selected term ID, for example
 * tipo_portafolio=62. WordPress normally treats a taxonomy query variable as
 * a term slug, so a numeric ID can return no results. Convert a valid term ID
 * to its slug before WordPress builds the taxonomy query.
 */
function ringo_portfolio_fix_admin_project_type_filter($query) {
    if (!is_admin() || !($query instanceof WP_Query)) {
        return;
    }

    global $pagenow;

    if ('edit.php' !== $pagenow || 'portfolio' !== $query->get('post_type')) {
        return;
    }

    $selected_term = $query->get('tipo_portafolio');

    if (empty($selected_term) || !is_numeric($selected_term)) {
        return;
    }

    $term = get_term((int) $selected_term, 'tipo_portafolio');

    if ($term && !is_wp_error($term)) {
        $query->set('tipo_portafolio', $term->slug);
    }
}
add_action('parse_query', 'ringo_portfolio_fix_admin_project_type_filter');
