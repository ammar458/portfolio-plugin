<?php

if (!defined('ABSPATH')) {
    exit;
}


// Shortcode: [portfolio_filtros]

function shortcode_portfolio_filtros() {

    $terms = get_terms([
        'taxonomy'   => 'tipo_portafolio',
        'hide_empty' => false,
    ]);

    usort($terms, function ($a, $b) {
        $order_a = get_field('orden_menu', $a);
        $order_b = get_field('orden_menu', $b);
        return $order_a - $order_b;
    });

    ob_start();
    ?>

    <div class="filtros-wrapper-contenedor">

        <button class="chevron chevron-left" aria-label="Previous">&#8249;</button>

        <div class="filtros-responsive-wrapper">
            <div class="filtros-portafolio-scroll">

                <a href="#all" class="filtro style-btn activo" data-filtro="*">ALL</a>

                <?php foreach ($terms as $term): ?>
                    <a href="#<?= esc_attr($term->slug) ?>" class="filtro style-btn" data-filtro=".<?= esc_attr($term->slug) ?>">
                        <?= esc_html($term->name) ?>
                    </a>
                <?php endforeach; ?>

            </div>
        </div>

        <button class="chevron chevron-right" aria-label="Next">&#8250;</button>

    </div>

    <?php
    return ob_get_clean();
}

add_shortcode('portfolio_filtros', 'shortcode_portfolio_filtros');


/**
 * Look up a Vimeo video's real width/height via its oEmbed endpoint, so a
 * bare vimeo.com URL (no pasted iframe) still gets sized to its true aspect
 * ratio instead of defaulting to landscape. Cached in post meta - only ever
 * hits the network once per video.
 */
function portfolio_get_vimeo_ratio($post_id, $vimeo_page_url) {
    $cache_key = '_video_ratio_' . md5($vimeo_page_url);
    $cached    = get_post_meta($post_id, $cache_key, true);
    if ($cached) {
        return $cached === 'none' ? '' : $cached;
    }

    $response = wp_remote_get(
        'https://vimeo.com/api/oembed.json?url=' . urlencode($vimeo_page_url),
        ['timeout' => 5]
    );

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        update_post_meta($post_id, $cache_key, 'none');
        return '';
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['width']) || empty($data['height'])) {
        update_post_meta($post_id, $cache_key, 'none');
        return '';
    }

    $ratio = $data['width'] . '/' . $data['height'];
    update_post_meta($post_id, $cache_key, $ratio);
    return $ratio;
}


