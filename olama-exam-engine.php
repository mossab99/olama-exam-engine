<?php
/**
 * Plugin Name: Olama Exam Engine
 * Plugin URI: https://olama.online/exam-engine
 * Description: Secure online exam module for the Olama School System. Supports LaTeX mathematics and MCQ, True/False, Short Answer, Matching, Ordering, Fill-in-the-Blank, and Essay questions with structured imports.
 * Version: 1.2.1
 * Author: Dr. Mossab Al Hunaity !!
 * Text Domain: olama-exam
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires Plugins: olama-core, olama-users, olama-school
 */

if (!defined('ABSPATH')) {
    exit;
}

// ── Constants ──────────────────────────────────────────────────
define('OLAMA_EXAM_VERSION', '1.2.1');
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

function olama_exam_get_active_academic_context()
{
    $active_year = class_exists('Olama_School_Academic') ? Olama_School_Academic::get_active_year() : null;
    $active_semester = ($active_year && class_exists('Olama_School_Academic'))
        ? Olama_School_Academic::get_active_semester($active_year->id)
        : null;

    return array($active_year, $active_semester);
}

/**
 * Register Exam Engine permissions with the Olama Users access matrix.
 */
function olama_exam_register_users_module()
{
    if (!function_exists('olama_users_register_module')) {
        return;
    }

    olama_users_register_module(array(
        'id' => 'olama_exam_engine',
        'plugin' => 'olama-exam-engine',
        'label' => __('Exam Engine', 'olama-exam'),
        'capability' => 'olama_manage_question_bank',
        'items' => array(
            array(
                'id' => 'olama_exam_engine.question_bank',
                'type' => 'submenu',
                'label' => __('Question Bank', 'olama-exam'),
                'capability' => 'olama_manage_question_bank',
                'url' => admin_url('admin.php?page=olama-exam'),
            ),
            array(
                'id' => 'olama_exam_engine.exams',
                'type' => 'submenu',
                'label' => __('Create Exams', 'olama-exam'),
                'capability' => 'olama_create_exams',
                'url' => admin_url('admin.php?page=olama-exam-create'),
            ),
            array(
                'id' => 'olama_exam_engine.results',
                'type' => 'submenu',
                'label' => __('View Results', 'olama-exam'),
                'capability' => 'olama_view_exam_results',
                'url' => admin_url('admin.php?page=olama-exam-results'),
            ),
            array(
                'id' => 'olama_exam_engine.grading',
                'type' => 'submenu',
                'label' => __('Grade Exams', 'olama-exam'),
                'capability' => 'olama_grade_exams',
                'url' => admin_url('admin.php?page=olama-exam-grade-essays'),
            ),
        ),
    ));
}
add_action('olama_users_register_modules', 'olama_exam_register_users_module', 20);

