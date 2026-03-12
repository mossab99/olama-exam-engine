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
    public static function get_exams($filters = array())
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_exams";

        $query = "SELECT e.*, 
                    s.section_name, g.grade_name, sub.subject_name,
                    u.display_name as teacher_name
                  FROM $table e
                  LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
                  LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
                  LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
                  LEFT JOIN {$wpdb->prefix}users u ON e.teacher_id = u.ID
                  WHERE 1=1";
        $params = array();

        if (!empty($filters['grade_id'])) {
            $query .= " AND s.grade_id = %d";
            $params[] = intval($filters['grade_id']);
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

        $fields = array(
            'title' => sanitize_text_field($data['title'] ?? ''),
            'section_id' => intval($data['section_id'] ?? 0),
            'subject_id' => intval($data['subject_id'] ?? 0),
            'teacher_id' => intval($data['teacher_id'] ?? get_current_user_id()),
            'academic_year_id' => intval($data['academic_year_id'] ?? 0),
            'semester_id' => intval($data['semester_id'] ?? 0),
            'start_time' => sanitize_text_field($data['start_time'] ?? ''),
            'end_time' => sanitize_text_field($data['end_time'] ?? ''),
            'duration_minutes' => intval($data['duration_minutes'] ?? 60),
            'passing_grade' => intval($data['passing_grade'] ?? 50),
            'max_attempts' => intval($data['max_attempts'] ?? 1),
            'question_mode' => sanitize_text_field($data['question_mode'] ?? 'manual'),
            'show_results' => intval($data['show_results'] ?? 0),
        );

        // Validate required
        if (empty($fields['title'])) {
            return new WP_Error('empty_title', olama_exam_translate('Exam title is required.'));
        }
        if (empty($fields['section_id'])) {
            return new WP_Error('empty_section', olama_exam_translate('Section is required.'));
        }

        // Random mode fields
        if ($fields['question_mode'] === 'random') {
            $fields['random_count'] = intval($data['random_count'] ?? 10);
            $fields['random_category_id'] = intval($data['random_category_id'] ?? 0);
            $fields['random_unit_id'] = intval($data['random_unit_id'] ?? 0);
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
            $fields['random_difficulty'] = null;
        }

        if ($id > 0) {
            $wpdb->update($table, $fields, array('id' => $id));
            return $id;
        } else {
            $fields['status'] = 'draft';
            $fields['created_at'] = current_time('mysql');
            $wpdb->insert($table, $fields);
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
     * Resolve random question selection from a category
     */
    private static function resolve_random_questions($exam)
    {
        global $wpdb;
        $query = "SELECT q.*, cu.unit_name, cu.unit_number 
                  FROM {$wpdb->prefix}olama_exam_questions q
                  LEFT JOIN {$wpdb->prefix}olama_curriculum_units cu ON q.unit_id = cu.id
                  WHERE 1=1";
        $params = array();

        if (!empty($exam->random_unit_id)) {
            $query .= " AND q.unit_id = %d";
            $params[] = intval($exam->random_unit_id);
        } elseif (!empty($exam->random_category_id)) {
            // Legacy fallback
            $query .= " AND q.category_id = %d";
            $params[] = intval($exam->random_category_id);
        }
        if (!empty($exam->random_difficulty)) {
            $query .= " AND q.difficulty = %s";
            $params[] = $exam->random_difficulty;
        }

        $query .= " ORDER BY RAND()";

        if (!empty($exam->random_count)) {
            $query .= " LIMIT %d";
            $params[] = intval($exam->random_count);
        }

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        return $wpdb->get_results($query);
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
