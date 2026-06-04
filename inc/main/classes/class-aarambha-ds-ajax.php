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
     * AJAX Actions.
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
     * @class Aarambha_DS_Ajax
     * 
     * @version 1.0.0
     * @since 1.0.0
     * 
     * @return object Aarambha_DS_Ajax
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
     * A dummy constructor to prevent this class from being loaded more than once.
     *
     * @see Aarambha_DS_Ajax::getInstance()
     *
     * @since 1.0.0
     * @access private
     * @codeCoverageIgnore
     */
    private function __construct()
    {
        /* We do nothing here! */
    }

    /**
     * You cannot clone this class.
     *
     * @since 1.0.0
     * @codeCoverageIgnore
     */
    public function __clone()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html__('Cheatin&#8217; huh?', 'aarambha-demo-sites'),
            '1.0.0'
        );
    }

    /**
     * You cannot unserialize instances of this class.
     *
     * @since 1.0.0
     * @codeCoverageIgnore
     */
    public function __wakeup()
    {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html__('Cheatin&#8217; huh?', 'aarambha-demo-sites'),
            '1.0.0'
        );
    }

    /**
     * Defines all the AJAX actions.
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
            'finalize-import'      => 'finalize'
        ];
    }

    /**
     * Registers all the AJAX actions.
     */
    private function register()
    {
        foreach ($this->actions as $key => $value) {
            $ajaxAction = "wp_ajax_{$key}";

            add_action($ajaxAction, [$this, $value]);
        }
    }

    /**
     * AJAX Request
     * ----
     * Queries the API to get full demo details.
     * 
     * @uses wp_send_json_success()
     * @uses wp_send_json_error()
     * 
     * @return array
     */
    public function retrieveDemo()
    {

        $demo     = sanitize_text_field($_REQUEST['demo']);
        $demoType = sanitize_text_field($_REQUEST['demoType']);

        $nonceKey = "retrieve-demo-{$demo}";

        if (!wp_verify_nonce($_REQUEST['nonce'], $nonceKey)) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        $result = Aarambha_DS()->api()->demo($demo, $demoType);

        if ($result['success']) {

            // We've retrieved the demo information.
            // Break provided information.
            $resDemo     = $result['data'];
            $information = [];

            $information['name']    = $resDemo['name'];
            $information['slug']    = $resDemo['slug'];
            $information['image']   = $resDemo['image'];
            $information['preview'] = $resDemo['preview'];

            wp_send_json_success(['demo' => $information]);
        } else {
            wp_send_json_error([
                'title' => $result['title'],
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * AJAX Request
     * ----
     * List the plugins used in the demo.
     * 
     * @uses wp_send_json_success()
     * @uses wp_send_json_error()
     * 
     * @return array
     */
    public function listPlugins()
    {
        $theme   = aarambha_ds_get_theme();
        $slug    = sanitize_text_field($_REQUEST['slug']);

        if (!wp_verify_nonce($_REQUEST['nonce'], 'list-plugins')) {

            // Nonce cannot be verified. Try again later.
            $response = [
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        $demoKey = "aarambha_ds_{$theme}_demo_{$slug}";

        $result = get_site_transient($demoKey);

        // If transient doesn't exist, fetch from API
        if (!$result) {
            $api_result = Aarambha_DS()->api()->demo($slug);
            
            if ($api_result['success']) {
                $demo = $api_result['data'];
                // Cache it for future use
                set_site_transient($demoKey, ['data' => $demo], WEEK_IN_SECONDS);
            } else {
                // API call failed
                $response = [
                    'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                    'message' => $api_result['message']
                ];
                wp_send_json_error($response);
            }
        } else {
            $demo = $result['data'];
        }

        // Check if demo has plugins
        if (isset($demo['plugins'])) {
            $status = Aarambha_DS()->plugins()
                ->runtime($demo)
                ->html();

            wp_send_json_success($status);
        }

        // No plugins found in demo
        $response = [
            'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
            'message' => esc_html__('This demo does not contain any plugins to install.', 'aarambha-demo-sites')
        ];

        wp_send_json_error($response);
    }

    /**
     * AJAX Request
     * ----
     * Install the plugin.
     * 
     * @uses wp_send_json_success()
     * @uses wp_send_json_error()
     * 
     * @return array
     */
    public function installPlugin()
    {
        $plugin  = sanitize_text_field($_REQUEST['slug']);

        $nonceKey = "install-{$plugin}";

        if (!wp_verify_nonce($_REQUEST['nonce'], $nonceKey)) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        $result = Aarambha_DS()->plugins()->ajaxInstall($plugin);

        if (isset($result['success']) && !$result['success']) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        // For WooCommerce, auto-activate immediately after installation
        $response = [];
        
        if ('woocommerce' === $plugin) {
            // Try to get the core file for WooCommerce
            $wc_core_file = 'woocommerce/woocommerce.php';
            
            if (Aarambha_DS()->plugins()->isInstalled($wc_core_file)) {
                $activate_status = Aarambha_DS()->plugins()->activate($wc_core_file);
                
                if ($activate_status) {
                    $response['status'] = 'activated';
                } else {
                    // If activation failed, return activate status for retry
                    $key = "activate-{$plugin}";
                    $response['status'] = 'activate';
                    $response['nonce']  = wp_create_nonce($key);
                }
            } else {
                // If not installed, return activate status
                $key = "activate-{$plugin}";
                $response['status'] = 'activate';
                $response['nonce']  = wp_create_nonce($key);
            }
        } else {
            // For other plugins, return activate status for next AJAX call
            $key = "activate-{$plugin}";
            $response['status'] = 'activate';
            $response['nonce']  = wp_create_nonce($key);
        }
        
        wp_send_json_success($response);
    }

    /**
     * AJAX Request
     * ----
     * Activate the plugin.
     * 
     * @uses wp_send_json_success()
     * @uses wp_send_json_error()
     * 
     * @return array
     */
    public function activatePlugin()
    {

        $plugin  = sanitize_text_field($_REQUEST['slug']);

        $nonceKey = "activate-{$plugin}";

        $response = [];

        if (!wp_verify_nonce($_REQUEST['nonce'], $nonceKey)) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        $pluginFile = sanitize_text_field($_REQUEST['coreFile']);

        $status = Aarambha_DS()->plugins()->activate($pluginFile);

        if (!$status) {
            wp_send_json_error();
        }

        $response = [];
        $response['status'] = 'activated';
        wp_send_json_success($response);
    }

    /**
     * AJAX Request
     * ----
     * Prepares the import files.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function prepareImport()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'])) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        $theme        = aarambha_ds_get_theme();
        $slug         = sanitize_text_field($_REQUEST['slug']);

        $steps        = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $demo         = Aarambha_DS()->demo($slug);

        // If demo is not cached, fetch from API
        if (!$demo) {
            $api_result = Aarambha_DS()->api()->demo($slug);
            
            if ($api_result['success']) {
                $demo = $api_result['data'];
                // Cache it for future use
                $demoKey = "aarambha_ds_{$theme}_demo_{$slug}";
                set_site_transient($demoKey, ['data' => $demo], WEEK_IN_SECONDS);
            } else {
                $response = [
                    'title'   => esc_html__('Error!', 'aarambha-demo-sites'),
                    'message' => $api_result['message']
                ];
                wp_send_json_error($response);
            }
        }

        $importer     = Aarambha_DS()->importer();
        $writtenFiles = $importer->prepare($demo);

        if (is_array($writtenFiles) && count($writtenFiles) > 0) {

            // Delete Posts.
            wp_delete_post(1, true); // Hello World Post
            wp_delete_post(2, true); // Sample Page
            wp_delete_post(3, true); // Privacy Policy Page.

            // We need the files to proceed further.
            $response = [
                'files'  => $writtenFiles,
                'steps'  => $steps,
                'action' => 'import-content',
                'nonce'  => wp_create_nonce('import-content'),
                'demo'   => $slug,
            ];

            wp_send_json_success($response);
        }


        $response = [
            'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
            'message' => esc_html__('Failed to prepare import files. Check error logs for details.', 'aarambha-demo-sites')
        ];

        wp_send_json_error($response);
    }

    /**
     * AJAX Request
     * ----
     * Imports the content
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function importContent()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-content')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        unset($_REQUEST['nonce']);
        unset($_REQUEST['action']);

        // Actually begin the import process.
        $slug       = sanitize_text_field( $_REQUEST['slug'] );

        $demosDir   = aarambha_ds_get_demos_dir($slug);
        $files      = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] );
        $filename   = $files['content'];


        $file       = wp_normalize_path("{$demosDir}/$filename");

        $import     = Aarambha_DS()
                        ->importer()
                        ->content($file, aarambha_ds_sanitize_text_or_array_field ($_REQUEST) );


        $steps    = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $index    = isset($_REQUEST['stepsIndex']) ? absint( $_REQUEST['stepsIndex'] ) + 1 : 1;
        $nextStep = $steps[$index];


        $nonceKey = "import-{$nextStep}";

        $response = [
            'nonce'  => wp_create_nonce($nonceKey),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ];

        wp_send_json_success($response);
    }

    /**
     * AJAX Request
     * ----
     * Imports the customizer data.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function importCustomize()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-customizer')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        // Actually begin the import process.
        $slug       = sanitize_text_field( $_REQUEST['slug'] );

        $demosDir   = aarambha_ds_get_demos_dir($slug);
        $files      = is_array($_REQUEST['files']) ? aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] ) : [];

        $filename   = $files['customizer'];


        $file       = wp_normalize_path("{$demosDir}/$filename");

        $status     = Aarambha_DS()->importer()->customizer($file);

        $steps    = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $index    = absint( $_REQUEST['stepsIndex'] ) + 1;
        $nextStep = $steps[$index];
        $nonceKey = "import-{$nextStep}";
        

        $response = [
            'nonce'  => wp_create_nonce($nonceKey),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ];

        wp_send_json_success($response);
    }

    /**
     * AJAX Request
     * ----
     * Imports the widget data.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function importWidget()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-widgets')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        // Actually begin the import process.
        $slug       = sanitize_text_field( $_REQUEST['slug'] );

        $demosDir   = aarambha_ds_get_demos_dir($slug);
        $files      = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] );

        $filename   = $files['widgets'];


        $file       = wp_normalize_path("{$demosDir}/$filename");

        $status     = Aarambha_DS()->importer()->widgets($file);

        $steps    = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $index    = absint( $_REQUEST['stepsIndex'] ) + 1;
        $nextStep = $steps[$index];

        $nonceKey = "import-{$nextStep}";

        $response = [
            'nonce'  => wp_create_nonce($nonceKey),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ];

        wp_send_json_success($response);
    }

    /**
     * AJAX Request
     * ----
     * Imports the slider.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function importSlider()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-slider')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        // Import the slider.
        $slug       = sanitize_text_field( $_REQUEST['slug'] );

        $demosDir   = aarambha_ds_get_demos_dir($slug);
        $files      = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['files'] );
        $sliders    = $files['slider'];

        if (class_exists('SmartSlider3') && is_array($sliders) && count($sliders) > 0) {

            require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-smart-slider.php';

            Aarambha_DS_Smart_Slider::delete();

            foreach ($sliders as $slider) {
                $sliderFile = $slider['file'];
                $file = "{$demosDir}/{$sliderFile}";
                Aarambha_DS()->importer()->slider($file);
            }
        }

        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $index = (int) $_REQUEST['stepsIndex'] + 1;
        $nextStep = $steps[$index];

        $nonceKey = "import-{$nextStep}";

        $response = [
            'nonce'  => wp_create_nonce($nonceKey),
            'action' => $nextStep,
            'steps'  => $steps,
        ];

        wp_send_json_success($response);
    }

    /**
     * AJAX Request
     * ----
     * Imports the menu.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function importMenu()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-menu')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        $slug       = sanitize_text_field($_REQUEST['slug']);
        $demo       = Aarambha_DS()->demo($slug);

        $navigation = aarambha_ds_sanitize_text_or_array_field( $demo['menus'] );

        Aarambha_DS()->importer()->setupNavigation($navigation);

        $steps = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $index = absint( $_REQUEST['stepsIndex'] ) + 1;
        $nextStep = $steps[$index];

        $nonceKey = "import-{$nextStep}";

        $response = [
            'nonce'  => wp_create_nonce($nonceKey),
            'action' => $nextStep,
            'steps'  => $steps,
        ];

        wp_send_json_success($response);
    }


    /**
     * AJAX Request
     * ----
     * Basicallys sets up the page reading.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function importPages()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-pages')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        // Setup the pages.
        $slug       = sanitize_text_field($_REQUEST['slug']);
        $demo       = Aarambha_DS()->demo($slug);

        $pages      = aarambha_ds_sanitize_text_or_array_field( $demo['pages'] );

        $wcSupport  = false;

        if (isset($demo['wcSupport']) && $demo['wcSupport']) {
            $wcSupport = true;
        }

        $frontPage = isset( $pages['homepage'] ) ? $pages['homepage'] : false;
        $blogPage  = (isset($pages['postpage'])) ? $pages['postpage'] : false;

        if ( $frontPage ) {
            $homePage  = get_page_by_title($frontPage);

            if (isset($homePage->ID)){
                update_option('show_on_front', 'page');
                update_option('page_on_front', $homePage->ID);
            }
        } 

        if ( $blogPage ) {
            $postsPage = get_page_by_title($blogPage);

            if (isset($postsPage->ID)) {
                update_option('page_for_posts', $postsPage->ID);
            }
        }

        if ($wcSupport) {
            $wc_pages = [
                'shop'          => 'Store',
                'cart'          => 'Cart',
                'checkout'      => 'Checkout',
                'myaccount'     => 'My account',
            ];

            // Ensure WooCommerce plugin is active before attempting to set WC pages/options.
            if (!function_exists('WC')) {
                if (!function_exists('is_plugin_active')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                // Try to activate the WooCommerce plugin if installed.
                if (file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                    $activate = activate_plugin('woocommerce/woocommerce.php');

                    // If activation did not bootstrap WC, try including main file directly.
                    if (!function_exists('WC') && file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                        include_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
                    }
                }
            }

            // If demo provides WooCommerce settings, apply them.
            $wcSettings = [];
            if (isset($demo['wcSettings']) && is_array($demo['wcSettings'])) {
                $wcSettings = $demo['wcSettings'];
            } elseif (isset($demo['woocommerce']) && is_array($demo['woocommerce'])) {
                $wcSettings = $demo['woocommerce'];
            } elseif (isset($demo['store']) && is_array($demo['store'])) {
                $wcSettings = $demo['store'];
            }

            if (!empty($wcSettings) && is_array($wcSettings)) {
                foreach ($wcSettings as $key => $value) {
                    // Only update simple scalar options to avoid corrupting complex settings.
                    if (is_scalar($value)) {
                        update_option(sanitize_text_field($key), maybe_unserialize($value));
                    }
                }
            }

            // Setup WooCommerce Pages.
            if (is_array($wc_pages) && function_exists('WC') && count($wc_pages) > 0) {

                foreach ($wc_pages as $slug => $title) {

                    $woopage = get_page_by_title(html_entity_decode($title));
                    if (isset($woopage) && property_exists($woopage, 'ID')) {

                        // prepare WooCommerce option slug where pages are stored.
                        $key = "woocommerce_{$slug}_page_id";
                        update_option($key, $woopage->ID);
                    }
                }
            }
        }

        $steps    = aarambha_ds_sanitize_text_or_array_field( $_REQUEST['steps'] );
        $index    = absint( $_REQUEST['stepsIndex'] ) + 1;
        $nextStep = $steps[$index];

        $nonceKey = "import-{$nextStep}";

        $response = [
            'nonce'  => wp_create_nonce($nonceKey),
            'action' => $nextStep,
            'steps'  => $steps
        ];

        wp_send_json_success($response);
    }
    
    /**
     * AJAX Request
     * ----
     * Finalize the import.
     * 
     * @return wp_send_json_{success|error} Success or Error depending upon.
     */
    public function finalize()
    {

        if (!wp_verify_nonce($_REQUEST['nonce'], 'import-finalize')) {
            // Nonce cannot be verified. Try again later.
            $response = [
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites')
            ];

            wp_send_json_error($response);
        }

        // Auto-complete WooCommerce setup wizard if WC is active
        if (function_exists('WC')) {
            $this->completeWooCommerceWizard();
        }

        // Add Hook
        do_action( 'aarambha_ds_after_demo_imported' );

        flush_rewrite_rules(true);

        $response = [
            'action' => 'finalized',
        ];

        wp_send_json_success($response);
    }

    /**
     * Complete the WooCommerce setup wizard by setting necessary options.
     * 
     * @return void
     */
    private function completeWooCommerceWizard()
    {
        // Mark WooCommerce setup wizard as completed
        update_option('woocommerce_setup_jetpack_activated', 'yes');
        update_option('woocommerce_admin_install_timestamp', time());
        
        // Mark onboarding as complete
        update_option('woocommerce_onboarding_profile', [
            'completed' => true,
            'skipped' => false,
        ]);

        // Set up basic store settings if not already configured
        $store_settings = [
            'store_name' => get_option('blogname'),
            'store_address' => get_option('woocommerce_store_address'),
            'store_address_2' => get_option('woocommerce_store_address_2'),
            'store_city' => get_option('woocommerce_store_city'),
            'store_postcode' => get_option('woocommerce_store_postcode'),
            'store_country' => get_option('woocommerce_default_country'),
            'store_state' => get_option('woocommerce_store_state'),
            'currency' => get_option('woocommerce_currency'),
        ];

        update_option('woocommerce_setup_wizard_completed', 'yes');

        // Add wc-admin notes/tasks completion flags if wcAdmin is loaded
        if (function_exists('wc_admin_record_tracks_event')) {
            // Dismiss initial setup tasks
            update_option('woocommerce_task_list_welcome_modal_dismissed', 'yes');
            update_option('woocommerce_admin_dismissed_notices', [
                'welcome-banner',
                'woocommerce_admin_welcome',
                'woocommerce_onboarding_profile_notice',
            ]);
        }
    }
}