// ── Load Includes ──────────────────────────────────────────────
function olama_exam_load_includes()
{
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-db.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-question-images.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-questions.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-gift-parser.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-json-parser.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-tex-parser.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-db.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-logger.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-identity.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-manager.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-engine.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-grader.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-ajax.php';
    require_once OLAMA_EXAM_PATH . 'includes/class-exam-shortcodes.php';

    // ── Employee Acceptance Test Module ──
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-professions.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-acceptance-tests.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-acceptance-public.php';

    // ── Student Acceptance Test Module ──
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-categories.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-grade-levels.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-student-tests.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-student-public.php';

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

    // Load acceptance test class and flush rewrite rules
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-professions.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-acceptance-tests.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-acceptance-public.php';
    $public_flow = new OEE_Acceptance_Public();
    $public_flow->register_rewrite();

    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-grade-levels.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-student-tests.php';
    require_once OLAMA_EXAM_PATH . 'includes/acceptance/class-oee-student-public.php';
    $student_public_flow = new OEE_Student_Public();
    $student_public_flow->register_rewrite();

    flush_rewrite_rules(false);
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

    // Initialize public acceptance handlers. Loading the class file alone is not
    // enough: the instances register the rewrite/query vars and handle requests.
    new OEE_Acceptance_Public();
    new OEE_Student_Public();

    // Deployments upgraded from an older build may already have the old
    // one-time flags while the acceptance rule is missing from WordPress.
    // Refresh the saved rules once after both public routes are registered.
    if (!get_option('oee_public_rewrite_flushed_v2', false)) {
        flush_rewrite_rules(false);
        update_option('oee_public_rewrite_flushed_v2', true);
    }

    // Check for DB updates
    $current_db = get_option('olama_exam_db_version', '0');
    if (version_compare($current_db, OLAMA_EXAM_VERSION, '<') || !get_option('olama_exam_db_sync_1_1_3', false) || !get_option('olama_exam_db_sync_acceptance_v1', false)) {
        Olama_Exam_DB::create_tables();
        Olama_Exam_DB::migrate_student_uid();
        Olama_Exam_DB::migrate_legacy_attempt_student_uids();
        update_option('olama_exam_db_version', OLAMA_EXAM_VERSION);
        update_option('olama_exam_db_sync_1_1_3', true);
        update_option('olama_exam_db_sync_acceptance_v1', true);
    }

    // One-time migrations
    olama_exam_migrate_unit_id();
    olama_exam_migrate_preview_support();
    olama_exam_migrate_student_uid();
    olama_exam_migrate_lesson_id();
    olama_exam_migrate_student_uid(); // ensure it's synced
    olama_exam_sync_db_columns();
    olama_exam_migrate_grade_level_id();
    Olama_Exam_DB::migrate_legacy_attempt_student_uids();

    // Suppress system noise (WPvivid, etc.)
    Olama_Exam_Logger::suppress_noise();
}
add_action('init', 'olama_exam_init', 10); // Standard priority

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

/**
 * Migration: Sync DB columns to ensure none are missing (like exam_type)
 */
function olama_exam_sync_db_columns()
{
    if (get_option('olama_exam_db_synced_v113', false)) {
        return;
    }

    Olama_Exam_DB::migrate_student_uid();

    update_option('olama_exam_db_synced_v113', true);
}

/**
 * Migration: Add grade_level_id to questions table
 */
function olama_exam_migrate_grade_level_id() {
    if ( get_option( 'olama_exam_grade_level_migrated', false ) ) {
        return;
    }

    global $wpdb;

    $col = $wpdb->get_results(
        "SHOW COLUMNS FROM {$wpdb->prefix}olama_exam_questions LIKE 'grade_level_id'"
    );
    if ( empty( $col ) ) {
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}olama_exam_questions
             ADD COLUMN grade_level_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER lesson_id"
        );
        $wpdb->query(
            "ALTER TABLE {$wpdb->prefix}olama_exam_questions
             ADD KEY idx_grade_level (grade_level_id)"
        );
    }

    flush_rewrite_rules( false );

    update_option( 'olama_exam_grade_level_migrated', true );
}

// ── Enqueue Assets ─────────────────────────────────────────────
function olama_exam_enqueue_admin_assets($hook)
{
    // Only load on our admin pages
    if (strpos($hook, 'olama-exam') === false && strpos($hook, 'oee-') === false) {
        return;
    }

    wp_enqueue_style(
        'olama-exam-admin',
        OLAMA_EXAM_URL . 'assets/css/exam-admin.css',
        array(),
        OLAMA_EXAM_VERSION
    );

    olama_exam_enqueue_math_assets();

    wp_enqueue_script(
        'olama-exam-admin',
        OLAMA_EXAM_URL . 'assets/js/exam-admin.js',
        array('jquery'),
        OLAMA_EXAM_VERSION,
        false // Load in header so olamaExam + ExamAdmin are available to inline scripts
    );

    // Find a page with the shortcode
    $exams_page_url = home_url('/exams/'); // Default fallback
    $pages = get_posts(array(
        'post_type' => 'page',
        's' => '[olama_exam]',
        'posts_per_page' => 1
    ));
    if (!empty($pages) && has_shortcode($pages[0]->post_content, 'olama_exam')) {
        $exams_page_url = get_permalink($pages[0]->ID);
    }

    wp_localize_script('olama-exam-admin', 'olamaExam', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('olama_exam_nonce'),
        'examsPageUrl' => $exams_page_url,
    ));

    // Enqueue WP media for question images
    wp_enqueue_media();
}
add_action('admin_enqueue_scripts', 'olama_exam_enqueue_admin_assets');

