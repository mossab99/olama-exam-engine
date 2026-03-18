<?php
/**
 * Plugin Name: Olama Exam Engine
 * Plugin URI: https://olama.online/exam-engine
 * Description: Secure online exam module for the Olama School System. Supports MCQ, True/False, Short Answer, Matching, Ordering, Fill-in-the-Blank, and Essay questions with GIFT/CSV import.
 * Version: 1.0.0
 * Author: Dr. Mossab Al Hunaity !!
 * Text Domain: olama-exam
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Constants ──────────────────────────────────────────────────
define('OLAMA_EXAM_VERSION', '1.1.0');
define('OLAMA_EXAM_PATH', plugin_dir_path(__FILE__));
define('OLAMA_EXAM_URL', plugin_dir_url(__FILE__));
define('OLAMA_EXAM_BASENAME', plugin_basename(__FILE__));

// ── SIS Dependency Check ───────────────────────────────────────
function olama_exam_check_dependencies()
{
    if (!class_exists('Olama_School_Permissions')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Olama Exam Engine</strong> requires the <strong>Olama School System</strong> plugin to be installed and active.';
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

// ── Load Includes ──────────────────────────────────────────────
function olama_exam_load_includes()
{
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-db.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-question-images.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-questions.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-gift-parser.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-csv-parser.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-manager.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-engine.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-grader.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-ajax.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-shortcodes.php';

    if (is_admin()) {
        require_once OLAMA_EXAM_PATH . 'admin/class-exam-admin.php';
    }
}

// ── Plugin Activation ──────────────────────────────────────────
function olama_exam_activate()
{
    if (!olama_exam_check_dependencies()) {
        deactivate_plugins(OLAMA_EXAM_BASENAME);
        wp_die(
            'Olama Exam Engine requires the Olama School System plugin to be active.',
            'Plugin Dependency Error',
            array('back_link' => true)
        );
    }

    require_once OLAMA_EXAM_PATH . 'includes/class-exam-db.php';
    Olama_Exam_DB::create_tables();

    require_once OLAMA_EXAM_PATH . 'includes/class-exam-question-images.php';
    Olama_Exam_Question_Images::ensure_dir_exists();

    update_option('olama_exam_version', OLAMA_EXAM_VERSION);
    update_option('olama_exam_db_version', '1.0.0');
}
register_activation_hook(__FILE__, 'olama_exam_activate');

// ── Plugin Deactivation ────────────────────────────────────────
function olama_exam_deactivate()
{
// Clean up scheduled events if any
}
register_deactivation_hook(__FILE__, 'olama_exam_deactivate');

// ── Initialize Plugin ──────────────────────────────────────────
function olama_exam_init()
{
    if (!olama_exam_check_dependencies()) {
        return;
    }

    olama_exam_load_includes();

    // Initialize admin
    if (is_admin()) {
        new Olama_Exam_Admin();
    }

    // Initialize shortcodes
    new Olama_Exam_Shortcodes();

    // Initialize AJAX handlers
    Olama_Exam_Ajax::init();

    // Check for DB updates
    $current_db = get_option('olama_exam_db_version', '0');
    if (version_compare($current_db, OLAMA_EXAM_VERSION, '<')) {
        Olama_Exam_DB::create_tables();
        update_option('olama_exam_db_version', OLAMA_EXAM_VERSION);
    }

    // One-time migrations
    olama_exam_migrate_unit_id();
    olama_exam_migrate_preview_support();
    olama_exam_migrate_student_uid();
    olama_exam_migrate_lesson_id();
}
add_action('plugins_loaded', 'olama_exam_init', 20); // Priority 20 = after SIS plugin

// ── Unit ID Migration ──────────────────────────────────────────
function olama_exam_migrate_unit_id()
{
    if (get_option('olama_exam_unit_id_migrated', false)) {
        return;
    }

    global $wpdb;

    // Add unit_id to questions table if missing
    $col = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}olama_exam_questions LIKE 'unit_id'");
    if (empty($col)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}olama_exam_questions ADD COLUMN unit_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER category_id");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}olama_exam_questions ADD KEY idx_unit (unit_id)");
    }

    // Add random_unit_id to exams table if missing
    $col2 = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}olama_exam_exams LIKE 'random_unit_id'");
    if (empty($col2)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}olama_exam_exams ADD COLUMN random_unit_id BIGINT UNSIGNED NULL AFTER random_category_id");
    }

    update_option('olama_exam_unit_id_migrated', true);
}

/**
 * Migration: Add is_preview to attempts table
 */
