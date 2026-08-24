<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode: [portfolio_dashboard]
 *
 * A bento-style overview of the portfolio: one featured video, a row of
 * smaller video thumbnails, a "web design" project list, and a "marketing /
 * social" campaign grid. Sections are populated entirely from existing data
 * (post thumbnail, tipo_de_contenido, tipo_portafolio terms, and the
 * video_card_* ACF fields already used by [portfolio_galeria]) - no new ACF
 * fields required.
 *
 * Attributes:
 *   web_term        Taxonomy slug (tipo_portafolio) shown in the "Web Design
 *                    Projects" column. Defaults to the first visible term.
 *   marketing_term   Taxonomy slug shown in the "Marketing & Social
 *                    Campaigns" column. Defaults to the second visible term.
 *   featured_id      Force a specific portfolio post ID as the featured hero
 *                    item instead of auto-picking the most recent video.
 *   web_count        Max items in the web design column. Default 3.
 *   marketing_count  Max items in the marketing/social column. Default 6.
 *   video_count      Max thumbnails in the "More Videos" strip. Default 5.
 */
function shortcode_portfolio_dashboard($atts) {

    $atts = shortcode_atts([
        'web_term'        => '',
        'marketing_term'  => '',
        'featured_id'     => 0,
        'web_count'       => 3,
        'marketing_count' => 6,
        'video_count'     => 5,
    ], $atts, 'portfolio_dashboard');

    $terms = portfolio_visible_terms();

    $web_term_slug       = $atts['web_term'] ?: (isset($terms[0]) ? $terms[0]->slug : '');
    $marketing_term_slug = $atts['marketing_term'] ?: (isset($terms[1]) ? $terms[1]->slug : $web_term_slug);

    // ---------- GATHER + CLASSIFY ITEMS ----------
    $posts_query = new WP_Query([
        'post_type'      => 'portfolio',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => portfolio_visible_tax_query(),
    ]);

    $media_by_id = [];
    $terms_by_id = [];
    $video_ids   = [];
    $web_ids     = [];
    $marketing_ids = [];

    if ($posts_query->have_posts()) {
        while ($posts_query->have_posts()) {
            $posts_query->the_post();
            $id    = get_the_ID();
            $media = portfolio_prepare_media($id);
            if (!$media) {
                continue;
            }

            $media_by_id[$id] = $media;
            $post_terms        = get_the_terms($id, 'tipo_portafolio');
            $slugs              = $post_terms ? wp_list_pluck($post_terms, 'slug') : [];
            $terms_by_id[$id]   = $post_terms ? wp_list_pluck($post_terms, 'name') : [];

            if ($media['data_type'] === 'video') {
                $video_ids[] = $id;
            }
            if ($web_term_slug && in_array($web_term_slug, $slugs, true)) {
                $web_ids[] = $id;
            }
            if ($marketing_term_slug && in_array($marketing_term_slug, $slugs, true)) {
                $marketing_ids[] = $id;
            }
        }
        wp_reset_postdata();
    }

    if (!$media_by_id) {
        return '';
    }

    // ---------- PICK THE FEATURED ITEM ----------
    $featured_id = (int) $atts['featured_id'];
    if (!$featured_id || !isset($media_by_id[$featured_id])) {
        $featured_id = $video_ids[0] ?? array_key_first($media_by_id);
    }
    $featured_media = $media_by_id[$featured_id];

    $more_video_ids = array_slice(array_values(array_diff($video_ids, [$featured_id])), 0, (int) $atts['video_count']);
    $web_ids        = array_slice(array_values(array_diff($web_ids, [$featured_id])), 0, (int) $atts['web_count']);
    $marketing_ids  = array_slice(array_values(array_diff($marketing_ids, [$featured_id])), 0, (int) $atts['marketing_count']);

    $featured_is_video = $featured_media['data_type'] === 'video';
    $featured_title    = $featured_media['video_card_title'] ?: get_the_title($featured_id);
    $featured_sub      = $featured_media['video_card_subtitle'];

    // ---------- RENDER ----------
    ob_start();
    ?>
    <div class="portfolio-dashboard">

        <div class="pd-tabs">
            <span class="pd-tab is-active">ALL</span>
            <?php foreach ($terms as $term) : ?>
                <span class="pd-tab"><?php echo esc_html($term->name); ?></span>
            <?php endforeach; ?>
        </div>

        <div class="pd-grid">

            <div class="pd-col pd-col-featured">

                <a href="<?php echo esc_url($featured_media['href']); ?>"
                   class="pd-featured glightbox"
                   data-gallery="dashboard"
                   aria-label="<?php echo esc_attr($featured_is_video ? 'Play ' . $featured_title . ' video' : 'Open ' . $featured_title); ?>"
                   <?php echo ($featured_is_video ? 'data-type="video"' : ''); ?>>

                    <span class="pd-featured-badge">+ Featured <?php echo $featured_is_video ? 'Video' : 'Project'; ?></span>

                    <img src="<?php echo esc_url($featured_media['img_main']); ?>"
                         alt="<?php echo esc_attr($featured_title); ?>"
                         loading="lazy">

                    <div class="pd-featured-overlay">
                        <?php if ($featured_is_video) : ?>
                            <span class="pd-featured-play" aria-hidden="true">
                                <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                    <circle cx="32" cy="32" r="29" fill="none" stroke="#ffffff" stroke-width="2"/>
                                    <path d="M27 21.5 45 32 27 42.5Z" fill="#ffffff"/>
                                </svg>
                            </span>
                        <?php endif; ?>

                        <h3 class="pd-featured-title"><?php echo esc_html($featured_title); ?></h3>

                        <?php if ($featured_sub !== '') : ?>
                            <p class="pd-featured-sub"><?php echo esc_html($featured_sub); ?></p>
                        <?php endif; ?>

                        <?php if ($featured_is_video) : ?>
                            <span class="pd-click-play">Click to play</span>
                        <?php endif; ?>
                    </div>
                </a>

                <?php if ($more_video_ids) : ?>
                    <div class="pd-more-videos">
                        <div class="pd-section-head">
                            <h4>More Videos</h4>
                        </div>
                        <div class="pd-video-strip">
                            <?php foreach ($more_video_ids as $id) :
                                $m     = $media_by_id[$id];
                                $label = $m['video_card_category'] ?: get_the_title($id);
                                ?>
                                <div class="pd-video-thumb-wrap">
                                    <a href="<?php echo esc_url($m['href']); ?>"
                                       class="pd-video-thumb glightbox"
                                       data-gallery="dashboard"
                                       data-type="video"
                                       aria-label="Play <?php echo esc_attr($m['video_accessible_title']); ?> video">
                                        <img src="<?php echo esc_url($m['img_main']); ?>"
                                             alt="<?php echo esc_attr(get_the_title($id)); ?>"
                                             loading="lazy">
                                        <span class="pd-play-mini" aria-hidden="true">
                                            <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                                <circle cx="32" cy="32" r="29" fill="none" stroke="#ffffff" stroke-width="2"/>
                                                <path d="M27 21.5 45 32 27 42.5Z" fill="#ffffff"/>
                                            </svg>
                                        </span>
                                    </a>
                                    <span class="pd-video-thumb-label"><?php echo esc_html($label); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pd-col pd-col-web">
                <div class="pd-section-head">
                    <h4>Web Design Projects</h4>
                </div>
                <div class="pd-project-list">
                    <?php foreach ($web_ids as $id) : ?>
                        <div class="pd-project-card">
                            <div class="pd-laptop">
                                <div class="pd-laptop-screen">
                                    <img src="<?php echo esc_url($media_by_id[$id]['img_main']); ?>"
                                         alt="<?php echo esc_attr(get_the_title($id)); ?>"
                                         loading="lazy">
                                </div>
                                <div class="pd-laptop-base"></div>
                            </div>
                            <div class="pd-project-body">
                                <h5><?php echo esc_html(get_the_title($id)); ?></h5>
                                <?php if (!empty($terms_by_id[$id])) : ?>
                                    <p><?php echo esc_html(implode(', ', $terms_by_id[$id])); ?></p>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(get_permalink($id)); ?>" class="pd-view-btn">View Project</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pd-col pd-col-marketing">
                <div class="pd-section-head">
                    <h4>Marketing &amp; Social Campaigns</h4>
                </div>
                <div class="pd-campaign-grid">
                    <?php foreach ($marketing_ids as $id) :
                        $m = $media_by_id[$id];
                        ?>
                        <?php if ($m['data_type'] === 'video' && $m['has_video_card_stats']) : ?>
                            <div class="pd-campaign-card pd-stat-card">
                                <?php if ($m['video_card_category'] !== '') : ?>
                                    <span class="pd-campaign-cat"><?php echo esc_html($m['video_card_category']); ?></span>
                                <?php endif; ?>
                                <div class="pd-campaign-stats">
                                    <?php if ($m['video_card_views'] !== '') : ?>
                                        <span class="pd-campaign-stat">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                                <path d="M2.2 12s3.6-6 9.8-6 9.8 6 9.8 6-3.6 6-9.8 6-9.8-6-9.8-6Zm9.8 3.6a3.6 3.6 0 1 0 0-7.2 3.6 3.6 0 0 0 0 7.2Z" fill="currentColor"/>
                                            </svg>
                                            <?php echo esc_html($m['video_card_views']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($m['video_card_lead_increase'] !== '') : ?>
                                        <span class="pd-campaign-stat">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                                <path d="M4 19V11h3v8H4Zm6 0V5h3v14h-3Zm6 0v-6h3v6h-3Z" fill="currentColor"/>
                                            </svg>
                                            <?php echo esc_html($m['video_card_lead_increase']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <a href="<?php echo esc_url($m['href']); ?>"
                                   class="pd-campaign-thumb glightbox"
                                   data-gallery="dashboard"
                                   data-type="video"
                                   aria-label="Play <?php echo esc_attr($m['video_accessible_title']); ?> video">
                                    <img src="<?php echo esc_url($m['img_main']); ?>"
                                         alt="<?php echo esc_attr(get_the_title($id)); ?>"
                                         loading="lazy">
                                </a>
                            </div>
                        <?php else : ?>
                            <a href="<?php echo esc_url($m['href']); ?>"
                               class="pd-campaign-card glightbox"
                               data-gallery="dashboard"
                               <?php echo ($m['data_type'] === 'video' ? 'data-type="video"' : ''); ?>
                               aria-label="<?php echo esc_attr($m['data_type'] === 'video' ? 'Play ' . $m['video_accessible_title'] . ' video' : 'Open ' . get_the_title($id)); ?>">
                                <img src="<?php echo esc_url($m['img_main']); ?>"
                                     alt="<?php echo esc_attr(get_the_title($id)); ?>"
                                     loading="lazy">
                                <?php if ($m['video_card_title'] !== '' || $m['video_card_category'] !== '') : ?>
                                    <div class="pd-campaign-overlay">
                                        <?php if ($m['video_card_category'] !== '') : ?>
                                            <span class="cat"><?php echo esc_html($m['video_card_category']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($m['video_card_title'] !== '') : ?>
                                            <h6><?php echo esc_html($m['video_card_title']); ?></h6>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($m['data_type'] === 'video') : ?>
                                    <span class="pd-campaign-play" aria-hidden="true">
                                        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" focusable="false">
                                            <circle cx="32" cy="32" r="29" fill="none" stroke="#ffffff" stroke-width="2"/>
                                            <path d="M27 21.5 45 32 27 42.5Z" fill="#ffffff"/>
                                        </svg>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('portfolio_dashboard', 'shortcode_portfolio_dashboard');
