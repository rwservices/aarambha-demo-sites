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

    public function import($file, $options = [])
    {
        do_action('aarambha_before_demo_import', $file, $this);

        $result = parent::import($file);

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