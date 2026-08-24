<?php

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Portfolio Type terms to show in a filter bar: published items only,
 * "Hide from filter bar" (ocultar_categoria) terms excluded, ordered by the
 * "orden_menu" ACF field. Shared by every shortcode that renders a category
 * filter/tab bar.
 */
function portfolio_visible_terms() {
    // Only include categories that contain at least one published portfolio
    // item. A second frontend check removes any term whose posts cannot be
    // rendered, such as an item missing its required media.
    $terms = get_terms([
        'taxonomy'   => 'tipo_portafolio',
        'hide_empty' => true,
    ]);

    if (is_wp_error($terms)) {
        $terms = [];
    }

    // Skip categories marked "Hide from filter bar" (ACF true/false field
    // "ocultar_categoria" on the tipo_portafolio taxonomy). Checking for
    // "hidden" rather than "enabled" means existing categories with no value
    // set yet default to shown - no migration needed for categories created
    // before this field existed.
    $terms = array_values(array_filter($terms, function ($term) {
        return !get_field('ocultar_categoria', $term);
    }));

    usort($terms, function ($a, $b) {
        $order_a = get_field('orden_menu', $a);
        $order_b = get_field('orden_menu', $b);
        return $order_a - $order_b;
    });

    return $terms;
}


// Shortcode: [portfolio_filtros]

function shortcode_portfolio_filtros() {

    $terms = portfolio_visible_terms();

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
    if ($cached && $cached !== 'none') {
        return $cached;
    }
    if ($cached === 'none') {
        // Stale permanent-failure marker from an older version (pre-v1.10)
        // that cached failed lookups forever instead of retrying - clear it
        // so this video gets a fresh attempt instead of being stuck.
        delete_post_meta($post_id, $cache_key);
    }

    // Short-lived failure cache: retries automatically on the next page
    // load instead of getting stuck if this was just a transient network
    // hiccup (unlike a permanent post-meta "failed" marker would).
    $fail_key = 'ppgh_vimeo_fail_' . md5($vimeo_page_url);
    if (get_transient($fail_key)) {
        return '';
    }

    $response = wp_remote_get(
        'https://vimeo.com/api/oembed.json?url=' . urlencode($vimeo_page_url),
        ['timeout' => 5]
    );

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        set_transient($fail_key, 1, HOUR_IN_SECONDS);
        return '';
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['width']) || empty($data['height'])) {
        set_transient($fail_key, 1, HOUR_IN_SECONDS);
        return '';
    }

    $ratio = $data['width'] . '/' . $data['height'];
    update_post_meta($post_id, $cache_key, $ratio);
    return $ratio;
}


/**
 * Term IDs whose "Hide from filter bar" (ocultar_categoria) ACF field is
 * checked. Shared by every shortcode that needs to pull a whole category
 * offline (e.g. while a display bug is being fixed) with one checkbox
 * instead of a separate toggle per shortcode.
 */
function portfolio_hidden_term_ids() {
    $hidden_term_ids = [];
    foreach (get_terms(['taxonomy' => 'tipo_portafolio', 'hide_empty' => false]) as $term) {
        if (get_field('ocultar_categoria', $term)) {
            $hidden_term_ids[] = $term->term_id;
        }
    }
    return $hidden_term_ids;
}

/**
 * tax_query clause that excludes posts in any hidden category. Returns []
 * (no-op) when nothing is hidden, so it can always be merged into a
 * WP_Query args array unconditionally.
 */
function portfolio_visible_tax_query() {
    $hidden_term_ids = portfolio_hidden_term_ids();
    if (!$hidden_term_ids) {
        return [];
    }
    return [[
        'taxonomy' => 'tipo_portafolio',
        'field'    => 'term_id',
        'terms'    => $hidden_term_ids,
        'operator' => 'NOT IN',
    ]];
}

/**
 * Resolves a portfolio item's display media: which image/video to open,
 * the lightbox data-type, and the video-card overlay copy. Shared by every
 * shortcode that renders a portfolio item, so the YouTube/Vimeo URL
 * normalization and ratio detection only lives in one place.
 *
 * Returns null when the item has no thumbnail or no resolvable target
 * (nothing to render).
 */
function portfolio_prepare_media($post_id) {

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

        if (!$img_main || !$href) return null;

        // Tag the video's real aspect ratio so JS can size the lightbox to fit
        // it exactly, rather than snapping to a fixed landscape/short bucket.
        // Falls back to a plausible default (16:9, or 9:16 for detected
        // verticals) whenever the real ratio can't be detected, so a failed
        // oEmbed lookup never leaves the video cropped/unstyled.
        //
        // Encoded directly into the video URL (query params, ignored by the
        // player) rather than a data-* attribute on the trigger element:
        // GLightbox sets the rendered iframe's src to this exact URL, so JS
        // can read the ratio straight off the actual slide being shown. A
        // data-attribute lookup would need to re-match the trigger element by
        // slide index, which breaks as soon as Isotope filtering reorders
        // the grid out from under GLightbox's original element order.
        if ($data_type === 'video') {
            $ratio_is_guess = !$video_ratio;
            if (!$video_ratio) {
                $video_ratio = $is_vertical ? '9/16' : '16/9';
            }
            $sep  = (strpos($href, '?') !== false) ? '&' : '?';
            $href .= $sep . 'gvratio=' . rawurlencode($video_ratio);
            if ($ratio_is_guess) {
                // Server couldn't confirm the real ratio (e.g. oEmbed request
                // blocked on this host) - let the browser try instead, since
                // it isn't subject to the server's outbound request limits.
                $href .= '&gvguess=1';
            }
        }

        // Video-card content comes only from ACF. Empty fields stay empty and
        // their matching card elements are not rendered.
        $video_card_category = '';
        $video_card_title = '';
        $video_card_subtitle = '';
        $video_card_views = '';
        $video_card_lead_increase = '';

        if ($data_type === 'video' && function_exists('get_field')) {
            $video_card_category = trim((string) get_field('video_card_category', $post_id));
            $video_card_title = trim((string) get_field('video_card_title', $post_id));
            $video_card_subtitle = trim((string) get_field('video_card_subtitle', $post_id));
            $video_card_views = trim((string) get_field('video_card_views', $post_id));
            $video_card_lead_increase = trim((string) get_field('video_card_lead_increase', $post_id));
        }

        $video_accessible_title = $video_card_title ?: get_the_title($post_id);
        $has_video_card_copy = ($video_card_title !== '' || $video_card_subtitle !== '');
        $has_video_card_stats = ($video_card_views !== '' || $video_card_lead_increase !== '');

    return [
        'img_main'                 => $img_main,
        'href'                     => $href,
        'data_type'                => $data_type,
        'term_classes'             => $term_classes,
        'video_card_category'      => $video_card_category,
        'video_card_title'         => $video_card_title,
        'video_card_subtitle'      => $video_card_subtitle,
        'video_card_views'         => $video_card_views,
        'video_card_lead_increase' => $video_card_lead_increase,
        'video_accessible_title'   => $video_accessible_title,
        'has_video_card_copy'      => $has_video_card_copy,
        'has_video_card_stats'     => $has_video_card_stats,
    ];
}


