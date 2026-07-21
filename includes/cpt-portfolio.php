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
