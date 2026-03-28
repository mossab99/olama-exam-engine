<?php
/**
 * Exam Manager — CRUD + Lifecycle
 * Handles exam creation, editing, deletion, status transitions, and question resolution.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Manager
{
    /**
     * Valid status transitions
     */
    private static $transitions = array(
        'draft' => array('published'),
        'published' => array('active', 'draft'),
        'active' => array('closed'),
        'closed' => array('draft'),
    );

    /**
     * Get exams with optional filters
     */
    public static function get_exams($filters = array(), $include_counts = false)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_exams";

        // Select specific columns to avoid fetching LONGTEXT (manual_question_ids) in list views
        $columns = "e.id, e.title, e.section_id, e.subject_id, e.teacher_id,
                    e.academic_year_id, e.semester_id,
                    e.start_time, e.end_time, e.duration_minutes, e.passing_grade,
                    e.max_attempts, e.question_mode, e.random_count,
                    e.random_category_id, e.random_unit_id, e.random_lesson_id,
                    e.random_difficulty, e.show_results, e.is_placement, e.status, e.created_at,
                    s.section_name, COALESCE(g.grade_name, g2.grade_name) as grade_name, sub.subject_name,
                    u.display_name as teacher_name";

        if ($include_counts) {
            $columns .= ",
                    (SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts a WHERE a.exam_id = e.id) as attempt_count";
        }

        $query = "SELECT $columns
                  FROM $table e
                  LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
                  LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
                  LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
                  LEFT JOIN {$wpdb->prefix}olama_grades g2 ON sub.grade_id = g2.id
                  LEFT JOIN {$wpdb->users} u ON e.teacher_id = u.ID
                  WHERE 1=1";
        $params = array();

        if (!empty($filters['grade_id'])) {
            // If section_id is provided, the section filter is already specific enough.
            // We only add grade-level filtering if section_id is not specified.
            if (empty($filters['section_id'])) {
                $query .= " AND (s.grade_id = %d OR sub.grade_id = %d)";
                $params[] = intval($filters['grade_id']);
                $params[] = intval($filters['grade_id']);
            }
        }
        if (!empty($filters['section_id'])) {
            $query .= " AND e.section_id = %d";
            $params[] = intval($filters['section_id']);
        }
        if (!empty($filters['subject_id'])) {
            $query .= " AND e.subject_id = %d";
            $params[] = intval($filters['subject_id']);
        }
        if (!empty($filters['teacher_id'])) {
            $query .= " AND e.teacher_id = %d";
            $params[] = intval($filters['teacher_id']);
        }
        if (!empty($filters['status'])) {
            $query .= " AND e.status = %s";
            $params[] = sanitize_text_field($filters['status']);
        }
        if (!empty($filters['academic_year_id'])) {
            $query .= " AND e.academic_year_id = %d";
            $params[] = intval($filters['academic_year_id']);
        }
        if (!empty($filters['semester_id'])) {
            $query .= " AND e.semester_id = %d";
            $params[] = intval($filters['semester_id']);
        }
        if (isset($filters['is_placement'])) {
            $query .= " AND e.is_placement = %d";
            $params[] = intval($filters['is_placement']);
        }
        if (!empty($filters['search'])) {
            $query .= " AND e.title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $query .= " ORDER BY e.created_at DESC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        return $wpdb->get_results($query);
    }

    /**
     * Get a single exam by ID
     */
    public static function get_exam($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, s.section_name, g.grade_name, g.id as grade_id, sub.subject_name
             FROM {$wpdb->prefix}olama_exam_exams e
             LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
             LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
             WHERE e.id = %d",
            intval($id)
        ));
    }

    /**
     * Save (create or update) an exam
     */
    public static function save_exam($data)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_exams";
        $id = intval($data['id'] ?? 0);

        $start_time = sanitize_text_field($data['start_time'] ?? '');
        $end_time = sanitize_text_field($data['end_time'] ?? '');
        
        if ($start_time) {
            $st_ts = strtotime($start_time);
            $start_time = $st_ts ? date('Y-m-d H:i:s', $st_ts) : '';
        }
        if ($end_time) {
            $et_ts = strtotime($end_time);
            $end_time = $et_ts ? date('Y-m-d H:i:s', $et_ts) : '';
        }

        $fields = array(
            'title' => sanitize_text_field($data['title'] ?? ''),
            'section_id' => intval($data['section_id'] ?? 0),
            'subject_id' => intval($data['subject_id'] ?? 0),
            'teacher_id' => intval($data['teacher_id'] ?? get_current_user_id() ?: 0),
            'academic_year_id' => intval($data['academic_year_id'] ?? 0),
            'semester_id' => intval($data['semester_id'] ?? 0),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration_minutes' => intval($data['duration_minutes'] ?? 60),
            'passing_grade' => intval($data['passing_grade'] ?? 50),
            'max_attempts' => intval($data['max_attempts'] ?? 1),
            'question_mode' => sanitize_text_field($data['question_mode'] ?? 'manual'),
            'show_results' => intval($data['show_results'] ?? 0),
            'is_placement' => (isset($data['is_placement']) && ($data['is_placement'] === 'on' || $data['is_placement'] == 1)) ? 1 : 0,
        );

        // Validate required
        if (empty($fields['title'])) {
            return new WP_Error('empty_title', olama_exam_translate('Exam title is required.'));
        }
        if (empty($fields['section_id']) && !$fields['is_placement']) {
            return new WP_Error('empty_section', olama_exam_translate('Section is required.'));
        }

        // Random mode fields
        if ($fields['question_mode'] === 'random') {
            $fields['random_count'] = intval($data['random_count'] ?? 10);
            $fields['random_category_id'] = intval($data['random_category_id'] ?? 0);
            $fields['random_unit_id'] = intval($data['random_unit_id'] ?? 0);
            $fields['random_lesson_id'] = intval($data['random_lesson_id'] ?? 0);
            $fields['random_difficulty'] = sanitize_text_field($data['random_difficulty'] ?? '');
            $fields['manual_question_ids'] = null;
        } else {
            // Manual mode: store question IDs as JSON
            $ids = $data['manual_question_ids'] ?? array();
            if (is_string($ids)) {
                $ids = json_decode($ids, true) ?: array();
            }
            $fields['manual_question_ids'] = json_encode(array_map('intval', $ids));
            $fields['random_count'] = null;
            $fields['random_category_id'] = null;
            $fields['random_unit_id'] = null;
            $fields['random_lesson_id'] = null;
            $fields['random_difficulty'] = null;
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                error_log('Exam Engine Save Error (Update ID ' . $id . '): ' . $wpdb->last_error . ' | Data: ' . json_encode($fields));
                return new WP_Error('db_error', 'Failed to update exam. ' . $wpdb->last_error);
            }
            return $id;
        } else {
            $fields['status'] = 'draft';
            $fields['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                error_log('Exam Engine Save Error (Insert): ' . $wpdb->last_error . ' | Data: ' . json_encode($fields));
                return new WP_Error('db_error', 'Failed to create exam. ' . $wpdb->last_error);
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Delete an exam (only if draft and no attempts)
     */
    public static function delete_exam($id)
    {
        global $wpdb;
        $exam = self::get_exam($id);
        if (!$exam) {
            return new WP_Error('not_found', 'Exam not found.');
        }

        // Check for attempts
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_id = %d",
            $id
        ));
        if ($attempts > 0) {
            return new WP_Error('has_attempts', olama_exam_translate('Cannot delete exam with student attempts.'));
        }

        return $wpdb->delete("{$wpdb->prefix}olama_exam_exams", array('id' => intval($id)));
    }

    /**
     * Update exam status with lifecycle validation
     */
    public static function update_status($id, $new_status)
    {
        global $wpdb;
        $exam = self::get_exam($id);
        if (!$exam) {
            return new WP_Error('not_found', 'Exam not found.');
        }

        $current = $exam->status;
        $allowed = self::$transitions[$current] ?? array();

        if (!in_array($new_status, $allowed)) {
            return new WP_Error('invalid_transition', sprintf(
                olama_exam_translate('Cannot change status from %s to %s.'),
                $current,
                $new_status
            ));
        }

        // Validate exam has questions before publishing
        if ($new_status === 'published') {
            $questions = self::get_exam_questions($id);
            if (empty($questions)) {
                return new WP_Error('no_questions', olama_exam_translate('Exam must have questions before publishing.'));
            }
        }

        $wpdb->update(
            "{$wpdb->prefix}olama_exam_exams",
            array('status' => $new_status),
            array('id' => intval($id))
        );

        return true;
    }

    /**
     * Get resolved questions for an exam (handles both manual and random modes)
     */
    public static function get_exam_questions($exam_id)
    {
        global $wpdb;
        $exam = is_object($exam_id) ? $exam_id : self::get_exam($exam_id);
        if (!$exam)
            return array();

        if ($exam->question_mode === 'random') {
            return self::resolve_random_questions($exam);
        }

        return self::resolve_manual_questions($exam);
    }

    /**
     * Resolve manual question selection
     */
    private static function resolve_manual_questions($exam)
    {
        if (empty($exam->manual_question_ids)) {
            return array();
        }

        $ids = json_decode($exam->manual_question_ids, true);
        if (empty($ids) || !is_array($ids)) {
            return array();
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, cu.unit_name, cu.unit_number 
             FROM {$wpdb->prefix}olama_exam_questions q
             LEFT JOIN {$wpdb->prefix}olama_curriculum_units cu ON q.unit_id = cu.id
             WHERE q.id IN ($placeholders) 
             ORDER BY FIELD(q.id, $placeholders)",
            array_merge($ids, $ids)
        ));
    }

    /**
     * Resolve random question selection from a category.
     * Uses a two-step approach: fetch matching IDs first, sample in PHP,
     * then fetch full rows — avoids expensive ORDER BY RAND().
     */
    private static function resolve_random_questions($exam)
    {
        global $wpdb;

        // Step 1: Fetch only matching IDs (lightweight)
        $id_query = "SELECT q.id 
                     FROM {$wpdb->prefix}olama_exam_questions q
                     WHERE 1=1";
        $params = array();

        if (!empty($exam->random_lesson_id)) {
            $id_query .= " AND q.lesson_id = %d";
            $params[] = intval($exam->random_lesson_id);
        } elseif (!empty($exam->random_unit_id)) {
            $id_query .= " AND q.unit_id = %d";
            $params[] = intval($exam->random_unit_id);
        } elseif (!empty($exam->random_category_id)) {
            // Legacy fallback
            $id_query .= " AND q.category_id = %d";
            $params[] = intval($exam->random_category_id);
        }
        if (!empty($exam->random_difficulty)) {
            $id_query .= " AND q.difficulty = %s";
            $params[] = $exam->random_difficulty;
        }

        if (!empty($params)) {
            $all_ids = $wpdb->get_col($wpdb->prepare($id_query, $params));
        } else {
            $all_ids = $wpdb->get_col($id_query);
        }

        if (empty($all_ids)) {
            return array();
        }

        // Step 2: Random sample in PHP (fast, no temp table)
        $count = intval($exam->random_count ?? count($all_ids));
        if ($count >= count($all_ids)) {
            $selected_ids = $all_ids;
            shuffle($selected_ids);
        } else {
            $keys = array_rand($all_ids, $count);
            $keys = is_array($keys) ? $keys : array($keys);
            $selected_ids = array_map(function ($k) use ($all_ids) {
                return $all_ids[$k];
            }, $keys);
            shuffle($selected_ids);
        }

        // Step 3: Fetch full rows for selected IDs only
        $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, cu.unit_name, cu.unit_number 
             FROM {$wpdb->prefix}olama_exam_questions q
             LEFT JOIN {$wpdb->prefix}olama_curriculum_units cu ON q.unit_id = cu.id
             WHERE q.id IN ($placeholders)
             ORDER BY FIELD(q.id, $placeholders)",
            array_merge($selected_ids, $selected_ids)
        ));
    }

    /**
     * Generate a preview of the exam (like a student would see)
     */
    public static function preview_exam($exam_id)
    {
        $exam = self::get_exam($exam_id);
        if (!$exam) {
            return new WP_Error('not_found', 'Exam not found.');
        }

        $questions = self::get_exam_questions($exam_id);

        // Strip correct answers for preview
        $preview_questions = array();
        foreach ($questions as $q) {
            $pq = clone $q;
            $answers = json_decode($pq->answers_json, true) ?: array();

            // Remove correct answer keys
            switch ($pq->type) {
                case 'mcq':
                    unset($answers['correct']);
                    break;
                case 'tf':
                    unset($answers['correct']);
                    break;
                case 'short':
                    unset($answers['answers']);
                    break;
                case 'matching':
                    // Shuffle the right side
                    if (!empty($answers['pairs'])) {
                        $rights = array_column($answers['pairs'], 'right');
                        shuffle($rights);
                        $answers['shuffled_rights'] = $rights;
                    }
                    break;
                case 'ordering':
                    // Shuffle items
                    if (!empty($answers['items'])) {
                        $shuffled = $answers['items'];
                        shuffle($shuffled);
                        $answers['shuffled_items'] = $shuffled;
                        unset($answers['correct_order']);
                    }
                    break;
                case 'fill_blank':
                    unset($answers['answers']);
                    break;
                case 'essay':
                    break;
            }

            $pq->answers_json = json_encode($answers);
            $preview_questions[] = $pq;
        }

        return array(
            'exam' => $exam,
            'questions' => $preview_questions,
            'count' => count($preview_questions),
        );
    }

    /**
     * Get attempt count for an exam
     */
    public static function get_attempt_count($exam_id)
    {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_id = %d",
            $exam_id
        )));
    }
}
