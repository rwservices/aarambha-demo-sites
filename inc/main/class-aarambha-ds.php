<?php

/**
 * The methods defined here is run during this plugin activation.
 * 
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core
 */

if (!defined('WPINC')) {
    exit;    // Exit if accessed directly.
}

/**
 * Class Aarambha_DS
 * 
 * Core file of the plugin.
 */

final class Aarambha_DS
{
    /**
     * The single class instance.
     *
     * @since 1.0.0
     * @access private
     *
     * @var object
     */
    private static $instance = null;

    /**
     * API Url for demo data.
     * 
     * @since 1.0.0
     * @access private
     * 
     * @var string
     */
    private $apiUrl = '';

    /**
     * Admin page arguments.
     * 
     * @since 1.0.0
     * @access private
     * 
     * @var array
     */
    private $adminPageArgs = [];

    /**
     * Whether the WordPress Importer plugin is available.
     *
     * @since 1.0.0
     * @access private
     *
     * @var bool
     */
    private $hasWpImporter = false;

    /**
     * Main Aarambha_DS Instance
     *
     * Ensures only one instance of this class exists in memory at any one time.
     *
     * @since 1.0.0
     * @static
     * @return Aarambha_DS
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();

            self::$instance->initGlobals();
            self::$instance->includeCoreFiles();
            self::$instance->runActions();
        }

        return self::$instance;
    }

    /**
     * A dummy constructor to prevent this class from being loaded more than once.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct()
    {
        /* We do nothing here! */
    }

    /**
     * You cannot clone this class.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?', 'aarambha-demo-sites'), '1.0.0');
    }

    /**
     * You cannot unserialize instances of this class.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?', 'aarambha-demo-sites'), '1.0.0');
    }

    /**
     * Initialize the plugin globals.
     */
    private function initGlobals()
    {
        $default_url = AARAMBHA_DS_API_URL;
        $url  = apply_filters('aarambha_ds_api_url', $default_url);

        $args = apply_filters('aarambha_ds_admin_page_args', [
            'menu_type' => 'menu',
            'slug'      => 'aarambha-ds',
            'menu_name' => esc_html__('Import Demo', 'aarambha-demo-sites'),
            'title'     => esc_html__('Import Demo', 'aarambha-demo-sites'),
            'icon'      => false,
            'parent'    => false,
            'position'  => 90,
        ]);

        $this->apiUrl        = $url;
        $this->adminPageArgs = $args;
    }

    /**
     * Loads all the core files.
     */
    private function includeCoreFiles()
    {
        require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-api.php';
        require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-ajax.php';
        require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-plugins.php';
        require_once AARAMBHA_DS_CLASSES . 'class-aarambha-ds-core.php';

        /* Include admin UI */
        require_once AARAMBHA_DS_UI . 'class-aarambha-ds-admin.php';

        // Load official WordPress Importer safely.
        $this->loadWordPressImporter();
    }

    /**
     * Attempt to load the WordPress Importer plugin class.
     */
    private function loadWordPressImporter()
    {
        // Define WP_LOAD_IMPORTERS if not already set, so the importer plugin doesn't early-return.
        if (!defined('WP_LOAD_IMPORTERS')) {
            define('WP_LOAD_IMPORTERS', true);
        }

        if (class_exists('WP_Import')) {
            $this->hasWpImporter = true;
            return;
        }

        $importer_plugin = 'wordpress-importer/wordpress-importer.php';

        // Check if the importer plugin is available.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active($importer_plugin)) {
            return;
        }

        // Load base WP_Importer class if it doesn't exist.
        if (!class_exists('WP_Importer')) {
            $base_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
            if (file_exists($base_importer)) {
                require_once $base_importer;
            }
        }

        $plugin_base = WP_PLUGIN_DIR . '/wordpress-importer';

        // Load parser files BEFORE loading class-wp-import.php so WXR_Parser is available.
        $parsers_file = $plugin_base . '/parsers.php';
        if (file_exists($parsers_file)) {
            require_once $parsers_file;
        }

        // Now load the importer class.
        $class_file = $plugin_base . '/class-wp-import.php';
        if (file_exists($class_file)) {
            require_once $class_file;
            $this->hasWpImporter = class_exists('WP_Import');
        }
    }

    /**
     * Fires the actions & filters.
     * 
     * @since 1.0.0
     */
    private function runActions()
    {
        add_action('init',           [$this, 'loadTextdomain']);
        add_action('plugins_loaded', [$this, 'pluginsLoaded']);
        add_action('init',           [$this, 'ajax']);
    }

    /**
     * Make plugin available for translation.
     */
    public function loadTextdomain()
    {
        load_plugin_textdomain('aarambha-demo-sites', false, AARAMBHA_DS_LANGUAGES);
    }

    /**
     * Runs during the plugin load.
     */
    public function pluginsLoaded()
    {
        $activeTheme  = aarambha_ds_get_theme();
        $authorThemes = get_site_transient('aarambha_ds_author_themes');

        if (!$authorThemes) {
            $authorThemes = $this->api()->themes();
        }

        if (
            !in_array($activeTheme, $authorThemes) &&
            AARAMBHA_DS_AUTHOR !== aarambha_ds_get_theme_author()
        ) {
            add_action('admin_notices', [$this, 'aarambha_ds_print_admin_notice']);
        } else {
            $this->admin();
        }

        // For testing purposes — remove in production if not needed.
        $this->admin();
    }

    /**
     * Displays the admin notice when theme is incompatible.
     */
    public function aarambha_ds_print_admin_notice()
    {
        echo sprintf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            sprintf(
                /* translators: 1: author URL, 2: author name, 3: plugin name */
                __('You need to have one of the themes from <a href="%1$s" target="_blank">%2$s</a> installed to use <strong>%3$s</strong> plugin', 'aarambha-demo-sites'),
                esc_url(AARAMBHA_DS_AUTHOR_URI),
                ucfirst(AARAMBHA_DS_AUTHOR),
                AARAMBHA_DS_PLUGIN_NAME
            )
        );
    }

    /**
     * Includes a view file with optional data.
     *
     * @since 1.0.0
     */
    public function view($view, $data = [])
    {
        try {
            include AARAMBHA_DS_VIEWS . "{$view}.php";
        } catch (Exception $e) {
            echo esc_html($e->getMessage());
        }
    }

    /**
     * Returns the API class instance.
     * 
     * @since 1.0.0
     * @return Aarambha_DS_API
     */
    public function api()
    {
        return Aarambha_DS_API::getInstance();
    }

    /**
     * Returns the Plugins helper class instance.
     *
     * @return Aarambha_DS_Plugins
     */
    public function plugins()
    {
        return Aarambha_DS_Plugins::getInstance();
    }

    /**
     * Returns the Core importer class instance.
     * This is the class that performs content/customizer/widget/slider imports.
     *
     * @return Aarambha_DS_Core
     */
    public function core()
    {
        return Aarambha_DS_Core::getInstance();
    }

    /**
     * Initialises the AJAX handler.
     * 
     * @since 1.0.0
     */
    public function ajax()
    {
        Aarambha_DS_Ajax::getInstance();
    }

    /**
     * Generates the Admin pages and UI for the importer.
     * 
     * @since 1.0.0
     */
    public function admin()
    {
        Aarambha_DS_Admin::getInstance();
    }

    /**
     * Returns the raw API URL.
     *
     * @return string
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    /**
     * Returns the full URL to the plugin's admin page.
     * 
     * @return string
     */
    public function getPageUrl()
    {
        $args     = $this->adminPageArgs;
        $menuType = $args['menu_type'];
        $slug     = $args['slug'];
        $parent   = 'admin.php';

        if ('submenu' === $menuType) {
            $parent = sanitize_text_field($args['parent']);
        }

        return add_query_arg(['page' => sanitize_key($slug)], admin_url($parent));
    }

    /**
     * Returns the admin page arguments array.
     *
     * @return array
     */
    public function getAdminPageArgs()
    {
        return $this->adminPageArgs;
    }

    /**
     * Returns the admin page slug.
     * 
     * @return string
     */
    public function getSlug()
    {
        return ($this->adminPageArgs)['slug'];
    }

    /**
     * Returns a fresh Aarambha_WP_Import instance (the low-level WXR importer),
     * or false when the WordPress Importer plugin is not available.
     *
     * Use Aarambha_DS()->core() to access higher-level import methods
     * (content, customizer, widgets, slider, setupNavigation).
     *
     * @return Aarambha_WP_Import|false
     */
    public function importer()
    {
        if (!$this->hasWpImporter) {
            if (current_user_can('manage_options')) {
                add_action('admin_notices', [$this, 'importerMissingNotice']);
            }
            return false;
        }

        if (!class_exists('Aarambha_WP_Import')) {
            require_once AARAMBHA_DS_CLASSES . 'class-aarambha-wp-import.php';
        }

        return new Aarambha_WP_Import();
    }

    /**
     * Admin notice shown when WordPress Importer plugin is missing.
     */
    public function importerMissingNotice()
    {
        echo '<div class="notice notice-error is-dismissible">
            <p><strong>Aarambha Demo Sites</strong> requires the external
            <a href="' . esc_url(admin_url('plugin-install.php?tab=plugin-information&plugin=wordpress-importer')) . '">WordPress Importer</a>
            plugin to be installed and activated before demo content can be imported.</p>
        </div>';
    }

    /**
     * Whether the WordPress Importer plugin is available.
     *
     * @return bool
     */
    public function hasWpImporter()
    {
        return $this->hasWpImporter;
    }

    /**
     * Returns the cached demo data from the transient store.
     * 
     * @param  string $slug Demo slug.
     * @return array|false  Demo data array, or false on cache miss.
     */
    public function demo($slug)
    {
        if (!$slug) {
            return false;
        }

        $theme         = aarambha_ds_get_theme();
        $key           = "aarambha_ds_{$theme}_demo_{$slug}";
        $transientData = get_site_transient($key);

        if (!$transientData) {
            return false;
        }

        return $transientData['data'];
    }
}
