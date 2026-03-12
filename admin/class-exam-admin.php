<?php
/**
 * Exam Engine Admin Menu
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menus'));
    }

    /**
     * Register admin menu and submenus
     */
    public function register_menus()
    {
        // Top-level menu
        add_menu_page(
            olama_exam_translate('Exam Engine'),
            olama_exam_translate('Exam Engine'),
            'manage_options',
            'olama-exam',
            array($this, 'render_question_bank'),
            'dashicons-welcome-learn-more',
            31
        );

        // Submenu: Question Bank
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('Question Bank'),
            olama_exam_translate('Question Bank'),
            'manage_options',
            'olama-exam',
            array($this, 'render_question_bank')
        );

        // Submenu: Import Questions (GIFT) - Hidden (called from QB)
        add_submenu_page(
            null,
            olama_exam_translate('Import GIFT'),
            olama_exam_translate('Import GIFT'),
            'manage_options',
            'olama-exam-import-gift',
            array($this, 'render_gift_import')
        );

        // Submenu: Import Questions (CSV) - Hidden (called from QB)
        add_submenu_page(
            null,
            olama_exam_translate('Import CSV'),
            olama_exam_translate('Import CSV'),
            'manage_options',
            'olama-exam-import-csv',
            array($this, 'render_csv_import')
        );

        // Submenu: Create Exam
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('Create Exam'),
            olama_exam_translate('Create Exam'),
            'manage_options',
            'olama-exam-create',
            array($this, 'render_exam_create')
        );

        // Submenu: Results
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('Results'),
            olama_exam_translate('Results'),
            'manage_options',
            'olama-exam-results',
            array($this, 'render_results')
        );

        // Submenu: Grade Essays
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('Grade Essays'),
            olama_exam_translate('Grade Essays'),
            'manage_options',
            'olama-exam-grade-essays',
            array($this, 'render_grade_essays')
        );

        // Hidden submenu for Preview (not shown in menu but accessible via link)
        add_submenu_page(
            null, // Hide from menu
            olama_exam_translate('Exam Preview'),
            olama_exam_translate('Exam Preview'),
            'manage_options',
            'olama-exam-preview',
            array($this, 'render_exam_preview')
        );

        // Hidden submenu for Student Preview
        add_submenu_page(
            null,
            olama_exam_translate('Student Preview'),
            olama_exam_translate('Student Preview'),
            'manage_options',
            'olama-exam-student-preview',
            array($this, 'render_student_preview')
        );
    }

    /**
     * Render admin pages
     */
    public function render_question_bank()
    {
        include OLAMA_EXAM_PATH . 'admin/views/question-bank.php';
    }

    public function render_gift_import()
    {
        include OLAMA_EXAM_PATH . 'admin/views/gift-import.php';
    }

    public function render_csv_import()
    {
        include OLAMA_EXAM_PATH . 'admin/views/csv-import.php';
    }

    public function render_exam_create()
    {
        include OLAMA_EXAM_PATH . 'admin/views/exam-create.php';
    }

    public function render_results()
    {
        include OLAMA_EXAM_PATH . 'admin/views/exam-results.php';
    }

    public static function render_grade_essays()
    {
        include OLAMA_EXAM_PATH . 'admin/views/exam-grade-essays.php';
    }

    public static function render_exam_preview()
    {
        include OLAMA_EXAM_PATH . 'admin/views/exam-preview.php';
    }

    public static function render_student_preview()
    {
        include OLAMA_EXAM_PATH . 'admin/views/student-preview.php';
    }
}
