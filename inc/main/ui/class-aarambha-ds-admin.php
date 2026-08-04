<?php

/**
 * Handles the UI generation part
 * 
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core/UI
 */

if (!defined('WPINC')) {
    exit;    // Exit if accessed directly.
}

/**
 * Class Aarambha_DS_Admin
 * 
 * Handles the creation of admin page and its user interfaces.
 */
class Aarambha_DS_Admin
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
     * Page slug.
     * 
     * @since 1.0.0
     * @access protected
     * 
     * @var string
     */
    protected $page = '';

    /**
     * Creates the Admin page and handles importer UI stuffs.
     *
     * @class Aarambha_DS_Admin
     * 
     * @version 1.0.0
     * @since 1.0.0
     * 
     * @return object Aarambha_DS_Admin
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }

        return self::$instance;
    }

    /**
     * A dummy constructor to prevent this class from being loaded more than once.
     *
     * @see Aarambha_DS_Admin::getInstance()
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
     * Initialize the Aarambha_DS_Admin
     */
    private function init()
    {
        add_action('admin_menu', [$this, 'createAdminMenu']);
        add_action('admin_footer', [$this, 'renderTemplates']);
        add_action('admin_notices', [$this, 'phpRequirementsNotice']);

        add_action('admin_init', [$this, 'onAdminInit']);
    }

    /**
     * Display an admin notice if the server's PHP settings fall below
     * the recommended values for running the demo importer.
     *
     * @since 1.0.0
     * @return void
     */
    public function phpRequirementsNotice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();

        // Only show on the plugin's own admin page.
        if (!$screen || $this->page !== $screen->id) {
            return;
        }

        $requirements = [
            'max_execution_time' => [
                'label'       => esc_html__('Max Execution Time', 'aarambha-demo-sites'),
                'recommended' => 1200,
                'current'     => (int) ini_get('max_execution_time'),
                'unit'        => esc_html__('s', 'aarambha-demo-sites'),
            ],
            'max_input_time' => [
                'label'       => esc_html__('Max Input Time', 'aarambha-demo-sites'),
                'recommended' => 600,
                'current'     => (int) ini_get('max_input_time'),
                'unit'        => esc_html__('s', 'aarambha-demo-sites'),
            ],
            'memory_limit' => [
                'label'       => esc_html__('Memory Limit', 'aarambha-demo-sites'),
                'recommended' => 512,
                'current'     => (int) (wp_convert_hr_to_bytes(ini_get('memory_limit')) / MB_IN_BYTES),
                'unit'        => 'M',
            ],
        ];

        $unmet = [];

        foreach ($requirements as $key => $req) {
            // -1 means "unlimited" for execution/input time — treat as satisfied.
            if (-1 === $req['current']) {
                continue;
            }

            if ($req['current'] < $req['recommended']) {
                $unmet[$key] = $req;
            }
        }

        if (empty($unmet)) {
            return;
        }
