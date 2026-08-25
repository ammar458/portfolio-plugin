<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode: [portfolio_dashboard]
 *
 * A bento-style overview of the portfolio: one featured video, a row of
 * smaller video thumbnails, a "web design" project list, and a "marketing /
 * social" campaign grid. Every item card reuses the same `.item-portafolio` /
 * `video-card-overlay` markup as [portfolio_galeria], so the two shortcodes
 * look identical at the card level - only the surrounding layout differs.
 *
 * Clicking a tab swaps the "ALL" bento view for a plain filtered grid of
 * just that category's items (mirroring how [portfolio_filtros] filters
 * [portfolio_galeria]) - see assets/dashboard-tabs.js.
 *
 * Attributes:
 *   web_term        Taxonomy slug (tipo_portafolio) shown in the "Web Design
 *                    Projects" column. Defaults to the first visible term.
 *   marketing_term   Taxonomy slug shown in the "Marketing & Social
 *                    Campaigns" column. Defaults to the second visible term.
 *   featured_id      Force a specific portfolio post ID as the featured hero
 *                    item instead of auto-picking the most recent video.
 *   web_count        Max items in the web design column. Default 6.
 *   marketing_count  Max items in the marketing/social column. Default 6.
 *   video_count      Max thumbnails in the "More Videos" strip. Default 4.
 */

/**
 * Finds the visible term whose name/slug matches a keyword (e.g. "web" for
 * the "Web Design Projects" column) so that column's items and the
 * same-named category tab point at the same real taxonomy term. Falls back
 * to $fallback_index so the column still has *something* to show if no
 * term happens to match the keyword.
 */
function portfolio_dashboard_guess_term($terms, $keyword, $fallback_index, $exclude_slug = '') {
    foreach ($terms as $term) {
        if ($term->slug === $exclude_slug) {
            continue;
        }
        if (stripos($term->name, $keyword) !== false || stripos($term->slug, $keyword) !== false) {
            return $term->slug;
        }
    }
    return isset($terms[$fallback_index]) ? $terms[$fallback_index]->slug : '';
}

