<?php

/**
 * Prepares the plugin data.
 *
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core/UI
 */

if ( ! defined( 'WPINC' ) ) {
    exit;
}

/**
 * Class Aarambha_DS_Plugins
 *
 * Plugin installer / activator used by the demo importer.
 */
class Aarambha_DS_Plugins {

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static $instance = null;

    /**
     * Merged free + pro plugin list for the current demo.
     *
     * Each entry MUST contain at minimum:
     *   'name'     => string  Human-readable label.
     *   'plugin'   => string  WordPress.org / download slug  (e.g. 'woocommerce').
     *   'coreFile' => string  Plugin main file relative to WP_PLUGIN_DIR
     *                         (e.g. 'woocommerce/woocommerce.php').
     *
     * @var array
     */
    private $plugins = [];

    public static function getInstance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->actions();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function __clone() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'aarambha-demo-sites' ), '1.0.0' );
    }

    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'aarambha-demo-sites' ), '1.0.0' );
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    public function actions(): void {
        add_filter( 'plugins_api', [ $this, 'pluginsApi' ], 10, 3 );
    }

    // -------------------------------------------------------------------------
    // Runtime — build plugin list for the chosen demo
    // -------------------------------------------------------------------------

    /**
     * Build the plugin list for $demo, auto-injecting WooCommerce when
     * the demo declares wc_support = true and WooCommerce is not already listed.
     *
     * @param array $demo Full demo data array from the API / transient.
     * @return $this
     */
    public function runtime( array $demo ): self {

        $this->plugins = isset( $demo['plugins'] ) ? (array) $demo['plugins'] : [];
        $demo_name     = $demo['slug'] ?? '';

        // ── Auto-inject WooCommerce ──────────────────────────────────────────
        if ( ! empty( $demo['wc_support'] ) ) {
            $woo_core = 'woocommerce/woocommerce.php';
            $listed   = false;

            foreach ( [ 'free', 'pro' ] as $type ) {
                foreach ( $this->plugins[ $type ] ?? [] as $p ) {
                    if ( ( $p['coreFile'] ?? '' ) === $woo_core ) {
                        $listed = true;
                        break 2;
                    }
                }
            }

            if ( ! $listed ) {
                $this->plugins['free'][] = [
                    'name'     => 'WooCommerce',
                    'plugin'   => 'woocommerce',   // wp.org slug used by ajaxInstall()
                    'coreFile' => $woo_core,
                ];
            }
        }
        // ────────────────────────────────────────────────────────────────────

        // ── Pro-plugin transient (used by pluginsApi() / download links) ────
        $transient          = get_site_transient( 'aarambha_ds_plugins' ) ?: new stdClass();
        $transient->plugins = $transient->plugins ?? [];

        if ( ! empty( $this->plugins['pro'] ) ) {
            $pluginsArr = [];

            foreach ( $this->plugins['pro'] as $plugin ) {
                $pluginCoreFile = $plugin['coreFile'] ?? '';
                $slug           = $plugin['plugin']   ?? '';
                $file           = $plugin['plugin_file'] ?? '';

                // Skip if already present in the raw plugins array (sanity guard).
                if ( in_array( $pluginCoreFile, (array) $this->plugins, true ) ) {
                    continue;
                }

                $pluginObj               = new stdClass();
                $pluginObj->name         = $plugin['name']    ?? '';
                $pluginObj->version      = $plugin['version'] ?? '';
                $pluginObj->slug         = $slug;
                $pluginObj->plugin       = $pluginCoreFile;
                $pluginObj->fileName     = $file;
                $pluginObj->demo         = $demo_name;
                $pluginObj->url          = $demo['preview'] ?? '';
                $pluginObj->download_link = Aarambha_DS()->api()->deferredDownload(
                    compact( 'slug', 'demo_name' )
                );

                $pluginsArr[] = $pluginObj;
            }

            $transient->plugins = $pluginsArr;
        }

        set_site_transient( 'aarambha_ds_plugins', $transient, WEEK_IN_SECONDS );

        return $this;
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /**
     * Build the HTML plugin list and decide whether to go straight to import.
     *
     * @return array{ step: string, html: string }
     */
    public function html(): array {
        $free    = $this->plugins['free'] ?? [];
        $pro     = $this->plugins['pro']  ?? [];
        $plugins = array_merge( $free, $pro );
        $total   = count( $plugins );

        $activeLists = [];
        foreach ( $plugins as $plugin ) {
            if ( $this->isActive( $plugin['coreFile'] ?? '' ) ) {
                $activeLists[] = $plugin['coreFile'];
            }
        }

        $allActive = ( count( $activeLists ) === $total );

        $status         = [];
        $status['step'] = $allActive ? 'import' : 'install-plugin';

        ob_start();
        if ( ! $allActive ) {
            Aarambha_DS()->view( 'plugins-list', $this->plugins );
        }
        $status['html'] = ob_get_clean();

        return $status;
    }

    // -------------------------------------------------------------------------
    // Install / activate
    // -------------------------------------------------------------------------

    /** Load is_plugin_active() if needed. */
    public function inject(): void {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /** Load WP_Upgrader + plugins_api() if needed. */
    public function injectInstaller(): void {
        if ( ! class_exists( 'WP_Upgrader', false ) ) {
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if ( ! function_exists( 'plugins_api' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
    }

    /**
     * Check whether a plugin's main file exists on disk.
     *
     * @param string $pluginFile e.g. 'woocommerce/woocommerce.php'
     */
    public function isInstalled( string $pluginFile = '' ): bool {
        if ( '' === $pluginFile ) {
            return false;
        }
        return file_exists( WP_PLUGIN_DIR . "/{$pluginFile}" );
    }

    /**
     * Check whether a plugin is currently active.
     *
     * @param string $pluginFile e.g. 'woocommerce/woocommerce.php'
     */
    public function isActive( string $pluginFile ): bool {
        if ( empty( $pluginFile ) ) {
            return false;
        }
        $this->inject();
        return is_plugin_active( $pluginFile );
    }

    /**
     * Activate a plugin by its main file path.
     *
     * @param string $plugin e.g. 'woocommerce/woocommerce.php'
     * @return bool
     */
    public function activate( string $plugin ): bool {
        $this->inject();

        if ( ! $this->isInstalled( $plugin ) ) {
            return false;
        }

        $result = activate_plugin( $plugin );

        // activate_plugin() returns null on success, WP_Error on failure.
        return ( null === $result || ! is_wp_error( $result ) );
    }

    /**
     * Install a free plugin from WordPress.org via AJAX.
     *
     * Mirrors wp_ajax_install_plugin() but returns an array instead of
     * echoing JSON so the AJAX handler stays in control of the response.
     *
     * @param string $slug WordPress.org plugin slug (e.g. 'woocommerce').
     * @return array{ success: bool, pluginName?: string, errorCode?: string, errorMessage?: string }
     */
    public function ajaxInstall( string $slug ): array {

        $status = [];

        if ( empty( $slug ) ) {
            $status['success']      = false;
            $status['errorMessage'] = __( 'No plugin slug supplied.', 'aarambha-demo-sites' );
            return $status;
        }

        $this->injectInstaller();

        // Query WordPress.org plugin repo for download info.
        $api = plugins_api(
            'plugin_information',
            [
                'slug'   => sanitize_key( wp_unslash( $slug ) ),
                'fields' => [ 'sections' => false ],
            ]
        );

        if ( is_wp_error( $api ) ) {
            $status['success']      = false;
            $status['errorMessage'] = $api->get_error_message();
            return $status;
        }

        $status['pluginName'] = $api->name;

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );

        // ── Error classification ─────────────────────────────────────────────
        if ( is_wp_error( $result ) ) {
            $status['success']      = false;
            $status['errorCode']    = $result->get_error_code();
            $status['errorMessage'] = $result->get_error_message();
            return $status;
        }

        if ( is_wp_error( $skin->result ) ) {
            $status['success']      = false;
            $status['errorCode']    = $skin->result->get_error_code();
            $status['errorMessage'] = $skin->result->get_error_message();
            return $status;
        }

        if ( $skin->get_errors()->has_errors() ) {
            $status['success']      = false;
            $status['errorMessage'] = $skin->get_error_messages();
            return $status;
        }

        if ( is_null( $result ) ) {
            global $wp_filesystem;
            $status['success']   = false;
            $status['errorCode'] = 'unable_to_connect_to_filesystem';
            $status['errorMessage'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'aarambha-demo-sites' );

            if (
                $wp_filesystem instanceof WP_Filesystem_Base &&
                is_wp_error( $wp_filesystem->errors ) &&
                $wp_filesystem->errors->has_errors()
            ) {
                $status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
            }
            return $status;
        }
        // ────────────────────────────────────────────────────────────────────

        $status['success'] = true;
        return $status;
    }

    // -------------------------------------------------------------------------
    // WooCommerce post-import setup
    // -------------------------------------------------------------------------

    /**
     * After demo content is imported: deduplicate WC pages, wire up WC options,
     * and clear the WC activation-redirect transient.
     *
     * Inspired by ThemeGrill's ImportHooks::set_wc_pages().
     *
     * @param string $demo_slug The imported demo slug (used for the filter name).
     */
    public function setupWooCommercePages( string $demo_slug = '' ): void {

        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        global $wpdb;

        /**
         * Filterable WC page definitions.
         * Each key is the WC option suffix (woocommerce_{key}_page_id).
         * 'name'  = post_name (slug), 'title' = post_title.
         */
        $wc_pages = apply_filters(
            "aarambha_ds_wc_{$demo_slug}_pages",
            [
                'shop'      => [ 'name' => 'shop',       'title' => 'Shop'       ],
                'cart'      => [ 'name' => 'cart',       'title' => 'Cart'       ],
                'checkout'  => [ 'name' => 'checkout',   'title' => 'Checkout'   ],
                'myaccount' => [ 'name' => 'my-account', 'title' => 'My Account' ],
            ]
        );

        foreach ( $wc_pages as $option_key => $page ) {

            // Find every published page matching the slug OR the title.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $page_ids = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE ( post_name = %s OR post_title = %s )
                       AND post_type   = 'page'
                       AND post_status = 'publish'",
                    $page['name'],
                    $page['title']
                )
            );

            if ( empty( $page_ids ) ) {
                continue;
            }

            $keep_id    = 0;
            $delete_ids = [];

            // Keep the page with the highest ID (most recently imported).
            if ( count( $page_ids ) > 1 ) {
                foreach ( $page_ids as $row ) {
                    if ( (int) $row->ID > $keep_id ) {
                        if ( $keep_id ) {
                            $delete_ids[] = $keep_id;
                        }
                        $keep_id = (int) $row->ID;
                    } else {
                        $delete_ids[] = (int) $row->ID;
                    }
                }
            } else {
                $keep_id = (int) $page_ids[0]->ID;
            }

            // Permanently delete duplicate pages.
            foreach ( $delete_ids as $del_id ) {
                wp_delete_post( $del_id, true );
            }

            // Normalise the slug and save the WC option.
            if ( $keep_id > 0 ) {
                wp_update_post( [
                    'ID'        => $keep_id,
                    'post_name' => sanitize_title( $page['name'] ),
                ] );
                update_option( "woocommerce_{$option_key}_page_id", $keep_id );
            }
        }

        // Prevent WC from redirecting to its setup wizard after the import.
        delete_transient( '_wc_activation_redirect' );
    }

    // -------------------------------------------------------------------------
    // pluginsApi filter — rewire pro-plugin download links
    // -------------------------------------------------------------------------

    /**
     * Intercept plugins_api() calls for pro plugins so WP_Upgrader gets
     * our download link instead of looking them up on wp.org.
     *
     * @param false|object|WP_Error $response
     * @param string                $action
     * @param object                $args
     * @return false|object|WP_Error
     */
    public function pluginsApi( $response, string $action, object $args ) {

        if ( 'plugin_information' !== $action || ! isset( $args->slug ) ) {
            return $response;
        }

        $proPlugins = get_site_transient( 'aarambha_ds_plugins' );

        if ( empty( $proPlugins->plugins ) || ! is_array( $proPlugins->plugins ) ) {
            return $response;
        }

        $theme = aarambha_ds_get_theme();

        foreach ( $proPlugins->plugins as $plugin ) {
            if ( $plugin->slug !== $args->slug ) {
                continue;
            }

            $slug = $plugin->slug;
            $demo = $plugin->demo;

            $response                = new stdClass();
            $response->id            = str_replace( '-', '_', $slug );
            $response->slug          = $slug;
            $response->plugin_name   = $plugin->name;
            $response->name          = $plugin->name;
            $response->new_version   = $plugin->version;
            $response->download_link = Aarambha_DS()->api()->download(
                compact( 'theme', 'slug', 'demo' )
            );
            break;
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Stubs kept for backwards compatibility
    // -------------------------------------------------------------------------

    public function prepare( $plugin ): void {}
    public function install( $plugin ): void {}
}