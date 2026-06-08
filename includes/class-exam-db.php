<?php
/**
 * Exam Engine Database Schema
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_DB
{
    /**
     * Get all tables managed by this plugin
     */
    public static function get_tables()
    {
        return array(
            'olama_exam_question_categories',
            'olama_exam_questions',
            'olama_exam_exams',
            'olama_exam_attempts',
            'olama_exam_essay_grades',
            'olama_exam_placement_info'
        );
    }

    /**
     * Create all exam engine tables
     */
    public static function create_tables()
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Table 1: Question Categories ───────────────────────
        $table_categories = "{$wpdb->prefix}olama_exam_question_categories";
        $sql_categories = "CREATE TABLE $table_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL,
            language VARCHAR(5) NOT NULL DEFAULT 'ar',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_subject (subject_id)
        ) $charset;";

        // ── Table 2: Questions ─────────────────────────────────
        $table_questions = "{$wpdb->prefix}olama_exam_questions";
        $sql_questions = "CREATE TABLE $table_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            unit_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            profession_id BIGINT UNSIGNED NULL DEFAULT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'mcq',
            question_text TEXT NOT NULL,
            answers_json LONGTEXT NOT NULL,
            difficulty VARCHAR(10) NOT NULL DEFAULT 'medium',
            language VARCHAR(5) NOT NULL DEFAULT 'ar',
            explanation TEXT NULL,
            image_filename VARCHAR(255) NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_category (category_id),
            KEY idx_unit (unit_id),
            KEY idx_unit_lesson (unit_id, lesson_id),
            KEY idx_lesson (lesson_id),
            KEY idx_profession (profession_id),
            KEY idx_type (type),
            KEY idx_difficulty (difficulty),
            KEY idx_language (language)
        ) $charset;";

        // ── Table 3: Exams ─────────────────────────────────────
        $table_exams = "{$wpdb->prefix}olama_exam_exams";
        $sql_exams = "CREATE TABLE $table_exams (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            section_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            teacher_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            academic_year_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            semester_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            passing_grade INT UNSIGNED NOT NULL DEFAULT 50,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 1,
            question_mode VARCHAR(10) NOT NULL DEFAULT 'manual',
            random_count INT UNSIGNED NULL,
            random_category_id BIGINT UNSIGNED NULL,
            random_unit_id BIGINT UNSIGNED NULL,
            random_lesson_id BIGINT UNSIGNED NULL,
            random_difficulty VARCHAR(10) NULL,
            manual_question_ids LONGTEXT NULL,
            question_limit INT UNSIGNED NULL,
            show_results TINYINT(1) NOT NULL DEFAULT 0,
            show_correct_answers TINYINT(1) NOT NULL DEFAULT 0,
            is_placement TINYINT(1) NOT NULL DEFAULT 0,
            exam_type VARCHAR(20) NOT NULL DEFAULT 'exam',
            password VARCHAR(255) NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_section (section_id),
            KEY idx_subject (subject_id),
            KEY idx_teacher (teacher_id),
            KEY idx_status (status),
            KEY idx_dates (start_time, end_time),
            KEY idx_academic (academic_year_id, semester_id),
            KEY idx_type (exam_type)
        ) $charset;";

        // ── Table 4: Exam Attempts ─────────────────────────────
        $table_attempts = "{$wpdb->prefix}olama_exam_attempts";
        $sql_attempts = "CREATE TABLE $table_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            exam_id BIGINT UNSIGNED NOT NULL,
            student_uid VARCHAR(100) NOT NULL,
            attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
            questions_snapshot_json LONGTEXT NOT NULL,
            answers_json LONGTEXT NULL,
            score DECIMAL(8,2) NULL,
            max_score DECIMAL(8,2) NULL,
            percentage DECIMAL(5,2) NULL,
            result VARCHAR(10) NOT NULL DEFAULT 'pending',
            started_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            submit_type VARCHAR(15) NULL,
            is_preview TINYINT(1) NOT NULL DEFAULT 0,
            exam_type VARCHAR(20) NOT NULL DEFAULT 'school',
            PRIMARY KEY  (id),
            KEY idx_exam (exam_id),
            KEY idx_student (student_uid),
            KEY idx_exam_student (exam_id, student_uid),
            KEY idx_result (result)
        ) $charset;";

        // ── Table 5: Essay Grades ──────────────────────────────
        $table_essays = "{$wpdb->prefix}olama_exam_essay_grades";
        $sql_essays = "CREATE TABLE $table_essays (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attempt_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            score DECIMAL(5,2) NOT NULL DEFAULT 0,
            max_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            teacher_comment TEXT NULL,
            graded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            graded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attempt (attempt_id),
            UNIQUE KEY idx_attempt_question (attempt_id, question_id)
        ) $charset;";

        // ── Table 6: Placement Info (Prospective Students) ─────
        $table_placement = "{$wpdb->prefix}olama_exam_placement_info";
        $sql_placement = "CREATE TABLE $table_placement (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attempt_id BIGINT UNSIGNED NOT NULL,
            student_name VARCHAR(255) NOT NULL,
            guardian_name VARCHAR(255) NULL,
            mobile VARCHAR(50) NULL,
            address TEXT NULL,
            old_school VARCHAR(255) NULL,
            last_finished_grade VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attempt (attempt_id)
        ) $charset;";

        // ── Table 7: Professions ────────────────────────────────
        $table_professions = "{$wpdb->prefix}oee_professions";
        $sql_professions = "CREATE TABLE $table_professions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name_ar VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_status (status)
        ) $charset;";

        // ── Table 8: Acceptance Tests ──────────────────────────
        $table_acceptance_tests = "{$wpdb->prefix}oee_acceptance_tests";
        $sql_acceptance_tests = "CREATE TABLE $table_acceptance_tests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profession_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            duration_min INT NOT NULL DEFAULT 45,
            num_questions INT NOT NULL DEFAULT 40,
            pass_score_pct INT NOT NULL DEFAULT 60,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            public_token VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY public_token (public_token),
            KEY idx_profession_id (profession_id),
            KEY idx_status (status)
        ) $charset;";

        // ── Table 9: Acceptance Applicants ─────────────────────
        $table_applicants = "{$wpdb->prefix}oee_acceptance_applicants";
        $sql_applicants = "CREATE TABLE $table_applicants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attempt_id BIGINT UNSIGNED NOT NULL,
            test_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            national_id VARCHAR(20) NOT NULL DEFAULT '',
            phone VARCHAR(20) NOT NULL DEFAULT '',
            email VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_attempt_id (attempt_id),
            KEY idx_test_id (test_id)
        ) $charset;";

        dbDelta($sql_categories);
        dbDelta($sql_questions);
        dbDelta($sql_exams);
        dbDelta($sql_attempts);
        dbDelta($sql_essays);
        dbDelta($sql_placement);
        dbDelta($sql_professions);
        dbDelta($sql_acceptance_tests);
        dbDelta($sql_applicants);
    }
    
    /**
     * Migration: Update student_id to student_uid (VARCHAR) in attempts table.
     * Migration: Add lesson_id to questions table if not exists.
     * Migration: Add missing columns to exams table.
     */
    public static function migrate_student_uid()
    {
        global $wpdb;
        $table_attempts = "{$wpdb->prefix}olama_exam_attempts";
        
        // 1. Migrate student_id to student_uid in attempts table
        $cols = $wpdb->get_results("SHOW COLUMNS FROM {$table_attempts}");
        $has_student_id = false;
        $has_student_uid = false;
        
        foreach ($cols as $col) {
            if ($col->Field === 'student_id') $has_student_id = true;
            if ($col->Field === 'student_uid') $has_student_uid = true;
        }

        if ($has_student_id && !$has_student_uid) {
            $wpdb->query("ALTER TABLE {$table_attempts} DROP INDEX idx_student");
            $wpdb->query("ALTER TABLE {$table_attempts} DROP INDEX idx_exam_student");
            $wpdb->query("ALTER TABLE {$table_attempts} CHANGE student_id student_uid VARCHAR(100) NOT NULL");
            $wpdb->query("ALTER TABLE {$table_attempts} ADD KEY idx_student (student_uid)");
            $wpdb->query("ALTER TABLE {$table_attempts} ADD KEY idx_exam_student (exam_id, student_uid)");
        }

        // 2. Add lesson_id to questions table if not exists
        $table_questions = "{$wpdb->prefix}olama_exam_questions";
        $q_cols = $wpdb->get_results("SHOW COLUMNS FROM {$table_questions}");
        $has_lesson_id = false;
        foreach ($q_cols as $col) {
            if ($col->Field === 'lesson_id') {
                $has_lesson_id = true;
                break;
            }
        }
        if (!$has_lesson_id) {
            // Check if unit_id exists before using AFTER
            $has_unit_id = false;
            foreach ($q_cols as $col) { if ($col->Field === 'unit_id') $has_unit_id = true; }
            $after = $has_unit_id ? "AFTER unit_id" : "";
            $wpdb->query("ALTER TABLE {$table_questions} ADD COLUMN lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0 $after");
            $wpdb->query("ALTER TABLE {$table_questions} ADD KEY idx_lesson (lesson_id)");
        }

        // 3. Add missing columns to exams table
        $table_exams = "{$wpdb->prefix}olama_exam_exams";
        $e_cols = $wpdb->get_results("SHOW COLUMNS FROM {$table_exams}");
        $columns = array_column($e_cols, 'Field');

        if (!in_array('random_lesson_id', $columns)) {
            $after = in_array('random_unit_id', $columns) ? "AFTER random_unit_id" : "";
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN random_lesson_id BIGINT UNSIGNED NULL $after");
        }
        
        if (!in_array('question_limit', $columns)) {
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN question_limit INT UNSIGNED NULL AFTER manual_question_ids");
        }

        if (!in_array('show_results', $columns)) {
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN show_results TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_question_ids");
        }

        if (!in_array('show_correct_answers', $columns)) {
            $after = in_array('show_results', $columns) ? "AFTER show_results" : "AFTER manual_question_ids";
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN show_correct_answers TINYINT(1) NOT NULL DEFAULT 0 $after");
        }

        if (!in_array('is_placement', $columns)) {
            $after = in_array('show_results', $columns) ? "AFTER show_results" : "";
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN is_placement TINYINT(1) NOT NULL DEFAULT 0 $after");
        }

        if (!in_array('exam_type', $columns)) {
            $after = in_array('is_placement', $columns) ? "AFTER is_placement" : (in_array('show_results', $columns) ? "AFTER show_results" : "");
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN exam_type VARCHAR(20) NOT NULL DEFAULT 'exam' $after");
            $wpdb->query("ALTER TABLE {$table_exams} ADD KEY idx_type (exam_type)");
        }

        if (!in_array('password', $columns)) {
            $after = in_array('exam_type', $columns) ? "AFTER exam_type" : "";
            $wpdb->query("ALTER TABLE {$table_exams} ADD COLUMN password VARCHAR(255) NULL $after");
        }

        // 4. Create/Update placement info table
        $table_placement = "{$wpdb->prefix}olama_exam_placement_info";
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_placement'") != $table_placement) {
            self::create_tables(); 
        } else {
            $p_cols = $wpdb->get_results("SHOW COLUMNS FROM {$table_placement}");
            $p_columns = array_column($p_cols, 'Field');
            
            if (!in_array('mobile', $p_columns)) {
                $after = in_array('guardian_name', $p_columns) ? "AFTER guardian_name" : "";
                $wpdb->query("ALTER TABLE {$table_placement} ADD COLUMN mobile VARCHAR(50) NULL $after");
            }
            if (!in_array('last_finished_grade', $p_columns)) {
                $after = in_array('old_school', $p_columns) ? "AFTER old_school" : "";
                $wpdb->query("ALTER TABLE {$table_placement} ADD COLUMN last_finished_grade VARCHAR(100) NULL $after");
            }
        }
    }

    /**
     * Drop all exam engine tables (use with caution)
     */
    public static function drop_tables()
    {
        global $wpdb;
        $tables = array(
            "{$wpdb->prefix}olama_exam_essay_grades",
            "{$wpdb->prefix}olama_exam_attempts",
            "{$wpdb->prefix}olama_exam_exams",
            "{$wpdb->prefix}olama_exam_questions",
            "{$wpdb->prefix}olama_exam_question_categories",
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }

        delete_option('olama_exam_version');
        delete_option('olama_exam_db_version');
    }
}