function shortcode_portfolio_dashboard($atts) {

    $atts = shortcode_atts([
        'web_term'        => '',
        'marketing_term'  => '',
        'featured_id'     => 0,
        'web_count'       => 6,
        'marketing_count' => 6,
        'video_count'     => 4,
    ], $atts, 'portfolio_dashboard');

    $terms = portfolio_visible_terms();

    // Match the "Web Design Projects" / "Marketing & Social Campaigns"
    // columns to the real taxonomy term of the same name, rather than just
    // grabbing the 1st/2nd visible term - otherwise a column can show items
    // from a different category than the same-named tab, so clicking that
    // tab shows a confusingly smaller/different set than the column implied.
    $web_term_slug       = $atts['web_term'] ?: portfolio_dashboard_guess_term($terms, 'web', 0);
    $marketing_term_slug = $atts['marketing_term'] ?: portfolio_dashboard_guess_term($terms, 'marketing', 1, $web_term_slug);

    // ---------- GATHER + CLASSIFY ITEMS ----------
    $posts_query = new WP_Query([
        'post_type'      => 'portfolio',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => portfolio_visible_tax_query(),
    ]);

    $media_by_id   = [];
    $video_ids     = [];
    $web_ids       = [];
    $marketing_ids = [];
    $ids_by_term   = [];

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
            $slugs             = $post_terms ? wp_list_pluck($post_terms, 'slug') : [];

            foreach ($slugs as $slug) {
                $ids_by_term[$slug][] = $id;
            }

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

    // Only tab through categories that actually have a renderable item -
    // an empty tab would filter to a blank grid.
    $terms = array_values(array_filter($terms, function ($term) use ($ids_by_term) {
        return !empty($ids_by_term[$term->slug]);
    }));

    $rendered_slugs = wp_list_pluck($terms, 'slug');

    // "View All" links next to each section heading only make sense if
    // there's an actual tab for them to jump to.
    $video_term_slug = portfolio_dashboard_guess_term($terms, 'video', -1, $web_term_slug);
    $web_view_all       = in_array($web_term_slug, $rendered_slugs, true) ? $web_term_slug : '';
    $marketing_view_all = in_array($marketing_term_slug, $rendered_slugs, true) ? $marketing_term_slug : '';
    $video_view_all     = in_array($video_term_slug, $rendered_slugs, true) ? $video_term_slug : '';

    // ---------- PICK THE FEATURED ITEM ----------
    $featured_id = (int) $atts['featured_id'];
    if (!$featured_id || !isset($media_by_id[$featured_id])) {
        $featured_id = $video_ids[0] ?? array_key_first($media_by_id);
    }

    $more_video_ids = array_slice(array_values(array_diff($video_ids, [$featured_id])), 0, (int) $atts['video_count']);
    $web_ids        = array_slice(array_values(array_diff($web_ids, [$featured_id])), 0, (int) $atts['web_count']);
    $marketing_ids  = array_slice(array_values(array_diff($marketing_ids, [$featured_id])), 0, (int) $atts['marketing_count']);

    $featured_is_video = $media_by_id[$featured_id]['data_type'] === 'video';

    // ---------- RENDER ----------
    ob_start();
    ?>
    <div class="portfolio-dashboard">

        <div class="pd-tabs">
            <a href="#all" class="pd-tab is-active" data-pd-filter="*">ALL</a>
            <?php foreach ($terms as $term) : ?>
                <a href="#<?php echo esc_attr($term->slug); ?>" class="pd-tab" data-pd-filter="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html($term->name); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="pd-view pd-view-all" data-pd-view="*">
            <div class="pd-grid">

                <div class="pd-col pd-col-featured">

                    <div class="pd-featured-wrap pd-card">
                        <span class="pd-featured-badge">Featured <?php echo $featured_is_video ? 'Video' : 'Project'; ?></span>
                        <div class="grid-portafolio pd-solo-grid">
                            <?php echo portfolio_render_item_card($featured_id, 'dashboard'); ?>
                        </div>
                    </div>

                    <?php if ($more_video_ids) : ?>
                        <div class="pd-more-videos pd-card">
                            <div class="pd-section-head">
                                <h4>More Videos</h4>
                                <?php if ($video_view_all) : ?>
                                    <a href="#<?php echo esc_attr($video_view_all); ?>" class="pd-see-all">View All</a>
                                <?php endif; ?>
                            </div>
                            <div class="grid-portafolio pd-strip-grid">
                                <?php foreach ($more_video_ids as $id) : ?>
                                    <?php echo portfolio_render_item_card($id, 'dashboard'); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pd-col pd-col-web pd-card">
                    <div class="pd-section-head">
                        <h4>Web Design Projects</h4>
                        <?php if ($web_view_all) : ?>
                            <a href="#<?php echo esc_attr($web_view_all); ?>" class="pd-see-all">View All</a>
                        <?php endif; ?>
                    </div>
                    <div class="grid-portafolio pd-mini-grid">
                        <?php foreach ($web_ids as $id) : ?>
                            <?php echo portfolio_render_item_card($id, 'dashboard'); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pd-col pd-col-marketing pd-card">
                    <div class="pd-section-head">
                        <h4>Marketing &amp; Social Campaigns</h4>
                        <?php if ($marketing_view_all) : ?>
                            <a href="#<?php echo esc_attr($marketing_view_all); ?>" class="pd-see-all">View All</a>
                        <?php endif; ?>
                    </div>
                    <div class="grid-portafolio pd-mini-grid">
                        <?php foreach ($marketing_ids as $id) : ?>
                            <?php echo portfolio_render_item_card($id, 'dashboard'); ?>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php foreach ($terms as $term) : ?>
            <div class="pd-view" data-pd-view="<?php echo esc_attr($term->slug); ?>" hidden>
                <div class="grid-portafolio">
                    <?php foreach ($ids_by_term[$term->slug] as $id) : ?>
                        <?php echo portfolio_render_item_card($id, 'dashboard'); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('portfolio_dashboard', 'shortcode_portfolio_dashboard');
