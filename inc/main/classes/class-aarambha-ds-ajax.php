<?php

/**
 * Handle the AJAX sent through demo importer.
 *
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core/UI
 */

if ( ! defined( 'WPINC' ) ) {
    exit;
}

/**
 * Class Aarambha_DS_Ajax
 *
 * Handles all AJAX actions for the demo importer.
 */
class Aarambha_DS_Ajax {

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static $instance = null;

    /**
     * Map of AJAX action slugs => method names.
     *
     * @var array<string, string>
     */
    private $actions = [];

    public static function getInstance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->define();
            self::$instance->register();
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
    // Registration
    // -------------------------------------------------------------------------

    private function define(): void {
        $this->actions = [
            'retrieve-demo'        => 'retrieveDemo',
            'list-plugins'         => 'listPlugins',
            'ocdi-install-plugin'  => 'installPlugin',
            'ocdi-activate-plugin' => 'activatePlugin',
            'prepare-import'       => 'prepareImport',
            'content-import'       => 'importContent',
            'customizer-import'    => 'importCustomize',
            'widgets-import'       => 'importWidget',
            'slider-import'        => 'importSlider',
            'menu-import'          => 'importMenu',
            'pages-import'         => 'importPages',
            'finalize-import'      => 'finalize',
        ];
    }

