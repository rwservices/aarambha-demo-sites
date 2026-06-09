<?php

/**
 * Aarambha Demo Sites Core.
 *
 * This class handles:
 * 1. Downloads the required demo files.
 * 2. Imports Content (using official WP_Import via Aarambha_WP_Import)
 * 3. Imports Customizer settings.
 * 4. Imports Widgets.
 * 5. Imports Sliders.
 * 6. Sets up navigation menus.
 *
 * @since 1.0.0
 */

if (!defined('WPINC')) {
    exit;
}

/**
 * Class Aarambha_DS_Core
 */
class Aarambha_DS_Core
{
    /**
     * Single class instance.
     *
     * @since 1.0.0
     * @var Aarambha_DS_Core|null
     */
    private static $instance = null;

    /**
     * Ensures only one instance of this class is available.
     *
     * @return Aarambha_DS_Core
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
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

    // -------------------------------------------------------------------------
    // Preparation
    // -------------------------------------------------------------------------

    /**
     * Prepares the import: creates the demo directory and downloads all files.
     *
     * @param  array  $demo Demo data array from the API.
     * @return array  Map of file-type keys to downloaded filenames.
     */
    public function prepare($demo)
    {
        $slug  = $demo['slug'];
        $dir   = $this->createDir($slug);
        $files = $this->download($demo, $dir);

        return $files;
    }

    /**
     * Creates the directory that will hold the demo's import files.
     *
     * @param  string $slug Demo slug.
     * @return string Absolute path to the directory.
     */
    public function createDir($slug)
    {
        $base = aarambha_ds_get_custom_uploads_dir();
        $path = "{$base}/{$slug}";

        if (!file_exists($path)) {
            $result = wp_mkdir_p(trailingslashit($path));
            if (!$result) {
                error_log(sprintf('[Aarambha DS] Failed to create directory: %s', $path));
            }
        }

        return $path;
    }

    /**
     * Downloads all demo files declared in $demo['files'].
     *
     * @param  array  $demo Demo data array.
     * @param  string $dir  Absolute path to the target directory.
     * @return array  Map of file-type keys to filenames that were successfully written.
     */
    public function download($demo, $dir)
    {
        $args = [
            'theme' => $demo['theme'] ?? 'neostore',
            'demo'  => $demo['slug'],
        ];

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $files = [];

        if (empty($demo['files'])) {
            return $files;
        }

        foreach ($demo['files'] as $key => $file) {
            if (empty($file)) {
                continue;
            }

            if (is_array($file)) {
                foreach ($file as $filename) {
                    $this->processSingleFile($filename['file'], $dir, $args, $files, $key);
                }
            } else {
                $this->processSingleFile($file, $dir, $args, $files, $key);
            }
        }

        return $files;
    }

    /**
     * Downloads and saves a single file to disk.
     *
     * @param string $filename Base filename to download.
     * @param string $dir      Absolute path to the target directory.
     * @param array  $args     Query args passed to the download URL builder.
     * @param array  &$files   Reference to the collected files map.
     * @param string $key      File-type key (e.g. 'content', 'customizer').
     */
    private function processSingleFile($filename, $dir, $args, &$files, $key)
    {
        $file_name = "{$dir}/{$filename}";

        // Skip if already downloaded.
        if (file_exists($file_name)) {
            $files[$key] = $filename;
            return;
        }

        $args['file'] = $filename;
        $url          = Aarambha_DS()->api()->downloadUrl($args);
        $download     = download_url($url);

        if (is_wp_error($download)) {
            error_log(sprintf('[Aarambha DS] Failed to download %s: %s', $filename, $download->get_error_message()));
            return;
        }

        $content = file_get_contents($download);

        // Clean up the temp file WordPress created.
        @unlink($download);

        if (false === $content) {
            error_log(sprintf('[Aarambha DS] Failed to read downloaded file for: %s', $filename));
            return;
        }

        $file_handle = fopen($file_name, 'w');
        if ($file_handle) {
            if (fwrite($file_handle, $content) !== false) {
                $files[$key] = $filename;
            }
            fclose($file_handle);
        } else {
            error_log(sprintf('[Aarambha DS] Failed to open file for writing: %s', $file_name));
        }
    }

