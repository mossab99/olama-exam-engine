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
        add_action('admin_post_oee_export_acceptance_csv', array($this, 'export_acceptance_csv'));
        add_action('admin_post_oee_export_student_acceptance_csv', array($this, 'export_student_acceptance_csv'));
        add_filter('submenu_file', array($this, 'highlight_job_apps_menu'));
        add_filter('submenu_file', array($this, 'highlight_student_acceptance_menu'));
        add_filter('submenu_file', array($this, 'highlight_school_exams_menu'));
    }

    public function highlight_job_apps_menu($submenu_file)
    {
        global $plugin_page;
        if (in_array($plugin_page, array('oee-acceptance-tests', 'oee-acceptance-results'))) {
            $submenu_file = 'oee-professions';
        }
        return $submenu_file;
    }

    public function highlight_school_exams_menu($submenu_file)
    {
        global $plugin_page;
        if (in_array($plugin_page, array('olama-exam-create-quiz', 'olama-exam-results', 'olama-exam-grade-essays'))) {
            $submenu_file = 'olama-exam-create';
        }
        return $submenu_file;
    }


    /**
     * Helper to get appropriate capability with fallback
     * @param string $cap The required capability from Olama School.
     * @return string Required capability capability to pass to WordPress functions.
     */
    private function get_capability($cap)
    {
        if (class_exists('Olama_School_Permissions')) {
            return $cap;
        }
        return 'manage_options';
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
            $this->get_capability('olama_manage_question_bank'),
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
            $this->get_capability('olama_manage_question_bank'),
            'olama-exam',
            array($this, 'render_question_bank')
        );

        // Submenu: Categories (Hidden from sidebar, accessible via tab)
        add_submenu_page(
            null,
            olama_exam_translate('Categories'),
            olama_exam_translate('Categories'),
            $this->get_capability('olama_manage_question_bank'),
            'olama-exam-categories',
            array($this, 'render_categories')
        );

        // Submenu: Import Questions (GIFT) - Hidden (called from QB)
        add_submenu_page(
            null,
            olama_exam_translate('Import GIFT'),
            olama_exam_translate('Import GIFT'),
            $this->get_capability('olama_manage_question_bank'),
            'olama-exam-import-gift',
            array($this, 'render_gift_import')
        );

        // Submenu: Import Questions (CSV) - Hidden (called from QB)
        add_submenu_page(
            null,
            olama_exam_translate('Import CSV'),
            olama_exam_translate('Import CSV'),
            $this->get_capability('olama_manage_question_bank'),
            'olama-exam-import-csv',
            array($this, 'render_csv_import')
        );

        // Submenu: Create Exam (Now acts as parent tab "School Exams")
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('School Exams'),
            olama_exam_translate('School Exams'),
            $this->get_capability('olama_create_exams'),
            'olama-exam-create',
            array($this, 'render_exam_create')
        );

        // Submenu: Create Quiz (Hidden, accessible via tab)
        add_submenu_page(
            null,
            olama_exam_translate('Create Quiz'),
            olama_exam_translate('Create Quiz'),
            $this->get_capability('olama_create_exams'),
            'olama-exam-create-quiz',
            array($this, 'render_exam_create')
        );

        // Submenu: Results (Hidden, accessible via tab)
        add_submenu_page(
            null,
            olama_exam_translate('Results'),
            olama_exam_translate('Results'),
            $this->get_capability('olama_view_exam_results'),
            'olama-exam-results',
            array($this, 'render_results')
        );

        // Submenu: Grade Essays (Hidden, accessible via tab)
        add_submenu_page(
            null,
            olama_exam_translate('Grade Essays'),
            olama_exam_translate('Grade Essays'),
            $this->get_capability('olama_grade_exams'),
            'olama-exam-grade-essays',
            array($this, 'render_grade_essays')
        );

        // Submenu: Job Apps (Acts as parent tab)
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('Job Apps'),
            olama_exam_translate('Job Apps'),
            $this->get_capability('olama_manage_question_bank'),
            'oee-professions',
            array($this, 'render_professions')
        );

        // Submenu: Acceptance Tests (Hidden)
        add_submenu_page(
            null,
            olama_exam_translate('Acceptance Tests'),
            olama_exam_translate('Acceptance Tests'),
            $this->get_capability('olama_create_exams'),
            'oee-acceptance-tests',
            array($this, 'render_acceptance_tests')
        );

        // Submenu: Acceptance Results (Hidden)
        add_submenu_page(
            null,
            olama_exam_translate('Acceptance Results'),
            olama_exam_translate('Acceptance Results'),
            $this->get_capability('olama_view_exam_results'),
            'oee-acceptance-results',
            array($this, 'render_acceptance_results')
        );

        // Submenu: Grade Levels (Acts as parent tab for school acceptance)
        add_submenu_page(
            'olama-exam',
            olama_exam_translate('grade_levels_menu'),
            olama_exam_translate('grade_levels_menu'),
            $this->get_capability('olama_manage_question_bank'),
            'oee-grade-levels',
            array($this, 'render_grade_levels')
        );

        // Submenu: Student Tests (Hidden)
        add_submenu_page(
            null,
            olama_exam_translate('student_tests_menu'),
            olama_exam_translate('student_tests_menu'),
            $this->get_capability('olama_create_exams'),
            'oee-student-tests',
            array($this, 'render_student_tests')
        );

        // Submenu: Student Results (Hidden)
        add_submenu_page(
            null,
            olama_exam_translate('student_results_menu'),
            olama_exam_translate('student_results_menu'),
            $this->get_capability('olama_view_exam_results'),
            'oee-student-results',
            array($this, 'render_student_results')
        );

        // Hidden submenu for Preview (not shown in menu but accessible via link)
        add_submenu_page(
            null, // Hide from menu
            olama_exam_translate('Exam Preview'),
            olama_exam_translate('Exam Preview'),
            $this->get_capability('olama_manage_question_bank'),
            'olama-exam-preview',
            array($this, 'render_exam_preview')
        );

        // Hidden submenu for Student Preview
        add_submenu_page(
            null,
            olama_exam_translate('Student Preview'),
            olama_exam_translate('Student Preview'),
            $this->get_capability('olama_manage_question_bank'),
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

    public function render_professions()
    {
        $action = $_GET['action'] ?? '';
        if (in_array($action, array('add', 'edit'))) {
            include OLAMA_EXAM_PATH . 'admin/views/professions-form.php';
        } else {
            include OLAMA_EXAM_PATH . 'admin/views/professions-list.php';
        }
    }

    public function render_acceptance_tests()
    {
        $action = $_GET['action'] ?? '';
        if (in_array($action, array('add', 'edit'))) {
            include OLAMA_EXAM_PATH . 'admin/views/acceptance-tests-form.php';
        } else {
            include OLAMA_EXAM_PATH . 'admin/views/acceptance-tests-list.php';
        }
    }

    public function render_acceptance_results()
    {
        include OLAMA_EXAM_PATH . 'admin/views/acceptance-results.php';
    }

    public function highlight_student_acceptance_menu($submenu_file)
    {
        global $plugin_page;
        if (in_array($plugin_page, array('oee-student-tests', 'oee-student-results', 'olama-exam-categories'))) {
            $submenu_file = 'oee-grade-levels';
        }
        return $submenu_file;
    }

    public function render_grade_levels()
    {
        $action = $_GET['action'] ?? '';
        if (in_array($action, array('add', 'edit'))) {
            include OLAMA_EXAM_PATH . 'admin/views/grade-levels-form.php';
        } else {
            include OLAMA_EXAM_PATH . 'admin/views/grade-levels-list.php';
        }
    }

    public function render_student_tests()
    {
        $action = $_GET['action'] ?? '';
        if (in_array($action, array('add', 'edit'))) {
            include OLAMA_EXAM_PATH . 'admin/views/student-tests-form.php';
        } else {
            include OLAMA_EXAM_PATH . 'admin/views/student-tests-list.php';
        }
    }

    public function render_student_results()
    {
        include OLAMA_EXAM_PATH . 'admin/views/acceptance-student-results.php';
    }

    /**
     * Export acceptance results to CSV
     */
    public function export_acceptance_csv()
    {
        if (!check_admin_referer('oee_export_csv_nonce')) {
            wp_die('Security check failed.');
        }

        $cap = $this->get_capability('olama_view_exam_results');
        if (!current_user_can($cap)) {
            wp_die('Insufficient permissions.');
        }

        global $wpdb;

        $prof_filter   = isset($_POST['profession_id']) ? intval($_POST['profession_id']) : 0;
        $test_filter   = isset($_POST['test_id']) ? intval($_POST['test_id']) : 0;
        $result_filter = isset($_POST['result_status']) ? sanitize_text_field($_POST['result_status']) : '';
        $start_filter  = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_filter    = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        $query = "SELECT ap.name, ap.national_id, ap.phone, ap.email,
                         p.name_ar AS profession,
                         t.title AS test_title,
                         att.score, att.max_score, att.percentage, att.result, att.started_at
                  FROM {$wpdb->prefix}oee_acceptance_applicants ap
                  JOIN {$wpdb->prefix}olama_exam_attempts att ON att.id = ap.attempt_id
                  JOIN {$wpdb->prefix}oee_acceptance_tests t ON t.id = ap.test_id
                  JOIN {$wpdb->prefix}oee_professions p ON p.id = t.profession_id
                  WHERE att.exam_type = 'acceptance'";

        $params = array();

        if ($prof_filter > 0) {
            $query .= " AND t.profession_id = %d";
            $params[] = $prof_filter;
        }
        if ($test_filter > 0) {
            $query .= " AND ap.test_id = %d";
            $params[] = $test_filter;
        }
        if ($result_filter === 'pass') {
            $query .= " AND att.result = 'pass'";
        } elseif ($result_filter === 'fail') {
            $query .= " AND att.result = 'fail'";
        }
        if (!empty($start_filter)) {
            $query .= " AND att.started_at >= %s";
            $params[] = $start_filter . ' 00:00:00';
        }
        if (!empty($end_filter)) {
            $query .= " AND att.started_at <= %s";
            $params[] = $end_filter . ' 23:59:59';
        }

        $query .= " ORDER BY att.started_at DESC";

        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $results = $wpdb->get_results($query);
        }

        $filename = 'acceptance_results_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Add UTF-8 BOM
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');
        fputcsv($output, array(
            olama_exam_translate('Applicant Name'),
            olama_exam_translate('National ID'),
            olama_exam_translate('Phone'),
            olama_exam_translate('Email'),
            olama_exam_translate('Profession'),
            olama_exam_translate('Test Title'),
            olama_exam_translate('Score'),
            olama_exam_translate('Result'),
            olama_exam_translate('Date')
        ));

        foreach ($results as $row) {
            fputcsv($output, array(
                $row->name,
                $row->national_id,
                $row->phone,
                $row->email,
                $row->profession,
                $row->test_title,
                $row->percentage . '% (' . $row->score . '/' . $row->max_score . ')',
                $row->result === 'pass' ? olama_exam_translate('Pass') : olama_exam_translate('Fail'),
                $row->started_at
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Export student acceptance results to CSV
     */
    public function export_student_acceptance_csv()
    {
        if (!check_admin_referer('oee_export_csv_nonce')) {
            wp_die('Security check failed.');
        }

        $cap = $this->get_capability('olama_view_exam_results');
        if (!current_user_can($cap)) {
            wp_die('Insufficient permissions.');
        }

        global $wpdb;

        $grade_filter  = isset($_POST['grade_level_id']) ? intval($_POST['grade_level_id']) : 0;
        $test_filter   = isset($_POST['test_id']) ? intval($_POST['test_id']) : 0;
        $result_filter = isset($_POST['result_status']) ? sanitize_text_field($_POST['result_status']) : '';
        $start_filter  = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_filter    = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        $query = "SELECT ap.student_name, ap.guardian_name, ap.date_of_birth, ap.phone, ap.email, ap.national_id,
                         gl.name_ar AS grade,
                         t.title AS test_title,
                         att.score, att.max_score, att.percentage, att.result, att.started_at
                  FROM {$wpdb->prefix}oee_student_applicants ap
                  JOIN {$wpdb->prefix}olama_exam_attempts att ON att.id = ap.attempt_id
                  JOIN {$wpdb->prefix}oee_student_tests t ON t.id = ap.test_id
                  JOIN {$wpdb->prefix}oee_grade_levels gl ON gl.id = t.grade_level_id
                  WHERE att.exam_type = 'student_acceptance'";

        $params = array();

        if ($grade_filter > 0) {
            $query .= " AND t.grade_level_id = %d";
            $params[] = $grade_filter;
        }
        if ($test_filter > 0) {
            $query .= " AND ap.test_id = %d";
            $params[] = $test_filter;
        }
        if ($result_filter === 'pass') {
            $query .= " AND att.result = 'pass'";
        } elseif ($result_filter === 'fail') {
            $query .= " AND att.result = 'fail'";
        }
        if (!empty($start_filter)) {
            $query .= " AND att.started_at >= %s";
            $params[] = $start_filter . ' 00:00:00';
        }
        if (!empty($end_filter)) {
            $query .= " AND att.started_at <= %s";
            $params[] = $end_filter . ' 23:59:59';
        }

        $query .= " ORDER BY att.started_at DESC";

        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $results = $wpdb->get_results($query);
        }

        $filename = 'student_acceptance_results_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Add UTF-8 BOM
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');
        fputcsv($output, array(
            olama_exam_translate('student_name'),
            olama_exam_translate('guardian_name'),
            olama_exam_translate('student_dob'),
            olama_exam_translate('national_id'),
            olama_exam_translate('phone'),
            olama_exam_translate('email'),
            olama_exam_translate('grade_levels_menu'),
            olama_exam_translate('Test'),
            olama_exam_translate('Score'),
            olama_exam_translate('Result'),
            olama_exam_translate('Date')
        ));

        foreach ($results as $row) {
            fputcsv($output, array(
                $row->student_name,
                $row->guardian_name,
                $row->date_of_birth,
                $row->national_id,
                $row->phone,
                $row->email,
                $row->grade,
                $row->test_title,
                $row->percentage . '% (' . $row->score . '/' . $row->max_score . ')',
                $row->result === 'pass' ? olama_exam_translate('Pass') : olama_exam_translate('Fail'),
                $row->started_at
            ));
        }

        fclose($output);
        exit;
    }

    public function render_categories()
    {
        $action = $_GET['action'] ?? '';
        if (in_array($action, array('add', 'edit'))) {
            include OLAMA_EXAM_PATH . 'admin/views/categories-form.php';
        } else {
            include OLAMA_EXAM_PATH . 'admin/views/categories-list.php';
        }
    }
}