// Shortcode: [portfolio_galeria]
function shortcode_portfolio_galeria() {

    // IDs to show first (in the given order)
    $priority_ids = [33248, 32544, 32686, 32581, 32644, 51116];

    // ---------- RENDER FUNCTION ----------
    $render_item = function ($post_id) {

        $terms        = get_the_terms($post_id, 'tipo_portafolio');
        $term_classes = $terms ? join(' ', wp_list_pluck($terms, 'slug')) : '';
        $img_main     = get_the_post_thumbnail_url($post_id, 'large');
        $type         = strtolower((string) get_field('tipo_de_contenido', $post_id));
        $img_sec      = get_field('imagen_secundaria', $post_id);
        // Editors can fill in either field - Embed Code takes priority if both are set.
        $raw_url = trim((string) get_field('video_embed_code', $post_id));
        if (!$raw_url) {
            $raw_url = trim((string) get_field('video_url', $post_id));
        }

        // Allow pasting a full <iframe> embed snippet (e.g. Vimeo/YouTube's
        // "Share > Embed" code) instead of a bare URL - pull the src out of it,
        // and keep its exact width/height so the lightbox can preserve the
        // video's real aspect ratio instead of guessing a bucketed format.
        $video_ratio = ''; // e.g. "1080/1920" - exact ratio from a pasted iframe
        if ($raw_url && stripos($raw_url, '<iframe') !== false) {
            if (preg_match('#width=["\'](\d+)["\']#i', $raw_url, $w) && preg_match('#height=["\'](\d+)["\']#i', $raw_url, $h)) {
                $video_ratio = $w[1] . '/' . $h[1];
            }
            if (preg_match('#src=["\']([^"\']+)["\']#i', $raw_url, $ifr)) {
                $raw_url = html_entity_decode($ifr[1]);
            }
        }

        // Normalize YouTube URLs (including Shorts)
        $video_url = '';
        $is_vertical = false;
        $is_vimeo    = false;
        if ($raw_url) {
            // YouTube Shorts: youtube.com/shorts/VIDEO_ID
            if (preg_match('#youtube\.com/shorts/([a-zA-Z0-9_-]+)#', $raw_url, $m)) {
                $video_url = 'https://www.youtube.com/embed/' . $m[1];
                $is_vertical = true;
            // youtu.be/VIDEO_ID (short link, may also be a Short)
            } elseif (strpos($raw_url, 'youtu.be/') !== false) {
                $path      = ltrim(parse_url($raw_url, PHP_URL_PATH), '/');
                $video_id  = preg_replace('#^shorts/#', '', $path);
                $video_url = 'https://www.youtube.com/embed/' . $video_id;
            // Standard youtube.com/watch?v=VIDEO_ID
            } elseif (strpos($raw_url, 'youtube.com/watch') !== false) {
                parse_str((string) parse_url($raw_url, PHP_URL_QUERY), $params);
                if (!empty($params['v'])) {
                    $video_url = 'https://www.youtube.com/embed/' . $params['v'];
                }
            // Vimeo Showcase (album): vimeo.com/showcase/ID
            } elseif (preg_match('#vimeo\.com/showcase/(\d+)#', $raw_url, $m)) {
                $video_url = 'https://vimeo.com/showcase/' . $m[1] . '/embed';
                $is_vimeo  = true;
                parse_str((string) parse_url($raw_url, PHP_URL_QUERY), $vparams);
                if (!empty($vparams['h'])) {
                    $video_url .= '?h=' . $vparams['h'];
                }
                if (!$video_ratio) {
                    $video_ratio = portfolio_get_vimeo_ratio($post_id, $raw_url);
                }
            // Vimeo: vimeo.com/ID, vimeo.com/ID/HASH (private share link), or vimeo.com/video/ID
            } elseif (preg_match('#vimeo\.com/(?:video/)?(\d+)(?:/([0-9a-zA-Z]+))?#', $raw_url, $m)) {
                $vimeo_hash = $m[2] ?? '';
                $is_vimeo   = true;
                if (!$vimeo_hash) {
                    parse_str((string) parse_url($raw_url, PHP_URL_QUERY), $vparams);
                    $vimeo_hash = $vparams['h'] ?? '';
                }
                $video_url = 'https://player.vimeo.com/video/' . $m[1];
                if ($vimeo_hash) {
                    $video_url .= '?h=' . $vimeo_hash;
                }
                // No pasted iframe dimensions - ask Vimeo's oEmbed API for the
                // video's real width/height so orientation is detected correctly.
                if (!$video_ratio) {
                    $video_ratio = portfolio_get_vimeo_ratio($post_id, $raw_url);
                }
            } else {
                $video_url = $raw_url;
            }
        }

        // Decide which URL to open in the lightbox
        if ($type === 'video' && $video_url) {
            $href      = $video_url;
            $data_type = 'video';
        } else {
            $href      = $img_sec ?: $img_main;
            $data_type = 'image';
        }

        if (!$img_main || !$href) return '';

        // Tag the video's real aspect ratio so JS can size the lightbox to
        // fit it exactly, rather than snapping to a fixed landscape/short bucket.
        // Vimeo intentionally gets NO fallback here: if the real ratio couldn't
        // be detected (iframe paste or oEmbed), we'd rather show that plainly
        // than silently mask it behind a guessed 16:9 box.
        $glightbox_extra = '';
        if ($data_type === 'video') {
            if (!$video_ratio && !$is_vimeo) {
                $video_ratio = $is_vertical ? '9/16' : '16/9';
            }
            if ($video_ratio) {
                $glightbox_extra = 'data-video-ratio="' . esc_attr($video_ratio) . '"';
            } elseif ($is_vimeo) {
                $glightbox_extra = 'data-video-noratio="1"';
            }
        }

        ob_start(); ?>
        <div class="item-portafolio <?php echo esc_attr($term_classes); ?>" style="position:relative;">
            <a href="<?php echo esc_url($href); ?>"
               class="glightbox"
               data-gallery="galeria"
               <?php echo ($data_type === 'video' ? 'data-type="video"' : ''); ?>
               <?php echo $glightbox_extra; ?>>
                <img src="<?php echo esc_url($img_main); ?>"
                     alt="<?php echo esc_attr(get_the_title($post_id)); ?>"
                     loading="lazy">
                <?php if ($data_type === 'video') : ?>
                <span class="portfolio-play-btn" aria-hidden="true" style="position:absolute;top:0;left:0;right:0;bottom:0;width:60px;height:60px;margin:auto;display:block;pointer-events:none;z-index:2;">
                    <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="30" cy="30" r="27" fill="#e3121f" stroke="#ffffff" stroke-width="3"/>
                        <polygon points="24,17 46,30 24,43" fill="#ffffff"/>
                    </svg>
                </span>
                <?php endif; ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    };

    // ---------- OUTPUT ----------
    ob_start();
    echo '<div class="grid-portafolio">';

    // 1. Show manually prioritized posts first (in the given order)
    $priority_query = new WP_Query([
        'post_type'      => 'portfolio',
        'post__in'       => $priority_ids,
        'orderby'        => 'post__in',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $shown_ids = [];
    if ($priority_query->have_posts()) {
        while ($priority_query->have_posts()) {
            $priority_query->the_post();
            $shown_ids[] = get_the_ID();
            echo $render_item(get_the_ID());
        }
        wp_reset_postdata();
    }

    // 2. Show all remaining posts (excluding already shown)
    $rest_query = new WP_Query([
        'post_type'      => 'portfolio',
        'posts_per_page' => -1,
        'orderby'        => 'ASC',
        'post_status'    => 'publish',
        'post__not_in'   => $shown_ids,
    ]);

    if ($rest_query->have_posts()) {
        while ($rest_query->have_posts()) {
            $rest_query->the_post();
            echo $render_item(get_the_ID());
        }
        wp_reset_postdata();
    }

    echo '</div>';
    return ob_get_clean();
}
add_shortcode('portfolio_galeria', 'shortcode_portfolio_galeria');
