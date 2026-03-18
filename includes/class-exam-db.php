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
            PRIMARY KEY (id),
            KEY idx_category (category_id),
            KEY idx_unit (unit_id),
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
            random_difficulty VARCHAR(10) NULL,
            manual_question_ids LONGTEXT NULL,
            show_results TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(15) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_section (section_id),
            KEY idx_subject (subject_id),
            KEY idx_teacher (teacher_id),
            KEY idx_status (status),
            KEY idx_dates (start_time, end_time),
            KEY idx_academic (academic_year_id, semester_id)
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
            PRIMARY KEY (id),
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

        dbDelta($sql_categories);
        dbDelta($sql_questions);
        dbDelta($sql_exams);
        dbDelta($sql_attempts);
        dbDelta($sql_essays);
    }
    
    /**
     * Migration: Update student_id to student_uid (VARCHAR) in attempts table.
     * Migration: Add lesson_id to questions table if not exists.
     */
    public static function migrate_student_uid()
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_attempts";
        
        // Wait, wpdb->get_results returns objects. We need to check if student_id exists.
        $cols = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        $has_student_id = false;
        $has_student_uid = false;
        
        foreach ($cols as $col) {
            if ($col->Field === 'student_id') $has_student_id = true;
            if ($col->Field === 'student_uid') $has_student_uid = true;
        }

        if ($has_student_id && !$has_student_uid) {
            // Because dbDelta is finicky with DROP INDEX and CHANGE COLUMN, run raw queries
            $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_student");
            $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_exam_student");
            $wpdb->query("ALTER TABLE {$table} CHANGE student_id student_uid VARCHAR(100) NOT NULL");
            $wpdb->query("ALTER TABLE {$table} ADD KEY idx_student (student_uid)");
            $wpdb->query("ALTER TABLE {$table} ADD KEY idx_exam_student (exam_id, student_uid)");
        }

        // Add lesson_id to exam_questions if not exists
        $questions_table = "{$wpdb->prefix}olama_exam_questions";
        $q_cols = $wpdb->get_results("SHOW COLUMNS FROM {$questions_table}");
        $has_lesson_id = false;
        foreach ($q_cols as $col) {
            if ($col->Field === 'lesson_id') {
                $has_lesson_id = true;
                break;
            }
        }
        if (!$has_lesson_id) {
            $wpdb->query("ALTER TABLE {$questions_table} ADD COLUMN lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_id");
            $wpdb->query("ALTER TABLE {$questions_table} ADD KEY idx_lesson (lesson_id)");
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