function olama_exam_migrate_preview_support()
{
    if (get_option('olama_exam_preview_migrated', false)) {
        return;
    }

    global $wpdb;
    $table = "{$wpdb->prefix}olama_exam_attempts";

    // Check if column exists
    $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'is_preview'");
    if (empty($col)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN is_preview TINYINT(1) NOT NULL DEFAULT 0 AFTER submit_type");
    }

    update_option('olama_exam_preview_migrated', true);
}

/**
 * Migration: Change student_id to student_uid in attempts table
 */
function olama_exam_migrate_student_uid()
{
    if (get_option('olama_exam_student_uid_migrated', false)) {
        return;
    }

    Olama_Exam_DB::migrate_student_uid();

    update_option('olama_exam_student_uid_migrated', true);
}

/**
 * Migration: Add lesson_id to questions table
 */
function olama_exam_migrate_lesson_id()
{
    if (get_option('olama_exam_lesson_id_migrated', false)) {
        return;
    }

    Olama_Exam_DB::migrate_student_uid(); // this now also migrates lesson_id

    update_option('olama_exam_lesson_id_migrated', true);
}

// ── Enqueue Assets ─────────────────────────────────────────────
function olama_exam_enqueue_admin_assets($hook)
{
    // Only load on our admin pages
    if (strpos($hook, 'olama-exam') === false) {
        return;
    }

    wp_enqueue_style(
        'olama-exam-admin',
        OLAMA_EXAM_URL . 'assets/css/exam-admin.css',
        array(),
        OLAMA_EXAM_VERSION
    );

    wp_enqueue_script(
        'olama-exam-admin',
        OLAMA_EXAM_URL . 'assets/js/exam-admin.js',
        array('jquery'),
        OLAMA_EXAM_VERSION,
        false // Load in header so olamaExam + ExamAdmin are available to inline scripts
    );

    wp_localize_script('olama-exam-admin', 'olamaExam', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('olama_exam_nonce'),
    ));

    // Enqueue WP media for question images
    wp_enqueue_media();
}
add_action('admin_enqueue_scripts', 'olama_exam_enqueue_admin_assets');

function olama_exam_enqueue_frontend_assets()
{
    // Only load on pages with our shortcode
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'olama_exam')) {
        return;
    }

    wp_enqueue_style(
        'olama-exam-student',
        OLAMA_EXAM_URL . 'assets/css/exam-student.css',
        array(),
        OLAMA_EXAM_VERSION
    );

    // Google Fonts for premium typography
    wp_enqueue_style(
        'olama-exam-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap',
        array(),
        null
    );

    wp_enqueue_script(
        'olama-exam-engine',
        OLAMA_EXAM_URL . 'assets/js/exam-engine.js',
        array('jquery'),
        OLAMA_EXAM_VERSION,
        true
    );

    wp_enqueue_script(
        'olama-exam-dragdrop',
        OLAMA_EXAM_URL . 'assets/js/exam-dragdrop.js',
        array('jquery'),
        OLAMA_EXAM_VERSION,
        true
    );

    wp_localize_script('olama-exam-engine', 'olamaExam', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('olama_exam_nonce'),
    ));
}
add_action('wp_enqueue_scripts', 'olama_exam_enqueue_frontend_assets');

// ── Translation Helper ─────────────────────────────────────────
function olama_exam_translate($text)
{
    static $translations = null;
    if ($translations === null) {
        $file = OLAMA_EXAM_PATH . 'languages/olama-exam-engine-ar.php';
        $translations = file_exists($file) ? include $file : array();
    }

    $locale = get_locale();
    if (strpos($locale, 'ar') === 0 && isset($translations[$text])) {
        return $translations[$text];
    }
    return $text;
}