?>
        <div class="notice notice-warning is-dismissible aarambha-ds-php-requirements-notice">
            <p>
                <strong><?php esc_html_e('Demo Importer: Server Configuration Warning', 'aarambha-demo-sites'); ?></strong>
            </p>
            <p>
                <?php esc_html_e('Your server\'s current PHP settings may cause the demo import to fail or time out. Recommended minimum values:', 'aarambha-demo-sites'); ?>
            </p>
            <table class="widefat" style="max-width: 600px; margin-bottom: 10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Setting', 'aarambha-demo-sites'); ?></th>
                        <th><?php esc_html_e('Current Value', 'aarambha-demo-sites'); ?></th>
                        <th><?php esc_html_e('Recommended', 'aarambha-demo-sites'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unmet as $req) : ?>
                        <tr>
                            <td><?php echo esc_html($req['label']); ?></td>
                            <td style="color: #d63638; font-weight: bold;">
                                <?php echo esc_html($req['current'] . $req['unit']); ?>
                            </td>
                            <td><?php echo esc_html($req['recommended'] . $req['unit']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <?php
                printf(
                    /* translators: %s: link to WordPress wp-config editing guide */
                    esc_html__('Please ask your hosting provider to increase these values, or update them yourself. %s', 'aarambha-demo-sites'),
                    '<a href="https://wordpress.org/support/article/editing-wp-config-php/" target="_blank" rel="noopener noreferrer">' . esc_html__('Learn how', 'aarambha-demo-sites') . '</a>'
                );
                ?>
            </p>
        </div>
<?php
    }

    /**
     * When admin init runs.
     * https://yoursite.com/wp-admin/admin.php?page=your-plugin-page&_clear=cache
     */
    public function onAdminInit()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['_clear']) && 'cache' === sanitize_text_field($_GET['_clear'])) {
            $this->deleteCache();
        }
    }

    /**
     * Delete the cache.
     */
    private function deleteCache()
    {
        global $wpdb;
        $table = $wpdb->options;
        $query = "SELECT * FROM {$table} WHERE `option_name` LIKE '%_aarambha_ds_%'";
        $results = $wpdb->get_results($query);

        if (!$results) {
            return;
        }

        if (is_array($results) && count($results) > 0) {
            foreach ($results as $result) {
                $wpdb->delete($table, ['option_id' => $result->option_id]);
            }
        }
    }

    /**
     * Hooked into 'admin_menu' to register the main page.
     */
    public function createAdminMenu()
    {
        $args = Aarambha_DS()->getAdminPageArgs();

        if ('submenu' === $args['menu_type']) {

            $page = add_submenu_page(
                $args['parent'],
                $args['title'],
                $args['menu_name'],
                'manage_options',
                $args['slug'],
                [$this, 'renderAdminPage'],
                $args['position']
            );
        } else if ('menu' === $args['menu_type']) {

            $page = add_menu_page(
                $args['title'],
                $args['menu_name'],
                'manage_options',
                $args['slug'],
                [$this, 'renderAdminPage'],
                $args['icon'],
                $args['position']
            );
        }

        $this->page = $page;

        add_action('admin_enqueue_scripts', [$this, 'enqueueScriptsStyles']);
    }


    /**
     * Render the admin page.
     * 
     * @since 1.0.0
     * @return void.
     */
    public function renderAdminPage()
    {
        $data = [
            'categories' => Aarambha_DS()->api()->categories(),
            'demos' => Aarambha_DS()->api()->demos()
        ];

        Aarambha_DS()->view('body', $data);
    }

    // -------------------------------------------------------------------------
    // Asset helpers
    // -------------------------------------------------------------------------

    /**
     * Register a JS script using constants defined in constant.php.
     *
     * Looks for an optional `{filename}.asset.php` sidecar file produced by
     * @wordpress/scripts / webpack.  If the sidecar exists its `dependencies`
     * and `version` values are used; otherwise the supplied $deps / $ver
     * (or AARAMBHA_DS_VERSION as a final fallback) are used instead.
     *
     * @param string   $handle    Unique script handle.
     * @param string   $filename  File name relative to AARAMBHA_DS_JS,
     *                            WITHOUT the .js extension.
     * @param string[] $deps      Fallback dependencies (used when no asset file).
     * @param string|null $ver    Fallback version (uses AARAMBHA_DS_VERSION when null).
     * @param bool     $in_footer Whether to enqueue before </body>.
     *
     * @return bool True on success, false when the JS file does not exist.
     */
    private function register_script(
        string $handle,
        string $filename,
        array  $deps      = [],
        ?string $ver      = null,
        bool   $in_footer = true
    ): bool {

        // Resolve absolute paths and public URLs from the plugin constants.
        $js_dir  = wp_normalize_path(AARAMBHA_DS_ROOT . 'assets/build/js/');
        $js_url  = AARAMBHA_DS_JS;   // URL constant already ends with /

        $js_file = $js_dir . $filename . '.js';

        // The JS file must exist.
        if (! file_exists($js_file)) {
            return false;
        }

        // Optional webpack asset sidecar file.
        $asset_file = $js_dir . $filename . '.asset.php';

        if (file_exists($asset_file)) {
            // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
            $asset = require $asset_file;
            $deps  = ! empty($deps) ? $deps : ($asset['dependencies'] ?? []);
            $ver   = $ver ?? ($asset['version'] ?? AARAMBHA_DS_VERSION);
        } else {
            $ver = $ver ?? AARAMBHA_DS_VERSION;
        }

        return wp_register_script(
            $handle,
            $js_url . $filename . '.js',
            $deps,
            $ver,
            $in_footer
        );
    }

    /**
     * Register a CSS stylesheet using constants defined in constant.php.
     *
     * @param string   $handle   Unique stylesheet handle.
     * @param string   $filename File name relative to AARAMBHA_DS_CSS,
     *                           WITHOUT the .css extension.
     * @param string[] $deps     Stylesheet dependencies.
     * @param string|null $ver   Version string; falls back to AARAMBHA_DS_VERSION.
     * @param string   $media    Media type / query (default 'all').
     *
     * @return bool True on success, false when the CSS file does not exist.
     */
    private function register_style(
        string $handle,
        string $filename,
        array  $deps  = [],
        ?string $ver  = null,
        string $media = 'all'
    ): bool {

        $css_dir  = wp_normalize_path(AARAMBHA_DS_ROOT . 'assets/build/css/');
        $css_url  = AARAMBHA_DS_CSS;  // URL constant already ends with /

        $css_file = $css_dir . $filename . '.css';

        if (! file_exists($css_file)) {
            return false;
        }

        $ver = $ver ?? AARAMBHA_DS_VERSION;

        return wp_register_style(
            $handle,
            $css_url . $filename . '.css',
            $deps,
            $ver,
            $media
        );
    }

    /**
     * Enqueue styles and scripts.
     * 
     * @since 1.0.0
     * @return void
     */
    public function enqueueScriptsStyles($hook)
    {
        $slug = Aarambha_DS()->getSlug();

        if ($this->page === $hook) {

            // Enqueue styles.
            wp_enqueue_style(
                'sweetalert2',
                AARAMBHA_DS_LIBRARY . 'sweetalert2.css',
                [],
                '11.10.1',
                'all'
            );

            // Plugin admin stylesheet (RTL-aware via AARAMBHA_DS_RTL).
            $admin_css = 'admin' . AARAMBHA_DS_RTL;
            if ($this->register_style($slug, $admin_css)) {
                wp_enqueue_style($slug);
            }

            // Enqueue scripts.
            wp_enqueue_script(
                "sweetalert2",
                AARAMBHA_DS_LIBRARY . 'sweetalert2.js',
                [],
                '11.10.1',
                true
            );
            if ($this->register_script($slug, 'admin', ['jquery', 'wp-util', 'updates'])) {

                $theme       =  get_stylesheet();
                $licenseSlug = "{$theme}-license";
                $licenseUrl  = admin_url("themes.php?page={$licenseSlug}");

                // Localize strings.
                $default = [
                    'nonce'          => wp_create_nonce(),
                    // AFTER — one nonce per action, each matches its handler's verify call
                    'nonces' => [
                        'retrieveDemo'  => wp_create_nonce('retrieve-demo'),
                        'listPlugins'   => wp_create_nonce('list-plugins'),
                        'prepareImport' => wp_create_nonce('prepare-import'),
                        'importContent' => wp_create_nonce('import-content'),
                    ],
                    'themeName'      => aarambha_ds_get_theme_name(),
                    'offlineTitle'   => esc_html__('You\'re Offline!', 'aarambha-demo-sites'),
                    'purchaseLabel'  => esc_html__('Purchase Now', 'aarambha-demo-sites'),
                    'previewLabel'   => esc_html__('Preview', 'aarambha-demo-sites'),
                    'loadingText'    => esc_html__('Please Wait!', 'aarambha-demo-sites'),
                    'installPlugins' => esc_html__('Install Plugins', 'aarambha-demo-sites'),
                    'importContent'  => esc_html__('Import Content', 'aarambha-demo-sites'),
                    'installing'     => esc_html__('Installing &#8230;', 'aarambha-demo-sites'),
                    'activating'     => esc_html__('Activating &#8230;', 'aarambha-demo-sites'),
                    'active'         => esc_html__('Active', 'aarambha-demo-sites'),
                    'failedTitle'    => esc_html__('Sorry!', 'aarambha-demo-sites'),
                    'activateLink'   => esc_url($licenseUrl),
                    'offlineMsg'     => esc_html__(
                        'We cannot import now. Please try again later!',
                        'aarambha-demo-sites'
                    ),
                    'ajaxUrl'        => admin_url('admin-ajax.php'),
                    'tryAgain'       => esc_html__(
                        'Refresh the page, and try again!',
                        'aarambha-demo-sites'
                    ),
                    'content'        => esc_html__(
                        'Importing Content&#8230;',
                        'aarambha-demo-sites'
                    ),
                    'customizer'     => esc_html__(
                        'Importing Customize Information &#8230;',
                        'aarambha-demo-sites'
                    ),
                    'widgets'        => esc_html__(
                        'Importing Widgets &#8230;',
                        'aarambha-demo-sites'
                    ),
                    'slider'         => esc_html__(
                        'Importing Slider &#8230;',
                        'aarambha-demo-sites'
                    ),
                    'failed'         => esc_html__(
                        'Something Went Wrong!',
                        'aarambha-demo-sites'
                    ),
                    'prepare'        => esc_html__(
                        'Preparing to import &#8230;',
                        'aarambha-demo-sites'
                    ),
                    'menu'           => esc_html__(
                        'Setting Menus &#8230;',
                        'aarambha-demo-sites'
                    ),
                    'pages'          => esc_html__(
                        'Setting Pages &#8230;',
                        'aarambha-demo-sites'
                    ),
                    'finalize'       => esc_html__(
                        'Finalizing the Import &#8230;',
                        'aarambha-demo-sites'
                    ),
                ];

                $user_args = apply_filters('aarambha_ds_localize_data', []);
                $args      = wp_parse_args($user_args, $default);

                wp_localize_script($slug, 'aarambhaDSData', $args);
                wp_enqueue_script($slug);
            }
        }
    }

    /**
     * Render popup templates.
     * 
     * @since 1.0.0
     * @return void
     */
    public function renderTemplates()
    {
        $currentScreen = get_current_screen();

        if ($this->page === $currentScreen->id) {
            Aarambha_DS()->view('popups/activate-theme');
            Aarambha_DS()->view('popups/failed');
            Aarambha_DS()->view('popups/purchase-theme');
            Aarambha_DS()->view('popups/information');
            Aarambha_DS()->view('import');
            Aarambha_DS()->view('importing');
            Aarambha_DS()->view('complete');
        }
    }
}