// Shortcode: [portfolio_galeria]
function shortcode_portfolio_galeria() {

    // IDs to show first (in the given order)
    $priority_ids = [33248, 32544, 32686, 32581, 32644, 51116];

    // Categories marked "Hide from filter bar" (ocultar_categoria) are
    // pulled from the grid entirely too, not just their filter button - lets
    // a whole category be taken offline with one checkbox (e.g. while a
    // display bug is being fixed and tested) instead of a separate toggle.
    $tax_query = portfolio_visible_tax_query();

    // ---------- RENDER FUNCTION ----------
    $render_item = function ($post_id) {

        $media = portfolio_prepare_media($post_id);
        if (!$media) return '';

        $img_main                 = $media['img_main'];
        $href                     = $media['href'];
        $data_type                = $media['data_type'];
        $video_card_category      = $media['video_card_category'];
        $video_card_title         = $media['video_card_title'];
        $video_card_subtitle      = $media['video_card_subtitle'];
        $video_card_views         = $media['video_card_views'];
        $video_card_lead_increase = $media['video_card_lead_increase'];
        $video_accessible_title   = $media['video_accessible_title'];
        $has_video_card_copy      = $media['has_video_card_copy'];
        $has_video_card_stats     = $media['has_video_card_stats'];

        $item_classes = trim('item-portafolio ' . $media['term_classes'] . ($data_type === 'video' ? ' is-video' : ''));

        ob_start(); ?>
        <div class="<?php echo esc_attr($item_classes); ?>">
            <a href="<?php echo esc_url($href); ?>"
               class="glightbox"
               data-gallery="galeria"
               aria-label="<?php echo esc_attr($data_type === 'video' ? 'Play ' . $video_accessible_title . ' video' : 'Open ' . get_the_title($post_id)); ?>"
               <?php echo ($data_type === 'video' ? 'data-type="video"' : ''); ?>>
                <img src="<?php echo esc_url($img_main); ?>"
                     alt="<?php echo esc_attr(get_the_title($post_id)); ?>"
                     loading="lazy">

                <?php if ($data_type === 'video') : ?>
                    <div class="video-card-overlay" aria-hidden="true">

                        <div class="video-card-body">
                            <?php if ($video_card_category !== '') : ?>
                                <span class="video-card-category"><?php echo esc_html($video_card_category); ?></span>
                            <?php endif; ?>

                            <div class="video-card-main-row<?php echo $has_video_card_copy ? '' : ' play-only'; ?>">
                                <?php if ($has_video_card_copy) : ?>
                                    <div class="video-card-copy">
                                        <?php if ($video_card_title !== '') : ?>
                                            <h3 class="video-card-title"><?php echo esc_html($video_card_title); ?></h3>
                                        <?php endif; ?>

                                        <?php if ($video_card_subtitle !== '') : ?>
                                            <p class="video-card-subtitle"><?php echo esc_html($video_card_subtitle); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <span class="portfolio-play-btn">
                                    <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                        <circle cx="32" cy="32" r="29" fill="none" stroke="#ffffff" stroke-width="2"/>
                                        <path d="M27 21.5 45 32 27 42.5Z" fill="#ffffff"/>
                                    </svg>
                                </span>
                            </div>

                            <?php if ($has_video_card_stats) : ?>
                                <div class="video-card-stats">
                                    <?php if ($video_card_views !== '') : ?>
                                        <span class="video-card-stat">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                                <path d="M2.2 12s3.6-6 9.8-6 9.8 6 9.8 6-3.6 6-9.8 6-9.8-6-9.8-6Zm9.8 3.6a3.6 3.6 0 1 0 0-7.2 3.6 3.6 0 0 0 0 7.2Z" fill="currentColor"/>
                                            </svg>
                                            <span><?php echo esc_html($video_card_views); ?></span>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($video_card_lead_increase !== '') : ?>
                                        <span class="video-card-stat">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                                <path d="M4 19V11h3v8H4Zm6 0V5h3v14h-3Zm6 0v-6h3v6h-3Z" fill="currentColor"/>
                                            </svg>
                                            <span><?php echo esc_html($video_card_lead_increase); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
        'tax_query'      => $tax_query,
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
        'tax_query'      => $tax_query,
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
