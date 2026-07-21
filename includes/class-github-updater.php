<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks GitHub Releases for a newer version of this plugin and wires
 * the result into the core WP update UI (Plugins list, "update now" link,
 * View details popup).
 */
class Portfolio_Plugin_GitHub_Updater {

    private $file;
    private $plugin_slug;
    private $basename;
    private $version;
    private $github_user;
    private $github_repo;
    private $cache_key;
    private $cache_hours = 6;

    public function __construct($file, $github_user, $github_repo) {
        $this->file        = $file;
        $this->basename     = plugin_basename($file);
        $this->plugin_slug  = dirname($this->basename);
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->cache_key    = 'ppgh_' . md5($this->basename);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        add_filter('plugin_row_meta', [$this, 'row_meta'], 10, 2);
    }

    private function get_plugin_data() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return get_plugin_data($this->file, false, false);
    }

    private function current_version() {
        if (!$this->version) {
            $data = $this->get_plugin_data();
            $this->version = $data['Version'];
        }
        return $this->version;
    }

    /**
     * Fetches the latest GitHub release, cached in a transient so we don't
     * hit the unauthenticated GitHub API rate limit on every admin request.
     */
    private function get_latest_release() {
        $cached = get_transient($this->cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $response = wp_remote_get($url, [
            'headers' => ['Accept' => 'application/vnd.github+json'],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_transient($this->cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) {
            set_transient($this->cache_key, [], HOUR_IN_SECONDS);
            return [];
        }

        $release = [
            'version'     => ltrim($body['tag_name'], 'v'),
            'download_url' => !empty($body['zipball_url']) ? $body['zipball_url'] : '',
            'changelog'   => !empty($body['body']) ? $body['body'] : '',
            'published'   => !empty($body['published_at']) ? $body['published_at'] : '',
            'html_url'    => !empty($body['html_url']) ? $body['html_url'] : '',
        ];

        // Prefer an uploaded .zip asset over the auto-generated source zipball,
        // since the zipball's top-level folder name is hash-based.
        if (!empty($body['assets']) && is_array($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (isset($asset['browser_download_url']) && preg_match('/\.zip$/', $asset['browser_download_url'])) {
                    $release['download_url'] = $asset['browser_download_url'];
                    break;
                }
            }
        }

        set_transient($this->cache_key, $release, $this->cache_hours * HOUR_IN_SECONDS);

        return $release;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (empty($release['version']) || empty($release['download_url'])) {
            return $transient;
        }

        if (version_compare($release['version'], $this->current_version(), '>')) {
            $transient->response[$this->basename] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->basename,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['download_url'],
                'tested'      => get_bloginfo('version'),
            ];
        } else {
            unset($transient->response[$this->basename]);
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (empty($release['version'])) {
            return $result;
        }

        $data = $this->get_plugin_data();

        return (object) [
            'name'          => $data['Name'],
            'slug'          => $this->plugin_slug,
            'version'       => $release['version'],
            'author'        => $data['Author'],
            'homepage'      => $release['html_url'],
            'sections'      => [
                'changelog' => $release['changelog'] ? nl2br(esc_html($release['changelog'])) : 'No changelog provided.',
            ],
            'download_link' => $release['download_url'],
            'last_updated'  => $release['published'],
        ];
    }

    /**
     * GitHub zip downloads extract into a `{repo}-{hash}` folder. Rename it
     * back to this plugin's existing folder slug so WP overwrites in place
     * instead of installing a sibling copy.
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $args = []) {
        global $wp_filesystem;

        if (empty($args['plugin']) || $args['plugin'] !== $this->basename) {
            return $source;
        }

        $corrected = trailingslashit($remote_source) . $this->plugin_slug . '/';

        if (untrailingslashit($source) === untrailingslashit($corrected)) {
            return $source;
        }

        if ($wp_filesystem->move($source, $corrected, true)) {
            return $corrected;
        }

        return $source;
    }

    public function after_install($response, $hook_extra, $result) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $result;
        }

        $was_active = is_plugin_active($this->basename);
        if ($was_active) {
            activate_plugin($this->basename);
        }

        return $result;
    }

    public function row_meta($links, $file) {
        if ($file === $this->basename) {
            $release = $this->get_latest_release();
            if (!empty($release['html_url'])) {
                $links[] = '<a href="' . esc_url($release['html_url']) . '" target="_blank">' . esc_html__('View changelog') . '</a>';
            }
        }
        return $links;
    }
}
