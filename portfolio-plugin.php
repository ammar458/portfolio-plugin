<?php
/*
Plugin Name: Custom Portfolio
Description: Plugin to manage and display a portfolio with filters and lightbox.
Version: 1.42
Author: Ricardo Frassati
GitHub Plugin URI: ammar458/portfolio-plugin
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/cpt-portfolio.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcode-galeria.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcode-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-github-updater.php';

if (is_admin()) {
    new Portfolio_Plugin_GitHub_Updater(__FILE__, 'ammar458', 'portfolio-plugin');
}

function portfolio_plugin_assets() {
    $plugin_path = plugin_dir_path(__FILE__);
    $plugin_url  = plugin_dir_url(__FILE__);

    wp_enqueue_style('portfolio-glightbox-css', $plugin_url . 'assets/glightbox.min.css', [], filemtime($plugin_path . 'assets/glightbox.min.css'));
    wp_enqueue_script('portfolio-glightbox-js', $plugin_url . 'assets/glightbox.min.js', [], '3.2.0', true);
    wp_enqueue_script('portfolio-glightbox-init', $plugin_url . 'assets/glightbox-init.js', ['portfolio-glightbox-js'], filemtime($plugin_path . 'assets/glightbox-init.js'), true);
    wp_enqueue_script('portfolio-filters-scroll', $plugin_url . 'assets/filtros-scroll.js', ['jquery'], filemtime($plugin_path . 'assets/filtros-scroll.js'), true);
    wp_enqueue_script('portfolio-dashboard-tabs', $plugin_url . 'assets/dashboard-tabs.js', ['portfolio-glightbox-init'], filemtime($plugin_path . 'assets/dashboard-tabs.js'), true);

    // Isotope loaded inline so WP Rocket cannot delay or 404 it
    wp_enqueue_script('isotope-js', 'https://unpkg.com/isotope-layout@3/dist/isotope.pkgd.min.js', ['jquery'], '3.0.6', true);

    wp_enqueue_script('portfolio-filters', $plugin_url . 'assets/filtros.js', ['jquery'], filemtime($plugin_path . 'assets/filtros.js'), true);

    // filemtime() as version forces browsers/WP Rocket to fetch the new file instead of a stale cached copy
    wp_enqueue_style('portfolio-styles', $plugin_url . 'assets/portfolio-styles.min.css', [], filemtime($plugin_path . 'assets/portfolio-styles.min.css'));
}
add_action('wp_enqueue_scripts', 'portfolio_plugin_assets');