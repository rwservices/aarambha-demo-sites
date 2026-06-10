<?php

/**
 * Handle the AJAX sent through demo importer.
 *
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core/UI
 */

if (!defined('WPINC')) {
    exit;
}

class Aarambha_DS_Ajax
{
    private static $instance = null;
    private $actions = [];

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->define();
            self::$instance->register();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cheatin&#8217; huh?', 'aarambha-demo-sites'), '1.0.0');
    }

    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cheatin&#8217; huh?', 'aarambha-demo-sites'), '1.0.0');
    }

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

    private function register()
    {
        foreach ($this->actions as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Start output buffering to catch any stray text (PHP notices, WP_Import
     * echo statements, plugin output) that would corrupt the JSON response and
     * cause "SyntaxError: Unexpected token 'F'" on the front end.
     *
     * Call this at the very top of every AJAX handler.
     */
    private function startBuffer()
    {
        // Disable all PHP error display — errors must go to the log, not stdout.
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
    }

    /**
     * Discard any buffered output and send a JSON success response.
     *
     * @param mixed $data
     */
    private function sendSuccess($data)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        wp_send_json_success($data);
    }

    /**
     * Discard any buffered output and send a JSON error response.
     *
     * @param mixed $data
     */
    private function sendError($data)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        wp_send_json_error($data);
    }

    /**
     * Verify a nonce; on failure discard buffer and send JSON error.
     *
     * @param string $nonce_value
     * @param string $nonce_action
     */
    private function verifyNonce($nonce_value, $nonce_action)
    {
        if (!wp_verify_nonce($nonce_value, $nonce_action)) {
            $this->sendError([
                'code'    => 'not_allowed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('This action cannot be performed now. Please try again later!', 'aarambha-demo-sites'),
            ]);
        }
    }

    /**
     * Safely get the next step name from the steps array.
     * Returns null when the index is out of bounds instead of triggering a PHP
     * notice that gets mixed into the response body.
     *
     * @param  array $steps
     * @param  int   $index
     * @return string|null
     */
    private function nextStep(array $steps, int $index): ?string
    {
        return isset($steps[$index]) ? $steps[$index] : null;
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public function retrieveDemo()
    {
        $this->startBuffer();

        $demo     = sanitize_text_field($_REQUEST['demo']);
        $demoType = sanitize_text_field($_REQUEST['demoType']);

        $this->verifyNonce($_REQUEST['nonce'], "retrieve-demo-{$demo}");

        $result = Aarambha_DS()->api()->demo($demo, $demoType);

        if ($result['success']) {
            $resDemo = $result['data'];
            $this->sendSuccess([
                'demo' => [
                    'name'    => $resDemo['name'],
                    'slug'    => $resDemo['slug'],
                    'image'   => $resDemo['image'],
                    'preview' => $resDemo['preview'],
                ],
            ]);
        }

        $this->sendError([
            'title'   => isset($result['title'])   ? $result['title']   : esc_html__('Error', 'aarambha-demo-sites'),
            'message' => isset($result['message']) ? $result['message'] : esc_html__('Failed to retrieve demo.', 'aarambha-demo-sites'),
        ]);
    }

    public function listPlugins()
    {
        $this->startBuffer();

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
                $this->sendError([
                    'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                    'message' => isset($api_result['message']) ? $api_result['message'] : esc_html__('Failed to retrieve demo data.', 'aarambha-demo-sites'),
                ]);
            }
        } else {
            $demo = $result['data'];
        }

        if (isset($demo['plugins'])) {
            $status = Aarambha_DS()->plugins()->runtime($demo)->html();
            $this->sendSuccess($status);
        }

        $this->sendError([
            'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
            'message' => esc_html__('This demo does not contain any plugins to install.', 'aarambha-demo-sites'),
        ]);
    }

    public function installPlugin()
    {
        $this->startBuffer();

        $plugin = sanitize_text_field($_REQUEST['slug']);

        $this->verifyNonce($_REQUEST['nonce'], "install-{$plugin}");

        $result = Aarambha_DS()->plugins()->ajaxInstall($plugin);

        if (isset($result['success']) && !$result['success']) {
            $this->sendError([
                'code'    => 'install_failed',
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => isset($result['errorMessage']) ? $result['errorMessage'] : esc_html__('Plugin installation failed.', 'aarambha-demo-sites'),
            ]);
        }

        $response = [];

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

        $this->sendSuccess($response);
    }

    public function activatePlugin()
    {
        $this->startBuffer();

        $plugin = sanitize_text_field($_REQUEST['slug']);

        $this->verifyNonce($_REQUEST['nonce'], "activate-{$plugin}");

        $pluginFile = sanitize_text_field($_REQUEST['coreFile']);
        $status     = Aarambha_DS()->plugins()->activate($pluginFile);

        if (!$status) {
            $this->sendError([
                'title'   => esc_html__('Sorry!', 'aarambha-demo-sites'),
                'message' => esc_html__('Plugin activation failed.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess(['status' => 'activated']);
    }

    public function prepareImport()
    {
        $this->startBuffer();

        if (empty($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'])) {
            $this->sendError([
                'code'    => 'invalid_nonce',
                'title'   => esc_html__('Permission denied', 'aarambha-demo-sites'),
                'message' => esc_html__('Invalid or missing nonce for prepare-import.', 'aarambha-demo-sites'),
            ]);
        }

        $theme = aarambha_ds_get_theme();
        $slug  = sanitize_text_field($_REQUEST['slug']);
        $steps = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $demo  = Aarambha_DS()->demo($slug);

        if (!$demo) {
            $api_result = Aarambha_DS()->api()->demo($slug);

            if ($api_result['success']) {
                $demo    = $api_result['data'];
                $demoKey = "aarambha_ds_{$theme}_demo_{$slug}";
                set_site_transient($demoKey, ['data' => $demo], WEEK_IN_SECONDS);
            } else {
                $this->sendError([
                    'title'   => esc_html__('Error!', 'aarambha-demo-sites'),
                    'message' => isset($api_result['message']) ? $api_result['message'] : esc_html__('Failed to retrieve demo data.', 'aarambha-demo-sites'),
                ]);
            }
        }

        try {
            $writtenFiles = Aarambha_DS_Core::getInstance()->prepare($demo);
        } catch (Exception $e) {
            error_log(sprintf('[Aarambha DS] prepareImport exception: %s', $e->getMessage()));
            $this->sendError([
                'title'   => esc_html__('Error preparing import', 'aarambha-demo-sites'),
                'message' => esc_html__('An exception occurred while preparing import files. Check server logs for details.', 'aarambha-demo-sites'),
            ]);
        }

        if (is_array($writtenFiles) && count($writtenFiles) > 0) {
            wp_delete_post(1, true);
            wp_delete_post(2, true);
            wp_delete_post(3, true);

            $this->sendSuccess([
                'files'  => $writtenFiles,
                'steps'  => $steps,
                'action' => 'import-content',
                'nonce'  => wp_create_nonce('import-content'),
                'demo'   => $slug,
            ]);
        }

        $this->sendError([
            'title'   => esc_html__('Error preparing import', 'aarambha-demo-sites'),
            'message' => esc_html__('Failed to prepare import files. Check error logs for details.', 'aarambha-demo-sites'),
        ]);
    }

    public function importContent()
    {
        $this->startBuffer();

        $this->verifyNonce($_REQUEST['nonce'], 'import-content');

        $slug  = sanitize_text_field($_REQUEST['slug']);
        $files = aarambha_ds_sanitize_text_or_array_field($_REQUEST['files']);
        $steps = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index = isset($_REQUEST['stepsIndex']) ? absint($_REQUEST['stepsIndex']) + 1 : 1;

        $demosDir = aarambha_ds_get_demos_dir($slug);
        $filename = isset($files['content']) ? $files['content'] : null;

        $result = [
            'action'  => 'terminate',
            'message' => esc_html__('No content file provided for import.', 'aarambha-demo-sites'),
        ];

        if (is_array($filename)) {
            foreach ($filename as $candidate) {
                $base = is_array($candidate) && isset($candidate['file']) ? $candidate['file'] : $candidate;
                if (empty($base)) continue;

                $file   = wp_normalize_path("{$demosDir}/{$base}");
                $result = Aarambha_DS()->core()->content($file);

                if (isset($result['action']) && 'import-customize' === $result['action']) {
                    break;
                }
            }
        } elseif (!empty($filename)) {
            $file   = wp_normalize_path("{$demosDir}/{$filename}");
            $result = Aarambha_DS()->core()->content($file);
        }

        if (isset($result['action']) && 'terminate' === $result['action']) {
            $this->sendError([
                'title'   => esc_html__('Import Failed', 'aarambha-demo-sites'),
                'message' => $result['message'],
            ]);
        }

        $nextStep = $this->nextStep($steps, $index);

        if (null === $nextStep) {
            $this->sendError([
                'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                'message' => esc_html__('Steps index out of range after content import.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ]);
    }

    /**
     * Imports Customizer settings from the .dat file.
     *
     * KEY FIXES:
     * 1. startBuffer() at the top discards any stray PHP output (notices,
     *    WP_Import echo statements, theme/plugin hooks that echo) so nothing
     *    contaminates the JSON — this is the direct cause of the SyntaxError.
     *
     * 2. Nonce action is derived from the step name sent by JS
     *    (e.g. "customizer-import"), not a hardcoded string, so create/verify
     *    always match.
     *
     * 3. WP_Error returned by the importer is now caught and sent as JSON
     *    instead of being ignored, so customizer values actually get saved.
     */
    public function importCustomize()
    {
        $this->startBuffer();

        // The nonce was created as wp_create_nonce("import-{$nextStep}") in the
        // previous step, where $nextStep is the SHORT step name from the steps
        // array (e.g. "customizer"), NOT the full WP AJAX action ("customizer-import").
        // Strip the trailing "-import" suffix so create and verify always match.
        $step = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'customizer-import';
        $step = preg_replace('/-import$/', '', $step); // "customizer-import" → "customizer"
        $this->verifyNonce($_REQUEST['nonce'], "import-{$step}");

        $slug  = sanitize_text_field($_REQUEST['slug']);
        $files = (isset($_REQUEST['files']) && is_array($_REQUEST['files']))
                    ? aarambha_ds_sanitize_text_or_array_field($_REQUEST['files'])
                    : [];
        $steps = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index = absint($_REQUEST['stepsIndex']) + 1;

        // No customizer file for this demo — skip silently and advance.
        if (empty($files['customizer'])) {
            $nextStep = $this->nextStep($steps, $index);
            if (null === $nextStep) {
                $this->sendError([
                    'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                    'message' => esc_html__('Steps index out of range after customizer import.', 'aarambha-demo-sites'),
                ]);
            }
            $this->sendSuccess([
                'nonce'  => wp_create_nonce("import-{$nextStep}"),
                'action' => $nextStep,
                'steps'  => $steps,
                'files'  => $files,
            ]);
        }

        $demosDir = aarambha_ds_get_demos_dir($slug);
        $filename = $files['customizer'];
        $file     = wp_normalize_path("{$demosDir}/{$filename}");

        // File must exist on disk before we attempt to import.
        if (!file_exists($file)) {
            $this->sendError([
                'title'   => esc_html__('Customizer Import Error', 'aarambha-demo-sites'),
                'message' => sprintf(
                    esc_html__('Customizer file not found: %s', 'aarambha-demo-sites'),
                    esc_html($file)
                ),
            ]);
        }

        // Run the import — any stray output is already trapped by ob_start().
        $result = Aarambha_DS()->core()->customizer($file);

        // core()->customizer() now propagates WP_Error — catch it here.
        if (is_wp_error($result)) {
            $this->sendError([
                'title'   => esc_html__('Customizer Import Failed', 'aarambha-demo-sites'),
                'message' => $result->get_error_message(),
            ]);
        }

        $nextStep = $this->nextStep($steps, $index);

        if (null === $nextStep) {
            $this->sendError([
                'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                'message' => esc_html__('Steps index out of range after customizer import.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ]);
    }

    public function importWidget()
    {
        $this->startBuffer();

        $step = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'widgets-import';
        $step = preg_replace('/-import$/', '', $step); // "widgets-import" → "widgets"
        $this->verifyNonce($_REQUEST['nonce'], "import-{$step}");

        $slug  = sanitize_text_field($_REQUEST['slug']);
        $files = aarambha_ds_sanitize_text_or_array_field($_REQUEST['files']);
        $steps = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index = absint($_REQUEST['stepsIndex']) + 1;

        if (!empty($files['widgets'])) {
            $demosDir = aarambha_ds_get_demos_dir($slug);
            $file     = wp_normalize_path("{$demosDir}/{$files['widgets']}");

            if (file_exists($file)) {
                Aarambha_DS()->core()->widgets($file);
            }
        }

        $nextStep = $this->nextStep($steps, $index);

        if (null === $nextStep) {
            $this->sendError([
                'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                'message' => esc_html__('Steps index out of range after widget import.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
            'files'  => $files,
        ]);
    }

    public function importSlider()
    {
        $this->startBuffer();

        $step = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'slider-import';
        $step = preg_replace('/-import$/', '', $step); // "slider-import" → "slider"
        $this->verifyNonce($_REQUEST['nonce'], "import-{$step}");

        $slug  = sanitize_text_field($_REQUEST['slug']);
        $files = aarambha_ds_sanitize_text_or_array_field($_REQUEST['files']);
        $steps = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index = absint($_REQUEST['stepsIndex']) + 1;

        $demosDir = aarambha_ds_get_demos_dir($slug);
        $sliders  = isset($files['slider']) ? $files['slider'] : [];

        if (class_exists('SmartSlider3') && is_array($sliders) && count($sliders) > 0) {
            require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-smart-slider.php';
            Aarambha_DS_Smart_Slider::delete();

            foreach ($sliders as $slider) {
                $file = wp_normalize_path("{$demosDir}/{$slider['file']}");
                Aarambha_DS()->core()->slider($file);
            }
        }

        $nextStep = $this->nextStep($steps, $index);

        if (null === $nextStep) {
            $this->sendError([
                'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                'message' => esc_html__('Steps index out of range after slider import.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
        ]);
    }

    public function importMenu()
    {
        $this->startBuffer();

        $step = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'menu-import';
        $step = preg_replace('/-import$/', '', $step); // "menu-import" → "menu"
        $this->verifyNonce($_REQUEST['nonce'], "import-{$step}");

        $slug       = sanitize_text_field($_REQUEST['slug']);
        $demo       = Aarambha_DS()->demo($slug);
        $navigation = aarambha_ds_sanitize_text_or_array_field($demo['menus']);

        Aarambha_DS()->core()->setupNavigation($navigation);

        $steps    = aarambha_ds_sanitize_text_or_array_field($_REQUEST['steps']);
        $index    = absint($_REQUEST['stepsIndex']) + 1;
        $nextStep = $this->nextStep($steps, $index);

        if (null === $nextStep) {
            $this->sendError([
                'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                'message' => esc_html__('Steps index out of range after menu import.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
        ]);
    }

    public function importPages()
    {
        $this->startBuffer();

        $step = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'pages-import';
        $step = preg_replace('/-import$/', '', $step); // "pages-import" → "pages"
        $this->verifyNonce($_REQUEST['nonce'], "import-{$step}");

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
        $nextStep = $this->nextStep($steps, $index);

        if (null === $nextStep) {
            $this->sendError([
                'title'   => esc_html__('Import Error', 'aarambha-demo-sites'),
                'message' => esc_html__('Steps index out of range after pages import.', 'aarambha-demo-sites'),
            ]);
        }

        $this->sendSuccess([
            'nonce'  => wp_create_nonce("import-{$nextStep}"),
            'action' => $nextStep,
            'steps'  => $steps,
        ]);
    }

    public function finalize()
    {
        $this->startBuffer();

        // Nonce was created in the previous step as wp_create_nonce("import-finalize")
        // because $nextStep = "finalize" (the short step name, no "-import" suffix).
        $this->verifyNonce($_REQUEST['nonce'], 'import-finalize');

        if (function_exists('WC')) {
            $this->completeWooCommerceWizard();
        }

        do_action('aarambha_ds_after_demo_imported');
        flush_rewrite_rules(true);

        $this->sendSuccess(['action' => 'finalized']);
    }

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