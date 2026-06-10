<?php
/**
 * Aarambha Custom WordPress Importer
 *
 * @package Aarambha_Demo_Sites
 */

if (!defined('WPINC')) {
    exit;
}

class Aarambha_WP_Import extends WP_Import
{
    public $fetch_attachments = true;

    public function __construct()
    {
        parent::__construct();
        add_action('import_end', [$this, 'afterImportComplete']);
    }

    /**
     * Run the import, suppressing all output from WP_Import.
     *
     * WP_Import (the official WordPress Importer plugin) echoes progress
     * messages like "Processing post #123", "Done." etc. directly to stdout
     * during import(). When this runs inside an AJAX handler those echo
     * statements appear BEFORE wp_send_json_success() fires, which makes the
     * response body look like:
     *
     *   Processing post #1...Done.{"success":true,...}
     *
     * jQuery then tries to JSON.parse() that string and throws:
     *   SyntaxError: Unexpected token 'P', "Processing"... is not valid JSON
     *
     * The fix: wrap parent::import() in ob_start() / ob_end_clean() so every
     * byte WP_Import echoes is captured and discarded. Only the return value
     * (true / WP_Error) is kept.
     */
    public function import($file, $options = [])
    {
        do_action('aarambha_before_demo_import', $file, $this);

        // Capture and discard all output from WP_Import.
        ob_start();
        $result = parent::import($file);
        ob_end_clean();

        do_action('aarambha_after_demo_import', $this->processed_posts, $this->processed_terms, $this);

        return $result;
    }

    public function afterImportComplete()
    {
        flush_rewrite_rules(true);
        do_action('aarambha_demo_import_complete', $this);
    }

    public function get_processed_posts() { return $this->processed_posts ?? []; }
    public function get_processed_terms() { return $this->processed_terms ?? []; }
}