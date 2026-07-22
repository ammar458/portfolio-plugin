<?php
/*
Plugin Name: Custom Portfolio
Description: Plugin to manage and display a portfolio with filters and lightbox.
Version: 1.22
Author: Ricardo Frassati
GitHub Plugin URI: ammar458/portfolio-plugin
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/cpt-portfolio.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcode-galeria.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-github-updater.php';

if (is_admin()) {
    new Portfolio_Plugin_GitHub_Updater(__FILE__, 'ammar458', 'portfolio-plugin');
}

function portfolio_plugin_assets() {
    wp_enqueue_style('portfolio-glightbox-css', plugin_dir_url(__FILE__) . 'assets/glightbox.min.css');
    wp_enqueue_script('portfolio-glightbox-js', plugin_dir_url(__FILE__) . 'assets/glightbox.min.js', [], '3.2.0', true);
    wp_enqueue_script('portfolio-glightbox-init', plugin_dir_url(__FILE__) . 'assets/glightbox-init.js', ['portfolio-glightbox-js'], '1.0', true);
    wp_enqueue_script('portfolio-filters-scroll', plugin_dir_url(__FILE__) . 'assets/filtros-scroll.js', ['jquery'], '1.0', true);

    // Isotope loaded inline so WP Rocket cannot delay or 404 it
    wp_enqueue_script('isotope-js', 'https://unpkg.com/isotope-layout@3/dist/isotope.pkgd.min.js', ['jquery'], '3.0.6', true);

    wp_enqueue_script('portfolio-filters', plugin_dir_url(__FILE__) . 'assets/filtros.js', ['jquery'], '1.1', true);

    wp_enqueue_style('portfolio-styles', plugin_dir_url(__FILE__) . 'assets/estilos.css');
}
add_action('wp_enqueue_scripts', 'portfolio_plugin_assets');

// Video slides open a direct iframe to the provider (see glightbox-init.js) -
// preconnecting means the DNS/TLS handshake for that new origin is already
// done by the time someone actually opens a video, instead of starting cold
// at click time.
function portfolio_plugin_video_preconnects() {
    echo '<link rel="preconnect" href="https://player.vimeo.com">' . "\n";
    echo '<link rel="preconnect" href="https://i.vimeocdn.com">' . "\n";
    echo '<link rel="preconnect" href="https://www.youtube.com">' . "\n";
    echo '<link rel="preconnect" href="https://www.youtube-nocookie.com">' . "\n";
}
add_action('wp_head', 'portfolio_plugin_video_preconnects', 1);