function olama_exam_enqueue_frontend_assets($force = false)
{
    // Only load on pages with our shortcode (unless forced)
    global $post;
    if (!$force && (!$post || !has_shortcode($post->post_content, 'olama_exam'))) {
        return;
    }

    if ($force && !did_action('wp_head')) {
        // We are early enough for standard enqueue
    } elseif ($force) {
        // Late enqueue — manually print style tags to ensure they work with versioning for cache busting
        echo '<link rel="stylesheet" id="olama-exam-student-late" href="' . esc_url(OLAMA_EXAM_URL . 'assets/css/exam-student.css?ver=' . OLAMA_EXAM_VERSION) . '" type="text/css" media="all" />';
        echo '<link rel="stylesheet" id="olama-exam-fonts-late" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Kufi+Arabic:wght@400;500;600;700&display=swap" type="text/css" media="all" />';
    }

    wp_enqueue_style(
        'olama-exam-student',
        OLAMA_EXAM_URL . 'assets/css/exam-student.css',
        array(),
        OLAMA_EXAM_VERSION
    );

    olama_exam_enqueue_math_assets();

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
        array('jquery', 'olama-exam-math'),
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

/**
 * Load a local, scoped MathJax runtime for exam content.
 *
 * MathJax is deliberately configured not to scan the whole WordPress page.
 * assets/js/exam-math.js sends only known exam containers for typesetting.
 */
function olama_exam_enqueue_math_assets()
{
    if (wp_script_is('olama-exam-math', 'enqueued')) {
        return;
    }

    wp_enqueue_script(
        'olama-exam-mathjax',
        OLAMA_EXAM_URL . 'assets/vendor/mathjax/tex-chtml.js',
        array(),
        '4.1.3',
        true
    );

    $mathjax_config = <<<'JS'
window.MathJax = {
    loader: {
        load: ['ui/safe']
    },
    startup: {
        typeset: false
    },
    tex: {
        inlineMath: [['\\(', '\\)'], ['$', '$']],
        displayMath: [['\\[', '\\]'], ['$$', '$$']],
        processEscapes: true
    },
    options: {
        enableMenu: false,
        safeOptions: {
            allow: {
                URLs: 'none',
                classes: 'none',
                cssIDs: 'none',
                styles: 'none'
            }
        }
    }
};
JS;
    wp_add_inline_script('olama-exam-mathjax', $mathjax_config, 'before');

    wp_enqueue_script(
        'olama-exam-math',
        OLAMA_EXAM_URL . 'assets/js/exam-math.js',
        array('olama-exam-mathjax'),
        OLAMA_EXAM_VERSION,
        true
    );
}

// ── Translation Helper ─────────────────────────────────────────
function olama_exam_translate($text)
{
    if (!did_action('init') && !did_action('plugins_loaded')) {
        return $text;
    }

    static $translations = null;
    if ($translations === null) {
        $file = OLAMA_EXAM_PATH . 'languages/olama-exam-engine-ar.php';
        if (file_exists($file)) {
            $translations = include $file;
        } else {
            $translations = array();
        }
    }

    $locale = get_locale();
    if ($locale && strpos((string)$locale, 'ar') === 0) {
        if (isset($translations[$text])) {
            return $translations[$text];
        }
    } else {
        // English/fallback human-readable titles for technical keys
        $english_fallback = array(
            'student_results_menu' => 'Student Results',
            'student_tests_menu'   => 'Student Tests',
            'grade_levels_menu'    => 'Grade Levels',
            'student_name'         => 'Student Name',
            'guardian_name'        => 'Guardian Name',
            'student_dob'          => 'Student DOB',
            'national_id'          => 'National ID',
            'phone'                => 'Phone',
        );
        if (isset($english_fallback[$text])) {
            return $english_fallback[$text];
        }
    }
    return $text;
}

/**
 * Global logging helper with level support
 */
function olama_exam_log( $message, $level = 'error' ) {
    if ( class_exists( 'Olama_Exam_Logger' ) ) {
        switch ( $level ) {
            case 'warning': Olama_Exam_Logger::warning( $message ); break;
            case 'info':    Olama_Exam_Logger::info( $message );    break;
            case 'debug':   Olama_Exam_Logger::debug( $message );   break;
            default:        Olama_Exam_Logger::log( $message );     break;
        }
    }
}