    private function register(): void {
        foreach ( $this->actions as $key => $method ) {
            add_action( "wp_ajax_{$key}", [ $this, $method ] );
        }
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Send a standardised "not allowed" error and exit.
     */
    private function sendNotAllowed(): void {
        wp_send_json_error( [
            'code'    => 'not_allowed',
            'title'   => esc_html__( 'Sorry!', 'aarambha-demo-sites' ),
            'message' => esc_html__( 'This action cannot be performed now. Please try again later!', 'aarambha-demo-sites' ),
        ] );
    }

    /**
     * Build the "advance to next step" response array.
     *
     * @param array       $steps     Full steps array from the request.
     * @param int         $index     Current stepsIndex.
     * @param array       $extra     Any extra keys to merge into the response.
     * @return array
     */
    private function nextStepResponse( array $steps, int $index, array $extra = [] ): array {
        $nextStep = $steps[ $index + 1 ] ?? 'finalize';
        $nonceKey = "import-{$nextStep}";

        return array_merge(
            [
                'nonce'  => wp_create_nonce( $nonceKey ),
                'action' => $nextStep,
                'steps'  => $steps,
            ],
            $extra
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: retrieve-demo
    // -------------------------------------------------------------------------

    /**
     * Queries the API to get full demo details.
     */
    public function retrieveDemo(): void {
        $demo     = sanitize_text_field( $_REQUEST['demo']     ?? '' );
        $demoType = sanitize_text_field( $_REQUEST['demoType'] ?? '' );

        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', "retrieve-demo-{$demo}" ) ) {
            $this->sendNotAllowed();
        }

        $result = Aarambha_DS()->api()->demo( $demo, $demoType );

        if ( $result['success'] ) {
            $resDemo = $result['data'];
            wp_send_json_success( [
                'demo' => [
                    'name'    => $resDemo['name'],
                    'slug'    => $resDemo['slug'],
                    'image'   => $resDemo['image'],
                    'preview' => $resDemo['preview'],
                ],
            ] );
        }

        wp_send_json_error( [
            'title'   => $result['title']   ?? esc_html__( 'Sorry!', 'aarambha-demo-sites' ),
            'message' => $result['message'] ?? esc_html__( 'Something went wrong.', 'aarambha-demo-sites' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: list-plugins
    // -------------------------------------------------------------------------

    /**
     * Returns the list of required plugins for the demo, with install/active state.
     */
    public function listPlugins(): void {
        $slug  = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $theme = aarambha_ds_get_theme();

        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'list-plugins' ) ) {
            $this->sendNotAllowed();
        }

        $demoKey = "aarambha_ds_{$theme}_demo_{$slug}";
        $result  = get_site_transient( $demoKey );

        if ( $result ) {
            $demo = $result['data'];

            // Pass through even when plugins key is absent — runtime() handles
            // wc_support injection so a WC-only demo still shows the WC row.
            $status = Aarambha_DS()->plugins()
                ->runtime( $demo )
                ->html();

            wp_send_json_success( $status );
        }

        $this->sendNotAllowed();
    }

    // -------------------------------------------------------------------------
    // AJAX: ocdi-install-plugin
    // -------------------------------------------------------------------------

    /**
     * Installs a plugin from WordPress.org (or our API for pro plugins).
     */
    public function installPlugin(): void {
        $plugin   = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $nonceKey = "install-{$plugin}";

        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', $nonceKey ) ) {
            $this->sendNotAllowed();
        }

        $result = Aarambha_DS()->plugins()->ajaxInstall( $plugin );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [
                'code'    => 'install_failed',
                'title'   => esc_html__( 'Sorry!', 'aarambha-demo-sites' ),
                'message' => $result['errorMessage'] ?? esc_html__( 'Plugin installation failed.', 'aarambha-demo-sites' ),
            ] );
        }

        wp_send_json_success( [
            'status' => 'activate',
            'nonce'  => wp_create_nonce( "activate-{$plugin}" ),
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: ocdi-activate-plugin
    // -------------------------------------------------------------------------

    /**
     * Activates an already-installed plugin.
     */
    public function activatePlugin(): void {
        $plugin     = sanitize_text_field( $_REQUEST['slug']     ?? '' );
        $pluginFile = sanitize_text_field( $_REQUEST['coreFile'] ?? '' );
        $nonceKey   = "activate-{$plugin}";

        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', $nonceKey ) ) {
            $this->sendNotAllowed();
        }

        $activated = Aarambha_DS()->plugins()->activate( $pluginFile );

        if ( ! $activated ) {
            wp_send_json_error( [
                'code'    => 'activation_failed',
                'title'   => esc_html__( 'Sorry!', 'aarambha-demo-sites' ),
                'message' => esc_html__( 'Plugin activation failed. Please try manually.', 'aarambha-demo-sites' ),
            ] );
        }

        wp_send_json_success( [ 'status' => 'activated' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: prepare-import
    // -------------------------------------------------------------------------

    /**
     * Downloads demo files and prepares for the import sequence.
     */
    public function prepareImport(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '' ) ) {
            $this->sendNotAllowed();
        }

        $slug  = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $demo  = Aarambha_DS()->demo( $slug );

        $writtenFiles = Aarambha_DS()->importer()->prepare( $demo );

        if ( ! is_array( $writtenFiles ) || count( $writtenFiles ) < 1 ) {
            wp_send_json_error( [
                'title'   => esc_html__( 'Sorry!', 'aarambha-demo-sites' ),
                'message' => esc_html__( 'This action cannot be performed now. Please try again later!', 'aarambha-demo-sites' ),
            ] );
        }

        // Remove default WP starter content so the demo is clean.
        wp_delete_post( 1, true ); // Hello World
        wp_delete_post( 2, true ); // Sample Page
        wp_delete_post( 3, true ); // Privacy Policy

        wp_send_json_success( [
            'files'  => $writtenFiles,
            'steps'  => $steps,
            'action' => 'import-content',
            'nonce'  => wp_create_nonce( 'import-content' ),
            'demo'   => $slug,
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: content-import
    // -------------------------------------------------------------------------

    /**
     * Runs the WXR content import.
     */
    public function importContent(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-content' ) ) {
            $this->sendNotAllowed();
        }

        unset( $_REQUEST['nonce'], $_REQUEST['action'] );

        $slug     = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $files    = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] ?? [] );
        $steps    = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $index    = isset( $_REQUEST['stepsIndex'] ) ? absint( $_REQUEST['stepsIndex'] ) : 0;

        $demosDir = aarambha_ds_get_demos_dir( $slug );
        $file     = wp_normalize_path( "{$demosDir}/{$files['content']}" );

        Aarambha_DS()->importer()->content(
            $file,
            aarambha_ds_sanitize_text_or_array_field( $_REQUEST )
        );

        wp_send_json_success(
            $this->nextStepResponse( $steps, $index, [ 'files' => $files ] )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: customizer-import
    // -------------------------------------------------------------------------

    /**
     * Imports customizer (theme_mod) data.
     */
    public function importCustomize(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-customizer' ) ) {
            $this->sendNotAllowed();
        }

        $slug  = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $files = is_array( $_REQUEST['files'] ?? null )
            ? aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] )
            : [];
        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $index = absint( $_REQUEST['stepsIndex'] ?? 0 );

        $demosDir = aarambha_ds_get_demos_dir( $slug );
        $file     = wp_normalize_path( "{$demosDir}/{$files['customizer']}" );

        Aarambha_DS()->importer()->customizer( $file );

        wp_send_json_success(
            $this->nextStepResponse( $steps, $index, [ 'files' => $files ] )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: widgets-import
    // -------------------------------------------------------------------------

    /**
     * Imports widget data.
     */
    public function importWidget(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-widgets' ) ) {
            $this->sendNotAllowed();
        }

        $slug  = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $files = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] ?? [] );
        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $index = absint( $_REQUEST['stepsIndex'] ?? 0 );

        $demosDir = aarambha_ds_get_demos_dir( $slug );
        $file     = wp_normalize_path( "{$demosDir}/{$files['widgets']}" );

        Aarambha_DS()->importer()->widgets( $file );

        wp_send_json_success(
            $this->nextStepResponse( $steps, $index, [ 'files' => $files ] )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: slider-import
    // -------------------------------------------------------------------------

    /**
     * Imports Smart Slider data (no-op when SmartSlider3 is not active).
     */
    public function importSlider(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-slider' ) ) {
            $this->sendNotAllowed();
        }

        $slug    = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $files   = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] ?? [] );
        $steps   = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $index   = absint( $_REQUEST['stepsIndex'] ?? 0 );
        $sliders = $files['slider'] ?? [];

        if ( class_exists( 'SmartSlider3' ) && is_array( $sliders ) && count( $sliders ) > 0 ) {
            require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-smart-slider.php';

            Aarambha_DS_Smart_Slider::delete();

            $demosDir = aarambha_ds_get_demos_dir( $slug );
            foreach ( $sliders as $slider ) {
                $file = "{$demosDir}/{$slider['file']}";
                Aarambha_DS()->importer()->slider( $file );
            }
        }

        wp_send_json_success(
            $this->nextStepResponse( $steps, $index )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: menu-import
    // -------------------------------------------------------------------------

    /**
     * Assigns imported nav menus to theme locations.
     */
    public function importMenu(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-menu' ) ) {
            $this->sendNotAllowed();
        }

        $slug  = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $demo  = Aarambha_DS()->demo( $slug );
        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $index = absint( $_REQUEST['stepsIndex'] ?? 0 );

        $navigation = aarambha_ds_sanitize_text_or_array_field( $demo['menus'] ?? [] );
        Aarambha_DS()->importer()->setupNavigation( $navigation );

        wp_send_json_success(
            $this->nextStepResponse( $steps, $index )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: pages-import
    // -------------------------------------------------------------------------

    /**
     * Sets the front page, blog page.
     * WooCommerce page wiring is intentionally deferred to finalize() so it
     * runs after ALL content is in the DB (deduplication works correctly).
     */
    public function importPages(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-pages' ) ) {
            $this->sendNotAllowed();
        }

        $slug  = sanitize_text_field( $_REQUEST['slug'] ?? '' );
        $demo  = Aarambha_DS()->demo( $slug );
        $pages = aarambha_ds_sanitize_text_or_array_field( $demo['pages'] ?? [] );
        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] ?? [] );
        $index = absint( $_REQUEST['stepsIndex'] ?? 0 );

        // ── Front page ───────────────────────────────────────────────────────
        $frontPageTitle = $pages['homepage'] ?? false;
        if ( $frontPageTitle ) {
            $homePage = get_page_by_title( $frontPageTitle );
            if ( isset( $homePage->ID ) ) {
                update_option( 'show_on_front', 'page' );
                update_option( 'page_on_front',  $homePage->ID );
            }
        }

        // ── Blog / posts page ────────────────────────────────────────────────
        $blogPageTitle = $pages['postpage'] ?? false;
        if ( $blogPageTitle ) {
            $postsPage = get_page_by_title( $blogPageTitle );
            if ( isset( $postsPage->ID ) ) {
                update_option( 'page_for_posts', $postsPage->ID );
            }
        }

        // NOTE: WooCommerce pages are handled in finalize() via
        // Aarambha_DS()->plugins()->setupWooCommercePages() which deduplicates
        // imported pages and is the correct place after all content is in the DB.

        wp_send_json_success(
            $this->nextStepResponse( $steps, $index )
        );
    }

    // -------------------------------------------------------------------------
    // AJAX: finalize-import
    // -------------------------------------------------------------------------

    /**
     * Finalizes the import:
     *  1. Wires up WooCommerce pages with deduplication (if WC is active).
     *  2. Fires the aarambha_ds_after_demo_imported action.
     *  3. Flushes rewrite rules.
     */
    public function finalize(): void {
        if ( ! wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'import-finalize' ) ) {
            $this->sendNotAllowed();
        }

        $slug = sanitize_text_field( $_REQUEST['slug'] ?? '' );

        // ── WooCommerce page setup ────────────────────────────────────────────
        // Runs here — after ALL content steps — so every imported page is
        // already in the DB. setupWooCommercePages() will:
        //   • find shop / cart / checkout / my-account pages by name or title
        //   • keep the highest-ID copy, permanently delete duplicates
        //   • save woocommerce_{key}_page_id options
        //   • delete the _wc_activation_redirect transient
        // It is a no-op when WooCommerce is not active.
        Aarambha_DS()->plugins()->setupWooCommercePages( $slug );
        // ─────────────────────────────────────────────────────────────────────

        // Generic post-import hook: Elementor kit, FA4 shim, etc.
        do_action( 'aarambha_ds_after_demo_imported' );

        flush_rewrite_rules( true );

        wp_send_json_success( [ 'action' => 'finalized' ] );
    }
}