    // -------------------------------------------------------------------------
    // Import steps
    // -------------------------------------------------------------------------

    /**
     * Imports WordPress content from a WXR file.
     *
     * @param  string $file Absolute path to the WXR (.xml) file.
     * @return array  Result array with 'action' and 'message' keys.
     */
    public function content($file)
    {
        // Obtain a fresh WP importer instance from the main plugin class.
        $importer = Aarambha_DS()->importer();

        if (!$importer) {
            return [
                'action'  => 'terminate',
                'message' => esc_html__('WordPress Importer plugin is required but not available.', 'aarambha-demo-sites'),
            ];
        }

        // Verify the file exists before attempting import.
        if (empty($file) || !file_exists($file)) {
            return [
                'action'  => 'terminate',
                'message' => sprintf(
                    /* translators: %s: file path */
                    esc_html__('Import file not found: %s', 'aarambha-demo-sites'),
                    esc_html($file)
                ),
                'file'    => $file,
            ];
        }

        try {
            $importer->fetch_attachments = true;

            do_action('aarambha_ds_before_content_import', $file, $importer);

            $result = $importer->import($file);

            do_action('aarambha_ds_after_content_import', $importer);

            // parent::import() may return a WP_Error or boolean. Treat non-falsey as success.
            if (is_wp_error($result)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf('[Aarambha DS] WP_Import returned error for file %s: %s', $file, $result->get_error_message()));
                }

                return [
                    'action'  => 'terminate',
                    'message' => $result->get_error_message(),
                    'file'    => $file,
                ];
            }

            // Gather processed counts when available for debugging.
            $posts_count = method_exists($importer, 'get_processed_posts') ? count($importer->get_processed_posts()) : 0;
            $terms_count = method_exists($importer, 'get_processed_terms') ? count($importer->get_processed_terms()) : 0;

            return [
                'action'                => 'import-customize',
                'message'               => esc_html__('Content imported successfully.', 'aarambha-demo-sites'),
                'file'                  => $file,
                'processed_posts_count' => $posts_count,
                'processed_terms_count' => $terms_count,
            ];

        } catch (Exception $e) {
            return [
                'action'  => 'terminate',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Imports Customizer settings from a JSON file.
     *
     * @param  string $import_file Absolute path to the customizer export file.
     * @return true
     */
    public function customizer($import_file)
    {
        require_once dirname(__FILE__) . '/customize/class-aarambha-ds-customize-importer.php';
        Aarambha_DS_Customize_Importer::import($import_file);
        return true;
    }

    /**
     * Imports widget data from a JSON file.
     *
     * @param  string $import_file Absolute path to the widgets export file.
     * @return true
     */
    public function widgets($import_file)
    {
        require_once dirname(__FILE__) . '/class-aarambha-ds-widget-importer.php';
        Aarambha_DS_Widget_Importer::import($import_file);
        return true;
    }

    /**
     * Imports a Smart Slider 3 slider from a file.
     *
     * @param  string $file Absolute path to the slider file.
     * @return true
     */
    public function slider($file)
    {
        if (!class_exists('SmartSlider3')) {
            return true;
        }

        SmartSlider3::import($file);
        return true;
    }

    /**
     * Assigns imported menus to the theme's registered nav-menu locations.
     *
     * @param  array $navigations Map of location slug => menu name.
     * @return true
     */
    public function setupNavigation($navigations)
    {
        $locations = get_theme_mod('nav_menu_locations', []);

        foreach ($navigations as $location => $menu_name) {
            $menu = get_term_by('name', $menu_name, 'nav_menu');
            if ($menu && isset($menu->term_id)) {
                $locations[$location] = $menu->term_id;
            }
        }

        set_theme_mod('nav_menu_locations', $locations);
        return true;
    }
}
