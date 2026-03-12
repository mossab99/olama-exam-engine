<?php
/**
 * Exam Engine AJAX Handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Ajax
{
    /**
     * Register all AJAX actions
     */
    public static function init()
    {
        $actions = array(
            // ── Question Management (Phase 2) ──
            'olama_exam_save_question',
            'olama_exam_delete_question',
            'olama_exam_bulk_delete_questions',
            'olama_exam_duplicate_question',
            'olama_exam_upload_question_image',
            'olama_exam_get_questions',

            // ── Import (Phase 2) ──
            'olama_exam_import_gift',
            'olama_exam_import_csv',
            'olama_exam_download_csv_template',

            // ── Question Categories ──
            'olama_exam_save_category',
            'olama_exam_delete_category',
            'olama_exam_get_categories',
            'olama_exam_get_subjects_by_grade',
            'olama_exam_get_sections_by_grade',
            'olama_exam_get_units_by_subject',
            'olama_exam_get_exam_schedule_info',

            // ── Exam Management (Phase 3) ──
            'olama_exam_save_exam',
            'olama_exam_delete_exam',
            'olama_exam_update_status',
            'olama_exam_preview',
            'olama_exam_get_exams',

            // ── Student Exam Engine (Phase 4) ──
            'olama_exam_start',
            'olama_exam_autosave',
            'olama_exam_submit',
            'olama_exam_resume',
            'olama_exam_grade_essay',

            // ── Image Streaming ──
            'olama_exam_stream_image',

            // ── Reporting (Phase 5) ──
            'olama_exam_get_question_stats',
            'olama_exam_delete_attempt',
            'olama_exam_retake_attempt',
        );

        foreach ($actions as $action) {
            $method = str_replace('olama_exam_', 'handle_', $action);
            add_action("wp_ajax_{$action}", array(__CLASS__, $method));

            // Some actions also need nopriv for logged-in students on the frontend
            $nopriv_actions = array(
                'olama_exam_start',
                'olama_exam_autosave',
                'olama_exam_submit',
                'olama_exam_resume',
                'olama_exam_stream_image',
            );
            if (in_array($action, $nopriv_actions)) {
                add_action("wp_ajax_nopriv_{$action}", array(__CLASS__, $method));
            }
        }
    }

    /**
     * Verify nonce and user permissions
     */
    private static function verify_request($capability = 'manage_options')
    {
        if (!check_ajax_referer('olama_exam_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'), 403);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'), 401);
        }

        if ($capability && !current_user_can($capability)) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }
    }

    // ── Category Handlers ──────────────────────────────────────

    public static function handle_get_categories()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_question_categories";
        $categories = $wpdb->get_results("SELECT c.*, s.subject_name 
            FROM $table c
            LEFT JOIN {$wpdb->prefix}olama_subjects s ON c.subject_id = s.id
            ORDER BY c.name ASC");

        wp_send_json_success($categories);
    }

    public static function handle_save_category()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_question_categories";

        $id = intval($_POST['id'] ?? 0);
        $data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'subject_id' => intval($_POST['subject_id'] ?? 0),
            'language' => sanitize_text_field($_POST['language'] ?? 'ar'),
        );

        if (empty($data['name'])) {
            wp_send_json_error(array('message' => 'Category name is required.'));
        }

        if ($id > 0) {
            $wpdb->update($table, $data, array('id' => $id));
            wp_send_json_success(array('id' => $id, 'message' => olama_exam_translate('Category updated.')));
        } else {
            $wpdb->insert($table, $data);
            wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => olama_exam_translate('Category created.')));
        }
    }

    public static function handle_delete_category()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(array('message' => 'Invalid category ID.'));
        }

        // Check if category has questions
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions WHERE category_id = %d",
            $id
        ));

        if ($count > 0) {
            wp_send_json_error(array(
                'message' => sprintf(
                    olama_exam_translate('Cannot delete: this category has %d question(s).'),
                    $count
                )
            ));
        }

        $wpdb->delete("{$wpdb->prefix}olama_exam_question_categories", array('id' => $id));
        wp_send_json_success(array('message' => olama_exam_translate('Category deleted.')));
    }

    // ── Image Streaming ────────────────────────────────────────

    public static function handle_get_subjects_by_grade()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $grade_id = intval($_POST['grade_id'] ?? 0);
        if ($grade_id <= 0) {
            wp_send_json_success(array());
        }

        $subjects = $wpdb->get_results($wpdb->prepare(
            "SELECT id, subject_name FROM {$wpdb->prefix}olama_subjects WHERE grade_id = %d AND is_active = 1 ORDER BY subject_name ASC",
            $grade_id
        ));

        wp_send_json_success($subjects);
    }

    public static function handle_get_sections_by_grade()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $grade_id = intval($_POST['grade_id'] ?? 0);
        if ($grade_id <= 0) {
            wp_send_json_success(array());
        }

        // olama_sections has no is_active column; filter by grade + active academic year
        $active_year = Olama_School_Academic::get_active_year();
        $year_id = $active_year ? $active_year->id : 0;

        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT id, section_name FROM {$wpdb->prefix}olama_sections WHERE grade_id = %d AND academic_year_id = %d ORDER BY section_name ASC",
            $grade_id,
            $year_id
        ));

        wp_send_json_success($sections);
    }

    public static function handle_get_units_by_subject()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $grade_id = intval($_POST['grade_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);

        if ($grade_id <= 0 || $subject_id <= 0) {
            wp_send_json_success(array());
        }

        // Get the active semester
        $active_semester = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}olama_semesters WHERE is_active = 1 LIMIT 1");
        $semester_id = $active_semester ? $active_semester->id : 0;

        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT cu.id, cu.unit_number, cu.unit_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions q WHERE q.unit_id = cu.id) as question_count
             FROM {$wpdb->prefix}olama_curriculum_units cu
             WHERE cu.grade_id = %d AND cu.subject_id = %d AND cu.semester_id = %d
             ORDER BY cu.unit_number ASC",
            $grade_id,
            $subject_id,
            $semester_id
        ));

        wp_send_json_success($units);
    }

    /**
     * Get SIS exam schedule info for a grade + subject.
     * Returns: active exam name, exam date, auto-title, and unit IDs from exam material.
     */
    public static function handle_get_exam_schedule_info()
    {
        self::verify_request('manage_options');

        global $wpdb;
        $grade_id   = intval($_POST['grade_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $section_id = intval($_POST['section_id'] ?? 0);

        if ($grade_id <= 0 || $subject_id <= 0) {
            wp_send_json_error('Missing grade or subject');
        }

        // Get active academic context
        $active_year     = Olama_School_Academic::get_active_year();
        $active_semester = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}olama_semesters WHERE is_active = 1 LIMIT 1");

        if (!$active_year || !$active_semester) {
            wp_send_json_error('No active year or semester');
        }

        // Get active semester exam (التقويم)
        $semester_exam = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_semester_exams
             WHERE semester_id = %d AND (grade_id = %d OR grade_id IS NULL) AND is_active = 1
             ORDER BY grade_id DESC
             LIMIT 1",
            $active_semester->id,
            $grade_id
        ));

        if (!$semester_exam) {
            wp_send_json_error('No active exam schedule found');
        }

        // Get the SIS exam entry for this grade + subject + semester_exam
        $sis_exam = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exams
             WHERE academic_year_id = %d AND semester_id = %d AND semester_exam_id = %d
                   AND grade_id = %d AND subject_id = %d
             LIMIT 1",
            $active_year->id,
            $active_semester->id,
            $semester_exam->id,
            $grade_id,
            $subject_id
        ));

        // Get names for auto-title
        $grade_name   = $wpdb->get_var($wpdb->prepare("SELECT grade_name FROM {$wpdb->prefix}olama_grades WHERE id = %d", $grade_id));
        $subject_name = $wpdb->get_var($wpdb->prepare("SELECT subject_name FROM {$wpdb->prefix}olama_subjects WHERE id = %d", $subject_id));
        $section_name = '';
        if ($section_id > 0) {
            $section_name = $wpdb->get_var($wpdb->prepare("SELECT section_name FROM {$wpdb->prefix}olama_sections WHERE id = %d", $section_id));
        }

        // Build auto-title
        $title_parts = array_filter([
            $active_year->year_name,
            $active_semester->semester_name,
            $semester_exam->exam_name,
            $grade_name,
            $section_name,
            $subject_name,
        ]);
        $auto_title = implode(' - ', $title_parts);

        // Extract unit IDs from exam_material_json
        $material_unit_ids = [];
        if ($sis_exam && !empty($sis_exam->exam_material_json)) {
            $material = json_decode($sis_exam->exam_material_json, true);
            if (isset($material['curriculum_items']) && is_array($material['curriculum_items'])) {
                foreach ($material['curriculum_items'] as $item) {
                    if (!empty($item['unit_id']) && !in_array(intval($item['unit_id']), $material_unit_ids)) {
                        $material_unit_ids[] = intval($item['unit_id']);
                    }
                }
            }
        }

        // Fetch unit details for those IDs
        $material_units = [];
        if (!empty($material_unit_ids)) {
            $ids_str = implode(',', $material_unit_ids);
            $material_units = $wpdb->get_results(
                "SELECT cu.id, cu.unit_number, cu.unit_name,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions q WHERE q.unit_id = cu.id) as question_count
                 FROM {$wpdb->prefix}olama_curriculum_units cu
                 WHERE cu.id IN ($ids_str)
                 ORDER BY cu.unit_number ASC"
            );
        }

        wp_send_json_success([
            'auto_title'      => $auto_title,
            'exam_date'       => $sis_exam ? $sis_exam->exam_date : null,
            'semester_exam'   => [
                'id'         => $semester_exam->id,
                'exam_name'  => $semester_exam->exam_name,
                'start_date' => $semester_exam->start_date,
                'end_date'   => $semester_exam->end_date,
            ],
            'material_unit_ids' => $material_unit_ids,
            'material_units'    => $material_units,
            'sis_exam_id'       => $sis_exam ? $sis_exam->id : null,
        ]);
    }

    public static function handle_stream_image()
    {
        $file = sanitize_file_name($_GET['file'] ?? '');
        if (empty($file)) {
            wp_die('No image specified.');
        }
        Olama_Exam_Question_Images::stream_image($file);
    }

    // ── Question Handlers ───────────────────────────────────────

    public static function handle_save_question()
    {
        self::verify_request('manage_options');

        $data = array(
            'id' => intval($_POST['id'] ?? 0),
            'category_id' => intval($_POST['category_id'] ?? 0),
            'unit_id' => intval($_POST['unit_id'] ?? 0),
            'type' => sanitize_text_field($_POST['type'] ?? 'mcq'),
            'question_text' => wp_kses_post($_POST['question_text'] ?? ''),
            'answers_json' => wp_unslash($_POST['answers_json'] ?? '{}'),
            'difficulty' => sanitize_text_field($_POST['difficulty'] ?? 'medium'),
            'language' => sanitize_text_field($_POST['language'] ?? 'ar'),
            'explanation' => sanitize_textarea_field($_POST['explanation'] ?? ''),
            'image_filename' => sanitize_file_name($_POST['image_filename'] ?? ''),
        );

        $result = Olama_Exam_Questions::save_question($data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'id' => $result,
            'message' => $data['id'] > 0 ? olama_exam_translate('Question updated.') : olama_exam_translate('Question created.'),
        ));
    }

    public static function handle_delete_question()
    {
        self::verify_request('manage_options');

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(array('message' => 'Invalid question ID.'));
        }

        $result = Olama_Exam_Questions::delete_question($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => olama_exam_translate('Question deleted.')));
    }

    public static function handle_bulk_delete_questions()
    {
        self::verify_request('manage_options');

        $ids = array_map('intval', $_POST['ids'] ?? array());
        if (empty($ids)) {
            wp_send_json_error(array('message' => 'No questions selected.'));
        }

        $deleted = Olama_Exam_Questions::bulk_delete($ids);
        wp_send_json_success(array(
            'deleted' => $deleted,
            'message' => sprintf(olama_exam_translate('%d question(s) deleted.'), $deleted),
        ));
    }

    public static function handle_duplicate_question()
    {
        self::verify_request('manage_options');

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(array('message' => 'Invalid question ID.'));
        }

        $new_id = Olama_Exam_Questions::duplicate_question($id);
        if (is_wp_error($new_id)) {
            wp_send_json_error(array('message' => $new_id->get_error_message()));
        }

        wp_send_json_success(array(
            'id' => $new_id,
            'message' => olama_exam_translate('Question duplicated.'),
        ));
    }

    public static function handle_upload_question_image()
    {
        self::verify_request('manage_options');

        if (empty($_FILES['question_image'])) {
            wp_send_json_error(array('message' => 'No file uploaded.'));
        }

        $result = Olama_Exam_Question_Images::handle_upload($_FILES['question_image']);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'filename' => $result,
            'image_url' => Olama_Exam_Question_Images::get_image_url($result),
            'message' => olama_exam_translate('Image uploaded.'),
        ));
    }

    public static function handle_get_questions()
    {
        self::verify_request('manage_options');

        $filters = array(
            'id' => intval($_POST['id'] ?? 0),
            'category_id' => intval($_POST['category_id'] ?? 0),
            'unit_id' => intval($_POST['unit_id'] ?? 0),
            'type' => sanitize_text_field($_POST['type'] ?? ''),
            'difficulty' => sanitize_text_field($_POST['difficulty'] ?? ''),
            'language' => sanitize_text_field($_POST['language'] ?? ''),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
        );

        // Remove empty filters
        $filters = array_filter($filters);

        $questions = Olama_Exam_Questions::get_questions($filters);

        // Add image URLs
        foreach ($questions as &$q) {
            $q->image_url = !empty($q->image_filename)
                ? Olama_Exam_Question_Images::get_image_url($q->image_filename)
                : '';
            $q->answers_decoded = json_decode($q->answers_json, true);
        }

        wp_send_json_success($questions);
    }

    // ── Import Handlers ────────────────────────────────────────

    public static function handle_import_gift()
    {
        self::verify_request('manage_options');

        $category_id = intval($_POST['category_id'] ?? 0);
        $unit_id = intval($_POST['unit_id'] ?? 0);
        $language = sanitize_text_field($_POST['language'] ?? 'ar');
        $difficulty = sanitize_text_field($_POST['difficulty'] ?? 'medium');
        $mode = sanitize_text_field($_POST['mode'] ?? 'preview'); // preview|import

        if (empty($_POST['gift_content'])) {
            wp_send_json_error(array('message' => 'No GIFT content provided.'));
        }

        $content = wp_unslash($_POST['gift_content']);
        $parsed = Olama_Exam_Gift_Parser::parse($content);

        if ($mode === 'preview') {
            wp_send_json_success(array(
                'questions' => $parsed['questions'],
                'errors' => $parsed['errors'],
                'count' => count($parsed['questions']),
            ));
        }

        if ($unit_id <= 0) {
            wp_send_json_error(array('message' => olama_exam_translate('Please select a unit for import.')));
        }

        $result = Olama_Exam_Gift_Parser::import($parsed, $category_id, $language, $difficulty, $unit_id);
        wp_send_json_success($result);
    }

    public static function handle_import_csv()
    {
        self::verify_request('manage_options');

        $category_id = intval($_POST['category_id'] ?? 0);
        $unit_id = intval($_POST['unit_id'] ?? 0);
        $mode = sanitize_text_field($_POST['mode'] ?? 'preview');

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error(array('message' => 'No CSV file uploaded.'));
        }

        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'File upload error.'));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error(array('message' => 'Only .csv files are allowed.'));
        }

        $parsed = Olama_Exam_Csv_Parser::parse($file['tmp_name']);

        if ($mode === 'preview') {
            wp_send_json_success(array(
                'questions' => $parsed['questions'],
                'errors' => $parsed['errors'],
                'count' => count($parsed['questions']),
            ));
        }

        if ($unit_id <= 0) {
            wp_send_json_error(array('message' => olama_exam_translate('Please select a unit for import.')));
        }

        $result = Olama_Exam_Csv_Parser::import($parsed, $category_id, $unit_id);
        wp_send_json_success($result);
    }

    public static function handle_download_csv_template()
    {
        self::verify_request('manage_options');

        $path = Olama_Exam_Csv_Parser::get_template_path();
        if (!file_exists($path)) {
            wp_die('Template file not found.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="questions-csv-template.csv"');
        // Add UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        readfile($path);
        exit;
    }

    // ── Exam Management Handlers (Phase 3) ────────────────────

    public static function handle_save_exam()
    {
        self::verify_request('manage_options');

        $result = Olama_Exam_Manager::save_exam($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => olama_exam_translate('Exam saved successfully.'),
            'id' => $result,
        ));
    }

    public static function handle_delete_exam()
    {
        self::verify_request('manage_options');

        $id = intval($_POST['id'] ?? 0);
        $result = Olama_Exam_Manager::delete_exam($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => olama_exam_translate('Exam deleted.')));
    }

    public static function handle_update_status()
    {
        self::verify_request('manage_options');

        $id = intval($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');

        $result = Olama_Exam_Manager::update_status($id, $status);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => olama_exam_translate('Status updated.'),
            'status' => $status,
        ));
    }

    public static function handle_preview()
    {
        self::verify_request('manage_options');

        $id = intval($_POST['id'] ?? 0);
        $result = Olama_Exam_Manager::preview_exam($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    public static function handle_get_exams()
    {
        self::verify_request('manage_options');

        $filters = array();
        if (!empty($_POST['grade_id']))
            $filters['grade_id'] = intval($_POST['grade_id']);
        if (!empty($_POST['section_id']))
            $filters['section_id'] = intval($_POST['section_id']);
        if (!empty($_POST['subject_id']))
            $filters['subject_id'] = intval($_POST['subject_id']);
        if (!empty($_POST['status']))
            $filters['status'] = sanitize_text_field($_POST['status']);
        if (!empty($_POST['search']))
            $filters['search'] = sanitize_text_field($_POST['search']);
        if (!empty($_POST['academic_year_id']))
            $filters['academic_year_id'] = intval($_POST['academic_year_id']);
        if (!empty($_POST['semester_id']))
            $filters['semester_id'] = intval($_POST['semester_id']);

        $exams = Olama_Exam_Manager::get_exams($filters);

        // Add attempt count to each exam
        foreach ($exams as &$exam) {
            $exam->attempt_count = Olama_Exam_Manager::get_attempt_count($exam->id);
            $exam->question_count = count(Olama_Exam_Manager::get_exam_questions($exam->id));
        }

        wp_send_json_success($exams);
    }

    // ── Student Engine Handlers (Phase 4) ─────────────────────

    public static function handle_start()
    {
        self::verify_request(null);

        $exam_id = intval($_POST['exam_id'] ?? 0);
        $student_uid = sanitize_text_field($_POST['student_uid'] ?? '');
        $is_preview = !empty($_POST['is_preview']) && current_user_can('manage_options');

        if (empty($student_uid) && !$is_preview) {
            wp_send_json_error(array('message' => 'Student ID is required.'));
        }

        // Security: if not admin, verify student belongs to family
        if (!current_user_can('manage_options')) {
            global $wpdb;
            $family_id = wp_get_current_user()->user_login;
            $is_member = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_students WHERE student_uid = %s AND family_id = %s",
                $student_uid,
                $family_id
            ));
            if (!$is_member) {
                wp_send_json_error(array('message' => 'Permission denied. Student does not belong to your family.'));
            }
        }

        $is_admin_override = current_user_can('manage_options');
        $result = Olama_Exam_Engine::start_exam($exam_id, $student_uid, $is_preview, $is_admin_override);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    public static function handle_autosave()
    {
        self::verify_request(null);

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        $student_uid = sanitize_text_field($_POST['student_uid'] ?? '');
        $answers_json = wp_unslash($_POST['answers_json'] ?? '{}');

        if (empty($student_uid)) {
            wp_send_json_error(array('message' => 'Student ID is required.'));
        }

        // Security: if not admin, verify student belongs to family
        if (!current_user_can('manage_options')) {
            global $wpdb;
            $family_id = wp_get_current_user()->user_login;
            $is_member = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_students WHERE student_uid = %s AND family_id = %s",
                $student_uid,
                $family_id
            ));
            if (!$is_member) {
                wp_send_json_error(array('message' => 'Permission denied. Student does not belong to your family.'));
            }
        }

        // DEBUG: Log received autosave
        error_log("Olama Exam Debug: Autosave hit. Attempt: " . $attempt_id . " Answers: " . $answers_json);

        $result = Olama_Exam_Engine::autosave($attempt_id, $answers_json);

        if (is_wp_error($result)) {
            error_log("Olama Exam Debug: Autosave error: " . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    public static function handle_submit()
    {
        self::verify_request(null);

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        $student_uid = sanitize_text_field($_POST['student_uid'] ?? '');

        if (empty($student_uid)) {
            wp_send_json_error(array('message' => 'Student ID is required.'));
        }

        // Security: if not admin, verify student belongs to family
        if (!current_user_can('manage_options')) {
            global $wpdb;
            $family_id = wp_get_current_user()->user_login;
            $is_member = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_students WHERE student_uid = %s AND family_id = %s",
                $student_uid,
                $family_id
            ));
            if (!$is_member) {
                wp_send_json_error(array('message' => 'Permission denied. Student does not belong to your family.'));
            }
        }

        error_log("Olama Exam Debug: Submit hit. Attempt: " . $attempt_id . " Student: " . $student_uid);

        $result = Olama_Exam_Engine::submit($attempt_id);

        if (is_wp_error($result)) {
            error_log("Olama Exam Debug: Submit error: " . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    public static function handle_resume()
    {
        self::verify_request(null);

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        $result = Olama_Exam_Engine::resume_exam($attempt_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    public static function handle_grade_essay()
    {
        self::verify_request('manage_options');

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        $question_id = intval($_POST['question_id'] ?? 0);
        $score = floatval($_POST['score'] ?? 0);
        $comment = sanitize_textarea_field($_POST['comment'] ?? '');

        $result = Olama_Exam_Grader::grade_essay($attempt_id, $question_id, $score, $comment);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => olama_exam_translate('Essay graded.'),
            'result' => $result,
        ));
    }

    public static function handle_delete_attempt()
    {
        self::verify_request('manage_options');

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        if ($attempt_id <= 0) {
            wp_send_json_error(array('message' => 'Invalid attempt ID.'));
        }

        global $wpdb;
        // Delete essay grades first due to FK or logic
        $wpdb->delete("{$wpdb->prefix}olama_exam_essay_grades", array('attempt_id' => $attempt_id));
        $result = $wpdb->delete("{$wpdb->prefix}olama_exam_attempts", array('id' => $attempt_id));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete attempt.'));
        }

        wp_send_json_success(array('message' => olama_exam_translate('Attempt deleted.')));
    }

    public static function handle_retake_attempt()
    {
        // For now, retake is just delete and let them start over
        self::handle_delete_attempt();
    }

    // ── Reporting Handlers (Phase 5 stubs) ─────────────────────

    public static function handle_get_results()
    {
        self::verify_request('manage_options');

        $exam_id = intval($_POST['exam_id'] ?? 0);

        global $wpdb;
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name as student_name
             FROM {$wpdb->prefix}olama_exam_attempts a
             LEFT JOIN {$wpdb->users} u ON a.student_id = u.ID
             WHERE a.exam_id = %d AND a.submitted_at IS NOT NULL
             ORDER BY a.percentage DESC",
            $exam_id
        ));

        wp_send_json_success(array('attempts' => $attempts));
    }

    public static function handle_export_csv()
    {
        self::verify_request('manage_options');

        $exam_id = intval($_POST['exam_id'] ?? 0);

        global $wpdb;
        $exam = Olama_Exam_Manager::get_exam($exam_id);
        if (!$exam) {
            wp_send_json_error(array('message' => 'Exam not found.'));
        }

        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name as student_name
             FROM {$wpdb->prefix}olama_exam_attempts a
             LEFT JOIN {$wpdb->users} u ON a.student_id = u.ID
             WHERE a.exam_id = %d AND a.submitted_at IS NOT NULL
             ORDER BY a.percentage DESC",
            $exam_id
        ));

        // Build CSV
        $rows = array();
        $rows[] = implode(',', array(
            olama_exam_translate('Student'),
            olama_exam_translate('Score'),
            'Max',
            olama_exam_translate('Percentage'),
            olama_exam_translate('Result'),
            olama_exam_translate('Submitted At'),
        ));

        foreach ($attempts as $a) {
            $rows[] = implode(',', array(
                '"' . str_replace('"', '""', $a->student_name ?? 'ID: ' . $a->student_id) . '"',
                $a->score ?? 0,
                $a->max_score ?? 0,
                ($a->percentage ?? 0) . '%',
                $a->result,
                date('Y-m-d H:i', strtotime($a->submitted_at)),
            ));
        }

        $csv_content = implode("\n", $rows);
        $filename = sanitize_file_name($exam->title) . '_results_' . date('Y-m-d') . '.csv';

        wp_send_json_success(array(
            'csv' => $csv_content,
            'filename' => $filename,
        ));
    }

    public static function handle_get_question_stats()
    {
        self::verify_request('manage_options');

        $exam_id = intval($_POST['exam_id'] ?? 0);

        global $wpdb;
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT questions_snapshot_json, answers_json
             FROM {$wpdb->prefix}olama_exam_attempts
             WHERE exam_id = %d AND submitted_at IS NOT NULL",
            $exam_id
        ));

        $stats = array();
        foreach ($attempts as $a) {
            $snapshot = json_decode($a->questions_snapshot_json, true) ?: array();
            $answers = json_decode($a->answers_json, true) ?: array();

            foreach ($snapshot as $q) {
                $qid = $q['question_id'];
                if (!isset($stats[$qid])) {
                    $stats[$qid] = array(
                        'question_id' => $qid,
                        'text' => mb_substr(strip_tags($q['question_text']), 0, 100),
                        'type' => $q['type'],
                        'total' => 0,
                        'correct' => 0,
                        'incorrect' => 0,
                    );
                }
                $stats[$qid]['total']++;
                $sa = $answers[$qid] ?? null;
                if ($q['type'] !== 'essay' && $sa !== null && $sa !== '') {
                    $earned = Olama_Exam_Grader::grade_question($q['type'], $sa, $q['correct'] ?? array(), $q['points'] ?? 1);
                    if ($earned >= ($q['points'] ?? 1)) {
                        $stats[$qid]['correct']++;
                    } else {
                        $stats[$qid]['incorrect']++;
                    }
                }
            }
        }

        wp_send_json_success(array('stats' => array_values($stats)));
    }
}
