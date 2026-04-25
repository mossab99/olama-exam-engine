<?php
/**
 * Olama Exam Engine — Secure Logger
 * Handles separate logging for the plugin to avoid debug.log bloat.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Logger
{
    /**
     * Log a message to a custom file
     * @param mixed $message String or array/object to log
     */
    public static function log($message)
    {
        // 1. Determine log directory (wp-content/uploads/olama-logs)
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/olama-logs';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Protect directory from public access
            file_put_contents($log_dir . '/.htaccess', 'deny from all');
            file_put_contents($log_dir . '/index.php', '');
        }

        $log_file = $log_dir . '/exam-engine.log';

        // 2. Format message
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $timestamp = date('[Y-m-d H:i:s]');
        error_log($timestamp . ' ' . $message . PHP_EOL, 3, $log_file);
    }

    /**
     * Suppress "noise" from the system error log (WPvivid, PHP 8.1+ Deprecations in Core)
     */
    public static function suppress_noise()
    {
        add_action('plugins_loaded', function () {
            set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                // 1. Suppress WPvivid's early translation notice
                if (strpos($errstr, 'wpvivid-backuprestore') !== false && strpos($errstr, '_load_textdomain_just_in_time') !== false) {
                    return true;
                }

                // 2. Suppress PHP Deprecated warnings from WP Core (common in PHP 8.1+)
                if ($errno === E_DEPRECATED && strpos($errfile, 'wp-includes') !== false) {
                    return true;
                }

                // 3. Suppress "doing_it_wrong" for textdomains if needed (extra layer)
                if (strpos($errstr, '_load_textdomain_just_in_time') !== false) {
                    return true;
                }

                // Return false to let the standard PHP/WP error handler take over for real issues
                return false;
            });
        }, 1);
    }
}
