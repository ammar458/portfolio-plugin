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
 *
 * Never makes that network call on the request that's rendering the grid:
 * this host has been observed to have Vimeo's oEmbed endpoint blocked/slow
 * outright, and portfolio_galeria renders every item in one pass before any
 * HTML reaches the browser - one stalled oEmbed call (up to the old 5s
 * timeout) delayed the *entire* grid, image items included, not just the
 * video it belonged to. With several uncached videos on one page that
 * compounds into the whole "everything is slow to load" symptom.
 *
 * Instead this returns immediately (falling back to a guessed ratio, same
 * as an outright failure - the browser corrects it once the lightbox opens,
 * via fetchVimeoRatioClientSide in glightbox-init.js, which isn't subject to
 * this server's network restrictions) and schedules the real lookup as a
 * background WP-Cron job, so it never blocks a real visitor and the cache
 * is warm for next time.
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

    // Short-lived failure cache: retries automatically once this expires
    // instead of getting stuck if this was just a transient network hiccup
    // (unlike a permanent post-meta "failed" marker would).
    $fail_key = 'ppgh_vimeo_fail_' . md5($vimeo_page_url);
    if (get_transient($fail_key)) {
        return '';
    }

    if (!wp_next_scheduled('portfolio_fetch_vimeo_ratio_event', [$post_id, $vimeo_page_url])) {
        wp_schedule_single_event(time(), 'portfolio_fetch_vimeo_ratio_event', [$post_id, $vimeo_page_url]);
    }

    return '';
}

/**
 * The actual oEmbed network call, moved out of portfolio_get_vimeo_ratio so
 * it runs as a background WP-Cron job instead of blocking a visitor's page
 * load (see the comment above that function for why).
 */
function portfolio_fetch_vimeo_ratio_bg($post_id, $vimeo_page_url) {
    $cache_key = '_video_ratio_' . md5($vimeo_page_url);
    $fail_key  = 'ppgh_vimeo_fail_' . md5($vimeo_page_url);

    if (get_transient($fail_key)) {
        return;
    }

    $response = wp_remote_get(
        'https://vimeo.com/api/oembed.json?url=' . urlencode($vimeo_page_url),
        ['timeout' => 10]
    );

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        set_transient($fail_key, 1, HOUR_IN_SECONDS);
        return;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['width']) || empty($data['height'])) {
        set_transient($fail_key, 1, HOUR_IN_SECONDS);
        return;
    }

    update_post_meta($post_id, $cache_key, $data['width'] . '/' . $data['height']);
}
add_action('portfolio_fetch_vimeo_ratio_event', 'portfolio_fetch_vimeo_ratio_bg', 10, 2);


