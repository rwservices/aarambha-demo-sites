<?php

/**
 * Prepares the plugin data.
 * 
 * @since       1.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Core/UI
 */

if (!defined('WPINC')) {
    exit;    // Exit if accessed directly.
}

/**
 * Class Aarambha_DS_Plugins
 * 
 * Plugin installer.
 */
class Aarambha_DS_Plugins
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
     * Free & Pro plugin data for the current demo.
     * 
     * @since 1.0.0
     * @access private
     * 
     * @var array
     */
    private $plugins = [];

    /**
     * Ensures only one instance of this class is available.
     * 
     * @since 1.0.0
     * @return Aarambha_DS_Plugins
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->actions();
        }

        return self::$instance;
    }

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
     * Register WordPress hooks.
     */
    public function actions()
    {
        add_filter('plugins_api', [$this, 'pluginsApi'], 10, 3);
    }

    /**
     * Stores demo plugin data and populates the pro-plugins transient.
     *
     * @param  array $demo Demo data array from the API.
     * @return Aarambha_DS_Plugins  Fluent interface.
     */
    public function runtime($demo)
    {
        $plugins   = $demo['plugins'];
        $this->plugins = $plugins;

        $demo_name = $demo['slug'];

        $transient = get_site_transient('aarambha_ds_plugins');

        if (!$transient || !is_object($transient)) {
            $transient          = new stdClass();
            $transient->plugins = [];
        }

        if (isset($plugins['pro'])) {
            $proPlugins = $plugins['pro'];
            $pluginsArr = [];

            foreach ($proPlugins as $plugin) {
                $pluginCoreFile = $plugin['coreFile'];
                $slug           = $plugin['plugin'];
                $file           = $plugin['plugin_file'];

                if (!in_array($pluginCoreFile, $plugins, true)) {
                    $pluginObj               = new stdClass();
                    $pluginObj->name         = $plugin['name'];
                    $pluginObj->version      = $plugin['version'];
                    $pluginObj->slug         = $slug;
                    $pluginObj->plugin       = $pluginCoreFile;
                    $pluginObj->fileName     = $file;
                    $pluginObj->demo         = $demo_name;
                    $pluginObj->url          = $demo['preview'];
                    $pluginObj->download_link = Aarambha_DS()->api()->deferredDownload(
                        compact('slug', 'demo_name')
                    );

                    $pluginsArr[] = $pluginObj;
                }
            }

            $transient->plugins = $pluginsArr;
        }

        set_site_transient('aarambha_ds_plugins', $transient, WEEK_IN_SECONDS);

        return $this;
    }

    /**
     * Ensures is_plugin_active() is available.
     */
    public function inject()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /**
     * Ensures the plugin upgrader classes are available.
     */
    public function injectInstaller()
    {
        if (!class_exists('WP_Upgrader', false)) {
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        if (!function_exists('plugins_api')) {
            include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
    }

    /**
     * Checks whether a plugin is installed (i.e. its main file exists on disk).
     *
     * @param  string $pluginFile Relative path: folder/plugin-file.php
     * @return bool
     */
    public function isInstalled($pluginFile = '')
    {
        if ('' === $pluginFile) {
            return false;
        }

        return file_exists(WP_PLUGIN_DIR . "/{$pluginFile}");
    }

    /**
     * Checks whether a plugin is currently active.
     *
     * @param  string $pluginFile Relative path: folder/plugin-file.php
     * @return bool
     */
    public function isActive($pluginFile)
    {
        if (empty($pluginFile)) {
            return false;
        }

        $this->inject();

        return is_plugin_active($pluginFile);
    }

    /**
     * Generates the plugin-list HTML and decides the next wizard step.
     *
     * @return array  ['step' => string, 'html' => string]
     */
    public function html()
    {
        $free    = isset($this->plugins['free']) ? $this->plugins['free'] : [];
        $pro     = isset($this->plugins['pro'])  ? $this->plugins['pro']  : [];
        $plugins = array_merge($free, $pro);
        $total   = count($plugins);

        $activeLists = [];

        foreach ($plugins as $plugin) {
            if ($this->isActive($plugin['coreFile'])) {
                $activeLists[] = $plugin['coreFile'];
            }
        }

        $status = [];

        if (count($activeLists) === $total) {
            $status['step'] = 'import';
        } else {
            $status['step'] = 'install-plugin';
        }

        ob_start();

        if (count($activeLists) !== $total) {
            Aarambha_DS()->view('plugins-list', $this->plugins);
        }

        $status['html'] = ob_get_clean();

        return $status;
    }

    /**
     * Activates an installed plugin.
     *
     * @param  string $plugin Relative plugin path (folder/file.php).
     * @return bool
     */
    public function activate($plugin)
    {
        $this->inject();

        if (!$this->isInstalled($plugin)) {
            return false;
        }

        $status = activate_plugin($plugin);

        // activate_plugin() returns null on success, WP_Error on failure.
        return !is_wp_error($status);
    }

    /**
     * Installs a plugin from the WordPress.org repository via AJAX.
     *
     * @param  string $slug Plugin slug.
     * @return array  Result array with 'success' key.
     */
    public function ajaxInstall($slug)
    {
        $status = [];

        if (empty($slug)) {
            $status['success'] = false;
            return $status;
        }

        $this->injectInstaller();

        $api = plugins_api(
            'plugin_information',
            [
                'slug'   => sanitize_key(wp_unslash($slug)),
                'fields' => ['sections' => false],
            ]
        );

        if (is_wp_error($api)) {
            $status['success']      = false;
            $status['errorMessage'] = $api->get_error_message();
            return $status;
        }

        $status['pluginName'] = $api->name;

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result   = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            $status['success']      = false;
            $status['errorCode']    = $result->get_error_code();
            $status['errorMessage'] = $result->get_error_message();
            return $status;
        }

        if (is_wp_error($skin->result)) {
            $status['success']      = false;
            $status['errorCode']    = $skin->result->get_error_code();
            $status['errorMessage'] = $skin->result->get_error_message();
            return $status;
        }

        if ($skin->get_errors()->has_errors()) {
            $status['success']      = false;
            $status['errorMessage'] = $skin->get_error_messages();
            return $status;
        }

        if (is_null($result)) {
            global $wp_filesystem;

            $status['success']   = false;
            $status['errorCode'] = 'unable_to_connect_to_filesystem';
            $status['errorMessage'] = __('Unable to connect to the filesystem. Please confirm your credentials.', 'aarambha-demo-sites');

            if ($wp_filesystem instanceof WP_Filesystem_Base && is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->has_errors()) {
                $status['errorMessage'] = esc_html($wp_filesystem->errors->get_error_message());
            }

            return $status;
        }

        $status['success'] = true;

        return $status;
    }

    /**
     * Hooks into plugins_api to supply download links for pro plugins.
     *
     * FIX: previously assumed the transient always existed and was a valid object,
     * causing a fatal error when no pro plugins were registered. Now guards against
     * a missing or malformed transient and an empty plugins list.
     *
     * @param  mixed  $response  Current response.
     * @param  string $action    API action.
     * @param  object $args      API arguments.
     * @return mixed  Modified (or unchanged) response.
     */
    public function pluginsApi($response, $action, $args)
    {
        if ('plugin_information' !== $action || !isset($args->slug)) {
            return $response;
        }

        $proPlugins = get_site_transient('aarambha_ds_plugins');
        $theme      = aarambha_ds_get_theme();

        // Guard: transient may not exist yet, or may not have a plugins property.
        if (
            !$proPlugins ||
            !is_object($proPlugins) ||
            empty($proPlugins->plugins) ||
            !is_array($proPlugins->plugins)
        ) {
            return $response;
        }

        foreach ($proPlugins->plugins as $plugin) {
            if ($plugin->slug === $args->slug) {
                $slug = $plugin->slug;
                $demo = $plugin->demo;

                $response                = new stdClass();
                $response->id            = str_replace('-', '_', $slug);
                $response->slug          = $slug;
                $response->plugin_name   = $plugin->name;
                $response->name          = $plugin->name;
                $response->new_version   = $plugin->version;
                $response->download_link = Aarambha_DS()->api()->download(
                    compact('theme', 'slug', 'demo')
                );

                break;
            }
        }

        return $response;
    }
}
