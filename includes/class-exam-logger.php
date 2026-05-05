<?php
/**
 * Olama Exam Engine — Logger (Wrapper)
 *
 * This class keeps its original file-writing behaviour AND
 * delegates to Olama_System_Logger (central logger in the parent
 * School System plugin) so all exam events are visible in the
 * unified Logs tab in the admin.
 *
 * If the parent plugin is not active, it falls back to writing
 * only to the private exam-engine.log file — no crash, no noise.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Exam_Logger {

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Log a message (backwards-compatible — always ERROR level).
     *
     * @param mixed $message String, array, or object to log.
     */
    public static function log( $message ) {
        $formatted = self::format( $message );

        // 1. Write to the private file (existing behaviour, preserved).
        self::write_to_file( $formatted );

        // 2. Delegate to central logger if available.
        if ( class_exists( 'Olama_System_Logger' ) ) {
            Olama_System_Logger::error( $formatted, 'exam-engine' );
        }
    }

    /**
     * Log a WARNING via the central logger.
     *
     * @param mixed $message
     * @param array $context Optional key-value context.
     */
    public static function warning( $message, $context = [] ) {
        $formatted = self::format( $message );
        self::write_to_file( '[WARNING] ' . $formatted );

        if ( class_exists( 'Olama_System_Logger' ) ) {
            Olama_System_Logger::warning( $formatted, 'exam-engine', $context );
        }
    }

    /**
     * Log an INFO event via the central logger.
     *
     * @param mixed $message
     * @param array $context Optional key-value context.
     */
    public static function info( $message, $context = [] ) {
        $formatted = self::format( $message );
        self::write_to_file( '[INFO] ' . $formatted );

        if ( class_exists( 'Olama_System_Logger' ) ) {
            Olama_System_Logger::info( $formatted, 'exam-engine', $context );
        }
    }

    /**
     * Log a DEBUG message. Only written when WP_DEBUG === true.
     *
     * @param mixed $message
     * @param array $context Optional key-value context.
     */
    public static function debug( $message, $context = [] ) {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }

        $formatted = self::format( $message );
        self::write_to_file( '[DEBUG] ' . $formatted );

        if ( class_exists( 'Olama_System_Logger' ) ) {
            Olama_System_Logger::debug( $formatted, 'exam-engine', $context );
        }
    }

    // ── Noise Suppression ────────────────────────────────────────────

    /**
     * Suppress irrelevant system noise (WPvivid, PHP 8.1+ deprecations).
     * Called once during plugin init.
     */
    public static function suppress_noise() {
        add_action( 'plugins_loaded', function () {
            set_error_handler( function ( $errno, $errstr, $errfile ) {
                // Suppress WPvivid translation notice.
                if ( strpos( $errstr, 'wpvivid-backuprestore' ) !== false
                     && strpos( $errstr, '_load_textdomain_just_in_time' ) !== false ) {
                    return true;
                }

                // Suppress PHP 8.1+ deprecations from WP core.
                if ( $errno === E_DEPRECATED && strpos( $errfile, 'wp-includes' ) !== false ) {
                    return true;
                }

                // Suppress textdomain just-in-time warnings.
                if ( strpos( $errstr, '_load_textdomain_just_in_time' ) !== false ) {
                    return true;
                }

                // Delegate all real errors to the standard handler.
                return false;
            } );
        }, 1 );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Convert arrays/objects to a readable string.
     */
    private static function format( $message ) {
        if ( is_array( $message ) || is_object( $message ) ) {
            return print_r( $message, true );
        }
        return (string) $message;
    }

    /**
     * Write a line to the private exam-engine.log file.
     */
    private static function write_to_file( $message ) {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/olama-logs';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
            file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden.' );
        }

        $log_file  = $log_dir . '/exam-engine.log';
        $timestamp = gmdate( '[Y-m-d H:i:s]' );
        error_log( $timestamp . ' ' . $message . PHP_EOL, 3, $log_file ); // phpcs:ignore
    }
}
