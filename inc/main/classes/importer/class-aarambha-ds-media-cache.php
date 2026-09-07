<?php

/**
 * Pre-downloads a demo's media files into a local cache so the WXR import
 * itself never has to make a slow network round-trip per attachment.
 *
 * The demo API server responds slowly (~1s+ per file). Importing 70+
 * attachments inside a single request runs well past the front-end proxy's
 * read timeout (nginx defaults to 60s), which surfaces to the user as a
 * "Gateway Time-out / Something Went Wrong!" error even though PHP is still
 * working in the background.
 *
 * Flow:
 *   1. collect_urls()       - parse <wp:attachment_url> entries out of the WXR.
 *   2. cache_batch()        - download a small batch to {demo dir}/_media_cache/,
 *                             called repeatedly across several AJAX requests so
 *                             no single request lasts long enough to time out.
 *   3. attach_interceptor() - short-circuit wp_remote_get() for cached URLs so
 *                             WP_Import::fetch_remote_file() reads from disk and
 *                             the real import finishes in seconds.
 *
 * @since       2.0.0
 * @package     Aarambha_Demo_Sites
 * @subpackage  Aarambha_Demo_Sites/Inc/Main/Classes/Importer
 */

if (!defined('WPINC')) {
    exit;    // Exit if accessed directly.
}

/**
 * Class Aarambha_DS_Media_Cache
 */
class Aarambha_DS_Media_Cache
{
    /**
     * Name of the cache directory created inside a demo's folder.
     *
     * @var string
     */
    const DIR_NAME = '_media_cache';

    /**
     * The registered `pre_http_request` callback, kept so it can be removed.
     *
     * @var callable|null
     */
    private static $interceptor = null;

    /**
     * Absolute path to the media cache directory for a demo.
     *
     * @param  string $demos_dir Absolute path to the demo's download folder.
     * @return string
     */
    public static function cache_dir($demos_dir)
    {
        return wp_normalize_path(trailingslashit($demos_dir) . self::DIR_NAME);
    }

    /**
     * Extract every attachment URL declared in a WXR file.
     *
     * @param  string $wxr_file Absolute path to the .xml file.
     * @return string[] De-duplicated list of absolute http(s) URLs.
     */
    public static function collect_urls($wxr_file)
    {
        if (empty($wxr_file) || !is_readable($wxr_file)) {
            return [];
        }

        $xml = file_get_contents($wxr_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        if (false === $xml) {
            return [];
        }

        $urls = [];

        if (preg_match_all('#<wp:attachment_url>(.*?)</wp:attachment_url>#s', $xml, $matches)) {
            foreach ($matches[1] as $raw) {
                $url = trim($raw);
                $url = preg_replace('#^<!\[CDATA\[(.*)\]\]>$#s', '$1', $url);
                $url = trim(html_entity_decode($url));

                if ($url && preg_match('#^https?://#i', $url)) {
                    $urls[$url] = true;
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * Deterministic cache filename for a URL (hash + original extension).
     *
     * @param  string $url
     * @return string
     */
    public static function filename_for($url)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        $ext  = $ext ? '.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $ext)) : '';

        return md5($url) . $ext;
    }

    /**
     * Whether a URL has already been downloaded to the cache.
     *
     * @param  string $url
     * @param  string $cache_dir
     * @return bool
     */
    public static function is_cached($url, $cache_dir)
    {
        $file = trailingslashit($cache_dir) . self::filename_for($url);

        return file_exists($file) && filesize($file) > 0;
    }

    /**
     * Download a slice of URLs into the cache directory.
     *
     * A download failure is logged and skipped rather than aborting the run;
     * the import will fall back to a live fetch for that single file.
     *
     * @param  string[] $urls
     * @param  int      $offset
     * @param  int      $batch_size
     * @param  string   $cache_dir
     * @return int Number of URLs consumed from $urls (always > 0 when work
     *             remained, so the caller's offset keeps advancing).
     */
    public static function cache_batch(array $urls, $offset, $batch_size, $cache_dir)
    {
        if (!wp_mkdir_p($cache_dir)) {
            return 0;
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $slice = array_slice($urls, $offset, max(1, (int) $batch_size));

        foreach ($slice as $url) {
            if (self::is_cached($url, $cache_dir)) {
                continue;
            }

            $tmp = download_url($url, 60);

            if (is_wp_error($tmp)) {
                aarambha_ds_error_log(sprintf(
                    '[Aarambha DS] media cache failed for %s: %s',
                    $url,
                    $tmp->get_error_message()
                ));
                continue;
            }

            $dest = trailingslashit($cache_dir) . self::filename_for($url);

            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @copy($tmp, $dest);
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink($tmp);
        }

        return count($slice);
    }

    /**
     * Route wp_remote_get() calls for cached URLs straight to the local file.
     *
     * WP_Import::fetch_remote_file() streams the response into a temp file
     * ($args['filename']); we satisfy that contract by copying our cached copy
     * there and returning a minimal 200 response.
     *
     * @param  string $cache_dir
     * @return void
     */
    public static function attach_interceptor($cache_dir)
    {
        self::detach_interceptor();

        self::$interceptor = static function ($pre, $args, $url) use ($cache_dir) {
            if (false !== $pre) {
                return $pre;
            }

            $file = trailingslashit($cache_dir) . self::filename_for($url);

            if (!file_exists($file) || filesize($file) < 1) {
                return false; // Not one of ours — let WordPress fetch it live.
            }

            $size = filesize($file);

            if (!empty($args['filename'])) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @copy($file, $args['filename']);
            }

            $type = wp_check_filetype($file);

            return [
                'headers'  => [
                    'content-length' => (string) $size,
                    'content-type'   => $type['type'] ? $type['type'] : 'application/octet-stream',
                ],
                'body'     => empty($args['filename'])
                    ? file_get_contents($file) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    : '',
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => !empty($args['filename']) ? $args['filename'] : null,
            ];
        };

        add_filter('pre_http_request', self::$interceptor, 10, 3);
    }

    /**
     * Remove the interceptor added by attach_interceptor().
     *
     * @return void
     */
    public static function detach_interceptor()
    {
        if (self::$interceptor) {
            remove_filter('pre_http_request', self::$interceptor, 10);
            self::$interceptor = null;
        }
    }

    /**
     * Delete the cache directory and its contents.
     *
     * @param  string $cache_dir
     * @return void
     */
    public static function cleanup($cache_dir)
    {
        if (empty($cache_dir) || !is_dir($cache_dir)) {
            return;
        }

        foreach ((array) glob(trailingslashit($cache_dir) . '*') as $file) {
            if (is_file($file)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @unlink($file);
            }
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @rmdir($cache_dir);
    }
}