// Shortcode: [portfolio_galeria]
function shortcode_portfolio_galeria() {

    // IDs to show first (in the given order)
    $priority_ids = [33248, 32544, 32686, 32581, 32644, 51116];

    // Categories marked "Hide from filter bar" (ocultar_categoria) are
    // pulled from the grid entirely too, not just their filter button - lets
    // a whole category be taken offline with one checkbox (e.g. while a
    // display bug is being fixed and tested) instead of a separate toggle.
    $hidden_term_ids = [];
    foreach (get_terms(['taxonomy' => 'tipo_portafolio', 'hide_empty' => false]) as $term) {
        if (get_field('ocultar_categoria', $term)) {
            $hidden_term_ids[] = $term->term_id;
        }
    }
    $tax_query = [];
    if ($hidden_term_ids) {
        $tax_query[] = [
            'taxonomy' => 'tipo_portafolio',
            'field'    => 'term_id',
            'terms'    => $hidden_term_ids,
            'operator' => 'NOT IN',
        ];
    }

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
        $video_url      = '';
        $video_provider = ''; // 'vimeo' or 'youtube' - drives which autoplay/mute params are valid below
        $is_vertical    = false;
        if ($raw_url) {
            // YouTube Shorts: youtube.com/shorts/VIDEO_ID
            if (preg_match('#youtube\.com/shorts/([a-zA-Z0-9_-]+)#', $raw_url, $m)) {
                $video_url      = 'https://www.youtube.com/embed/' . $m[1];
                $video_provider = 'youtube';
                $is_vertical    = true;
            // youtu.be/VIDEO_ID (short link, may also be a Short)
            } elseif (strpos($raw_url, 'youtu.be/') !== false) {
                $path      = ltrim(parse_url($raw_url, PHP_URL_PATH), '/');
                $video_id  = preg_replace('#^shorts/#', '', $path);
                $video_url      = 'https://www.youtube.com/embed/' . $video_id;
                $video_provider = 'youtube';
            // Standard youtube.com/watch?v=VIDEO_ID
            } elseif (strpos($raw_url, 'youtube.com/watch') !== false) {
                parse_str((string) parse_url($raw_url, PHP_URL_QUERY), $params);
                if (!empty($params['v'])) {
                    $video_url      = 'https://www.youtube.com/embed/' . $params['v'];
                    $video_provider = 'youtube';
                }
            // Vimeo Showcase (album): vimeo.com/showcase/ID
            } elseif (preg_match('#vimeo\.com/showcase/(\d+)#', $raw_url, $m)) {
                $video_url      = 'https://vimeo.com/showcase/' . $m[1] . '/embed';
                $video_provider = 'vimeo';
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
                $video_url      = 'https://player.vimeo.com/video/' . $m[1];
                $video_provider = 'vimeo';
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

        // Tag the video's real aspect ratio so JS can size the lightbox to fit
        // it exactly, rather than snapping to a fixed landscape/short bucket.
        // Falls back to a plausible default (16:9, or 9:16 for detected
        // verticals) whenever the real ratio can't be detected, so a failed
        // oEmbed lookup never leaves the video cropped/unstyled.
        //
        // Encoded directly into the video URL (query params, ignored by the
        // player) rather than a data-* attribute on the trigger element:
        // the lightbox is given this href verbatim (see glightbox-init.js),
        // so JS can read the ratio straight off the actual slide being shown.
        // A data-attribute lookup would need to re-match the trigger element
        // by slide index, which breaks as soon as Isotope filtering reorders
        // the grid out from under the original element order.
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

            // Autoplay-on-open used to be handled by GLightbox's bundled
            // Plyr player calling .play() once ready; now that video slides
            // render as a plain iframe (data-type="external" - see
            // glightbox-init.js), it has to be requested directly from the
            // provider instead. Each provider expects its own param name for
            // "start muted", and muting is required for autoplay to be
            // honored at all.
            if ($video_provider === 'vimeo') {
                $href .= '&autoplay=1&muted=1';
            } elseif ($video_provider === 'youtube') {
                // enablejsapi=1 is also what lets our postMessage pause
                // command (see pauseVideoIframe in glightbox-init.js) reach
                // the player when navigating away from this slide.
                $href .= '&autoplay=1&mute=1&enablejsapi=1';
            }
        }

        ob_start(); ?>
        <div class="item-portafolio <?php echo esc_attr($term_classes); ?>" style="position:relative;">
            <a href="<?php echo esc_url($href); ?>"
               class="glightbox"
               data-gallery="galeria"
               <?php echo ($data_type === 'video' ? 'data-type="external"' : ''); ?>>
                <img src="<?php echo esc_url($img_main); ?>"
                     alt="<?php echo esc_attr(get_the_title($post_id)); ?>"
                     loading="lazy">
                <?php if ($data_type === 'video') : ?>
                <span class="portfolio-play-btn" aria-hidden="true" style="position:absolute;top:0;left:0;right:0;bottom:0;width:60px;height:60px;margin:auto;display:block;pointer-events:none;z-index:2;">
                    <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <radialGradient id="ppgh-play-face" cx="35%" cy="30%" r="75%">
                                <stop offset="0%" stop-color="#ff6a5c"/>
                                <stop offset="55%" stop-color="#e3121f"/>
                                <stop offset="100%" stop-color="#9c0c13"/>
                            </radialGradient>
                            <linearGradient id="ppgh-play-rim" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff" stop-opacity="0.95"/>
                                <stop offset="45%" stop-color="#ffffff" stop-opacity="0.2"/>
                                <stop offset="100%" stop-color="#000000" stop-opacity="0.4"/>
                            </linearGradient>
                            <linearGradient id="ppgh-play-triangle" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff"/>
                                <stop offset="100%" stop-color="#e2e2e2"/>
                            </linearGradient>
                        </defs>
                        <circle cx="30" cy="30" r="27" fill="url(#ppgh-play-face)"/>
                        <circle cx="30" cy="30" r="27" fill="none" stroke="url(#ppgh-play-rim)" stroke-width="2.5"/>
                        <ellipse cx="23" cy="16" rx="15" ry="7" fill="#ffffff" opacity="0.28"/>
                        <polygon points="19,17 41,30 19,43" fill="url(#ppgh-play-triangle)"/>
                        <polygon points="19,17 41,30 19,43" fill="none" stroke="#7a0a10" stroke-opacity="0.3" stroke-width="0.75"/>
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
