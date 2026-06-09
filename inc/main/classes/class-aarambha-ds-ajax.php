<?php

/**
 * Handle the AJAX sent through demo importer.
 * 
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core/UI
 */

if (!defined('WPINC')) {
    exit;    // Exit if accessed directly.
}

/**
 * Class Aarambha_DS_Ajax
 * 
 * Handles the AJAX Actions.
 */
class Aarambha_DS_Ajax
{
    /**
     * Single class instance.
     * 
     * @since 1.0.0
     * @access private
     * 
     * @var object
     */
    private static $instance = null;

    /**
     * AJAX Actions map: action-name => method-name.
     * 
     * @since 1.0.0
     * @access private
     * 
     * @var array
     */
    private $actions = [];

    /**
     * Registers and fires the AJAX actions.
     *
     * @since 1.0.0
     * @return Aarambha_DS_Ajax
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->define();
            self::$instance->register();
        }

        return self::$instance;
    }

    /**
     * Private constructor.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct()
    {
        /* We do nothing here! */
    }

    public function __clone()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html__('Cheatin&#8217; huh?', 'aarambha-demo-sites'),
            '1.0.0'
        );
    }

    public function __wakeup()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html__('Cheatin&#8217; huh?', 'aarambha-demo-sites'),
            '1.0.0'
        );
    }

    /**
     * Defines all the AJAX action-to-method mappings.
     */
    private function define()
    {
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

    /**
     * Registers all the AJAX actions with WordPress.
     */
    private function register()
    {
        foreach ($this->actions as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
        }
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Verify a nonce and die with a JSON error response on failure.
     *
     * @param string $nonce_value The raw nonce value from the request.
     * @param string $nonce_action The expected nonce action string.
     */
    private function verifyNonce($nonce_value, $nonce_action)
    {
        if (!wp_verify_nonce($nonce_value, $nonce_action)) {
            wp_send_json_error([
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites'),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * Retrieves full demo details from the API.
     */
    public function retrieveDemo()
    {
        $demo     = sanitize_text_field($_REQUEST['demo']);
        $demoType = sanitize_text_field($_REQUEST['demoType']);

        $this->verifyNonce($_REQUEST['nonce'], "retrieve-demo-{$demo}");

        $result = Aarambha_DS()->api()->demo($demo, $demoType);

        if ($result['success']) {
            $resDemo = $result['data'];

            wp_send_json_success([
                'demo' => [
                    'name'    => $resDemo['name'],
                    'slug'    => $resDemo['slug'],
                    'image'   => $resDemo['image'],
                    'preview' => $resDemo['preview'],
                ],
            ]);
        }

        wp_send_json_error([
            'title'   => $result['title'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Lists the plugins required by a demo.
     */
    public function listPlugins()
    {
        $slug = sanitize_text_field($_REQUEST['slug']);

        $this->verifyNonce($_REQUEST['nonce'], 'list-plugins');

        $theme   = aarambha_ds_get_theme();
        $demoKey = "aarambha_ds_{$theme}_demo_{$slug}";
        $result  = get_site_transient($demoKey);

        if (!$result) {
            $api_result = Aarambha_DS()->api()->demo($slug);

            if ($api_result['success']) {
                $demo = $api_result['data'];
                set_site_transient($demoKey, ['data' => $demo], WEEK_IN_SECONDS);
            } else {
                wp_send_json_error([
                    'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                    'message' => isset($api_result['message']) ? $api_result['message'] : esc_html__('Failed to retrieve demo data.', 'aarambha-demo-sites'),
                ]);
            }
        } else {
            $demo = $result['data'];
        }

        if (isset($demo['plugins'])) {
            $status = Aarambha_DS()->plugins()
                ->runtime($demo)
                ->html();

            wp_send_json_success($status);
        }

        wp_send_json_error([
            'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
            'message' => esc_html__('This demo does not contain any plugins to install.', 'aarambha-demo-sites'),
        ]);
    }

    /**
     * Installs a plugin via AJAX.
     */
    public function installPlugin()
    {
        $plugin = sanitize_text_field($_REQUEST['slug']);

        $this->verifyNonce($_REQUEST['nonce'], "install-{$plugin}");

        $result = Aarambha_DS()->plugins()->ajaxInstall($plugin);

        if (isset($result['success']) && !$result['success']) {
            wp_send_json_error([
                'code'    => 'install_failed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => isset($result['errorMessage']) ? $result['errorMessage'] : esc_html__('Plugin installation failed.', 'aarambha-demo-sites'),
            ]);
        }

        $response = [];

        // For WooCommerce, try to auto-activate immediately after installation.
        if ('woocommerce' === $plugin) {
            $wc_core_file = 'woocommerce/woocommerce.php';

            if (Aarambha_DS()->plugins()->isInstalled($wc_core_file)) {
                $activated = Aarambha_DS()->plugins()->activate($wc_core_file);

                if ($activated) {
                    $response['status'] = 'activated';
                } else {
                    $response['status'] = 'activate';
                    $response['nonce']  = wp_create_nonce("activate-{$plugin}");
                }
            } else {
                $response['status'] = 'activate';
                $response['nonce']  = wp_create_nonce("activate-{$plugin}");
            }
        } else {
            $response['status'] = 'activate';
            $response['nonce']  = wp_create_nonce("activate-{$plugin}");
        }

        wp_send_json_success($response);
    }

    /**
     * Activates an installed plugin via AJAX.
     */
    public function activatePlugin()
    {
        $plugin = sanitize_text_field($_REQUEST['slug']);

        $this->verifyNonce($_REQUEST['nonce'], "activate-{$plugin}");

        $pluginFile = sanitize_text_field($_REQUEST['coreFile']);
        $status     = Aarambha_DS()->plugins()->activate($pluginFile);

        if (!$status) {
            wp_send_json_error([
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('Plugin activation failed.', 'aarambha-demo-sites'),
            ]);
        }

        wp_send_json_success(['status' => 'activated']);
    }

    /**
     * Downloads all demo files and prepares the import.
     */
    public function prepareImport()
    {
        // prepareImport uses the generic nonce (no action string) created at enqueue time.
        if (empty($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'])) {
            $msg = esc_html__('Invalid or missing nonce for prepare-import.', 'aarambha-demo-sites');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Aarambha DS] prepareImport nonce failed: %s', var_export($_REQUEST, true)));
            }

            wp_send_json_error([
                'code'    => 'invalid_nonce',
                'title'   => esc_html__('Permission denied', 'aarambha-demo-sites'),
                'message' => $msg,
            ]);
        }

        $theme = aarambha_ds_get_theme();
        $slug  = sanitize_text_field($_REQUEST['slug']);
        $steps = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $demo  = Aarambha_DS()->demo($slug);

        // Fetch from API if not cached.
        if (!$demo) {
            $api_result = Aarambha_DS()->api()->demo($slug);

            if ($api_result['success']) {
                $demo    = $api_result['data'];
                $demoKey = "aarambha_ds_{$theme}_demo_{$slug}";
                set_site_transient($demoKey, ['data' => $demo], WEEK_IN_SECONDS);
            } else {
                wp_send_json_error([
                    'title'   => esc_html__('Error!', 'aarambha-demo-sites'),
                    'message' => isset($api_result['message']) ? $api_result['message'] : esc_html__('Failed to retrieve demo data.', 'aarambha-demo-sites'),
                ]);
            }
        }

        try {
            $writtenFiles = Aarambha_DS_Core::getInstance()->prepare($demo);
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Aarambha DS] prepareImport exception: %s', $e->getMessage()));
            }

            wp_send_json_error([
                'title'   => esc_html__('Error preparing import', 'aarambha-demo-sites'),
                'message' => esc_html__('An exception occurred while preparing import files. Check server logs for details.', 'aarambha-demo-sites'),
            ]);
        }

        if (is_array($writtenFiles) && count($writtenFiles) > 0) {

            // Delete default WordPress sample content.
            wp_delete_post(1, true); // Hello World post
            wp_delete_post(2, true); // Sample Page
            wp_delete_post(3, true); // Privacy Policy page

            wp_send_json_success([
                'files'  => $writtenFiles,
                'steps'  => $steps,
                'action' => 'import-content',
                'nonce'  => wp_create_nonce('import-content'),
                'demo'   => $slug,
            ]);
        }

        $message = esc_html__('Failed to prepare import files. Check error logs for details.', 'aarambha-demo-sites');
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($writtenFiles)) {
            // If there are attempted files but no successful writes, include a hint.
            $message = esc_html__('Failed to prepare import files. Some downloads may have failed — check error logs.', 'aarambha-demo-sites');
        }

        wp_send_json_error([
            'title'   => esc_html__('Error preparing import', 'aarambha-demo-sites'),
            'message' => $message,
        ]);
    }

    /**
     * Imports WordPress content (WXR).
     *
     * FIX: previously called Aarambha_DS()->importer()->content() which does not
     * exist on Aarambha_WP_Import. Correctly delegates to Aarambha_DS_Core::content().
     */
    public function importContent()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-content');

        $slug     = sanitize_text_field($_REQUEST['slug']);
        $files    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['files']);
        $steps    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index    = isset($_REQUEST['stepsIndex']) ? absint($_REQUEST['stepsIndex']) + 1 : 1;

        $demosDir = aarambha_ds_get_demos_dir($slug);

        // Support either a single filename or an array of possible files.
        $filename = isset($files['content']) ? $files['content'] : null;

        $result = [
            'action'  => 'terminate',
            'message' => esc_html__('No content file provided for import.', 'aarambha-demo-sites'),
        ];

        if (is_array($filename)) {
            // Try each candidate until one succeeds.
            foreach ($filename as $candidate) {
                // Candidate might be a scalar filename or an array with 'file'.
                $base = is_array($candidate) && isset($candidate['file']) ? $candidate['file'] : $candidate;
                if (empty($base)) {
                    continue;
                }

                $file = wp_normalize_path("{$demosDir}/{$base}");
                $result = Aarambha_DS()->core()->content($file);

                if (isset($result['action']) && 'import-customize' === $result['action']) {
                    // Successful import — stop trying further files.
                    break;
                }
            }
        } elseif (!empty($filename)) {
            $file   = wp_normalize_path("{$demosDir}/{$filename}");
            $result = Aarambha_DS()->core()->content($file);
        }

        if (isset($result['action']) && 'terminate' === $result['action']) {
            $error_payload = [
                'title'   => esc_html__('Import Failed', 'aarambha-demo-sites'),
                'message' => $result['message'],
            ];

            // Include file/debug info when debugging is enabled to aid diagnosis.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $error_payload['file'] = isset($result['file']) ? $result['file'] : null;
                if (isset($result['processed_posts_count'])) {
                    $error_payload['processed_posts_count'] = $result['processed_posts_count'];
                }
                if (isset($result['processed_terms_count'])) {
                    $error_payload['processed_terms_count'] = $result['processed_terms_count'];
                }
            }

            wp_send_json_error($error_payload);
        }

        $nextStep = $steps[$index];

        wp_send_json_success([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ]);
    }

    /**
     * Imports Customizer settings.
     *
     * FIX: previously called Aarambha_DS()->importer()->customizer() which does not
     * exist on Aarambha_WP_Import. Correctly delegates to Aarambha_DS_Core::customizer().
     */
    public function importCustomize()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-customizer');

        $slug     = sanitize_text_field($_REQUEST['slug']);
        $files    = is_array($_REQUEST['files'])
                        ? aarambha_ds_sanitize_text_or_array_field($_REQUEST['files'])
                        : [];
        $steps    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index    = absint($_REQUEST['stepsIndex']) + 1;

        $demosDir = aarambha_ds_get_demos_dir($slug);
        $filename = $files['customizer'];
        $file     = wp_normalize_path("{$demosDir}/{$filename}");

        // Delegate to Aarambha_DS_Core — NOT to Aarambha_DS()->importer().
        Aarambha_DS()->core()->customizer($file);

        $nextStep = $steps[$index];

        wp_send_json_success([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ]);
    }

    /**
     * Imports widget data.
     *
     * FIX: previously called Aarambha_DS()->importer()->widgets() which does not
     * exist on Aarambha_WP_Import. Correctly delegates to Aarambha_DS_Core::widgets().
     */
    public function importWidget()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-widgets');

        $slug     = sanitize_text_field($_REQUEST['slug']);
        $files    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['files']);
        $steps    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index    = absint($_REQUEST['stepsIndex']) + 1;

        $demosDir = aarambha_ds_get_demos_dir($slug);
        $filename = $files['widgets'];
        $file     = wp_normalize_path("{$demosDir}/{$filename}");

        // Delegate to Aarambha_DS_Core — NOT to Aarambha_DS()->importer().
        Aarambha_DS()->core()->widgets($file);

        $nextStep = $steps[$index];

        wp_send_json_success([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ]);
    }

    /**
     * Imports Smart Slider 3 sliders.
     *
     * FIX: previously called Aarambha_DS()->importer()->slider() which does not
     * exist on Aarambha_WP_Import. Correctly delegates to Aarambha_DS_Core::slider().
     */
    public function importSlider()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-slider');

        $slug    = sanitize_text_field($_REQUEST['slug']);
        $files   = aarambha_ds_sanitize_text_or_array_field($_REQUEST['files']);
        $steps   = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index   = absint($_REQUEST['stepsIndex']) + 1;

        $demosDir = aarambha_ds_get_demos_dir($slug);
        $sliders  = isset($files['slider']) ? $files['slider'] : [];

        if (class_exists('SmartSlider3') && is_array($sliders) && count($sliders) > 0) {

            require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-smart-slider.php';
            Aarambha_DS_Smart_Slider::delete();

            foreach ($sliders as $slider) {
                $sliderFile = $slider['file'];
                $file       = "{$demosDir}/{$sliderFile}";
                // Delegate to Aarambha_DS_Core::slider().
                Aarambha_DS()->core()->slider($file);
            }
        }

        $nextStep = $steps[$index];

        wp_send_json_success([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
        ]);
    }

    /**
     * Sets up navigation menus after content import.
     *
     * FIX: previously called Aarambha_DS()->importer()->setupNavigation() which does
     * not exist on Aarambha_WP_Import. Correctly delegates to Aarambha_DS_Core::setupNavigation().
     */
    public function importMenu()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-menu');

        $slug       = sanitize_text_field($_REQUEST['slug']);
        $demo       = Aarambha_DS()->demo($slug);
        $navigation = aarambha_ds_sanitize_text_or_array_field($demo['menus']);

        // Delegate to Aarambha_DS_Core — NOT to Aarambha_DS()->importer().
        Aarambha_DS()->core()->setupNavigation($navigation);

        $steps    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index    = absint($_REQUEST['stepsIndex']) + 1;
        $nextStep = $steps[$index];

        wp_send_json_success([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
        ]);
    }

    /**
     * Sets up front page, blog page, and WooCommerce pages.
     */
    public function importPages()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-pages');

        $slug  = sanitize_text_field($_REQUEST['slug']);
        $demo  = Aarambha_DS()->demo($slug);
        $pages = aarambha_ds_sanitize_text_or_array_field($demo['pages']);

        $wcSupport = !empty($demo['wcSupport']);

        $frontPage = isset($pages['homepage']) ? $pages['homepage'] : false;
        $blogPage  = isset($pages['postpage']) ? $pages['postpage'] : false;

        if ($frontPage) {
            $homePage = get_page_by_title($frontPage);
            if (isset($homePage->ID)) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $homePage->ID);
            }
        }

        if ($blogPage) {
            $postsPage = get_page_by_title($blogPage);
            if (isset($postsPage->ID)) {
                update_option('page_for_posts', $postsPage->ID);
            }
        }

        if ($wcSupport) {
            $wc_pages = [
                'shop'      => 'Store',
                'cart'      => 'Cart',
                'checkout'  => 'Checkout',
                'myaccount' => 'My account',
            ];

            // Ensure WooCommerce is active.
            if (!function_exists('WC')) {
                if (!function_exists('is_plugin_active')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                if (file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                    activate_plugin('woocommerce/woocommerce.php');

                    if (!function_exists('WC')) {
                        include_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
                    }
                }
            }

            // Apply any demo-provided WooCommerce scalar options.
            $wcSettings = [];
            if (isset($demo['wcSettings']) && is_array($demo['wcSettings'])) {
                $wcSettings = $demo['wcSettings'];
            } elseif (isset($demo['woocommerce']) && is_array($demo['woocommerce'])) {
                $wcSettings = $demo['woocommerce'];
            } elseif (isset($demo['store']) && is_array($demo['store'])) {
                $wcSettings = $demo['store'];
            }

            foreach ($wcSettings as $key => $value) {
                if (is_scalar($value)) {
                    update_option(sanitize_text_field($key), maybe_unserialize($value));
                }
            }

            // Assign WooCommerce page IDs.
            if (function_exists('WC')) {
                foreach ($wc_pages as $wc_slug => $title) {
                    $woopage = get_page_by_title(html_entity_decode($title));
                    if (isset($woopage) && property_exists($woopage, 'ID')) {
                        update_option("woocommerce_{$wc_slug}_page_id", $woopage->ID);
                    }
                }
            }
        }

        $steps    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index    = absint($_REQUEST['stepsIndex']) + 1;
        $nextStep = $steps[$index];

        wp_send_json_success([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
        ]);
    }

    /**
     * Finalizes the import process.
     */
    public function finalize()
    {
        $this->verifyNonce($_REQUEST['nonce'], 'import-finalize');

        if (function_exists('WC')) {
            $this->completeWooCommerceWizard();
        }

        do_action('aarambha_ds_after_demo_imported');

        flush_rewrite_rules(true);

        wp_send_json_success(['action' => 'finalized']);
    }

    /**
     * Marks the WooCommerce setup wizard as complete.
     */
    private function completeWooCommerceWizard()
    {
        update_option('woocommerce_setup_jetpack_activated', 'yes');
        update_option('woocommerce_admin_install_timestamp', time());
        update_option('woocommerce_onboarding_profile', [
            'completed' => true,
            'skipped'   => false,
        ]);
        update_option('woocommerce_setup_wizard_completed', 'yes');

        if (function_exists('wc_admin_record_tracks_event')) {
            update_option('woocommerce_task_list_welcome_modal_dismissed', 'yes');
            update_option('woocommerce_admin_dismissed_notices', [
                'welcome-banner',
                'woocommerce_admin_welcome',
                'woocommerce_onboarding_profile_notice',
            ]);
        }
    }
}
