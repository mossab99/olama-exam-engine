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
    public static function is_direct($exam)
    {
        return $exam && ($exam->exam_type ?? '') === 'direct';
    }

    /** Keep attempts as history, but use the last submitted mark for practice exams. */
    public static function latest_direct_attempt_condition($attempt = 'a', $exam = 'e')
    {
        global $wpdb;
        return " AND ({$exam}.exam_type IS NULL OR {$exam}.exam_type <> 'direct' OR NOT EXISTS (
            SELECT 1 FROM {$wpdb->prefix}olama_exam_attempts newer
            WHERE newer.exam_id = {$attempt}.exam_id AND newer.student_uid = {$attempt}.student_uid
              AND newer.exam_type = 'school' AND newer.is_preview = 0 AND newer.submitted_at IS NOT NULL
              AND (newer.submitted_at > {$attempt}.submitted_at OR (newer.submitted_at = {$attempt}.submitted_at AND newer.id > {$attempt}.id))))";
    }

    /** One aggregate query for the visible exams; previews and applicant tests are excluded. */
    public static function get_exam_summaries($exam_ids)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', $exam_ids))));
        if (!$ids) return array();
        $summaries = array();
        foreach ($ids as $id) {
            $summaries[$id] = array('attempts'=>0, 'graded'=>0, 'passed'=>0, 'failed'=>0, 'pending'=>0,
                'average'=>null, 'highest'=>null, 'lowest'=>null, 'pass_rate'=>null);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $latest = self::latest_direct_attempt_condition();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.exam_id, COUNT(*) AS attempts,
                SUM(CASE WHEN result IN ('pass','fail') THEN 1 ELSE 0 END) AS graded,
                SUM(CASE WHEN result = 'pass' THEN 1 ELSE 0 END) AS passed,
                SUM(CASE WHEN result = 'fail' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN result IN ('pass','fail') THEN 0 ELSE 1 END) AS pending,
                AVG(CASE WHEN result IN ('pass','fail') THEN percentage END) AS average,
                MAX(CASE WHEN result IN ('pass','fail') THEN percentage END) AS highest,
                MIN(CASE WHEN result IN ('pass','fail') THEN percentage END) AS lowest
             FROM {$wpdb->prefix}olama_exam_attempts a JOIN {$wpdb->prefix}olama_exam_exams e ON e.id = a.exam_id
             WHERE a.exam_id IN ($placeholders) AND a.submitted_at IS NOT NULL
               AND a.is_preview = 0 AND a.exam_type = 'school' $latest
             GROUP BY a.exam_id", $ids
        ));
        foreach ((array) $rows as $row) {
            $summary = &$summaries[intval($row->exam_id)];
            foreach (array('attempts', 'graded', 'passed', 'failed', 'pending') as $key) $summary[$key] = intval($row->$key);
            foreach (array('average', 'highest', 'lowest') as $key) $summary[$key] = $row->$key === null ? null : round(floatval($row->$key), 1);
            $summary['pass_rate'] = $summary['graded'] ? round($summary['passed'] / $summary['graded'] * 100, 1) : null;
            unset($summary);
        }
        return $summaries;
    }

    /** Register a clock-based sweep and reconcile overdue exams on every visit. */
    public static function init_lifecycle()
    {
        add_filter('cron_schedules', array(__CLASS__, 'lifecycle_schedules'));
        add_action('olama_exam_close_expired', array(__CLASS__, 'close_expired_exams'));
        if (!wp_next_scheduled('olama_exam_close_expired')) {
            wp_schedule_event(time() + 60, 'olama_exam_minute', 'olama_exam_close_expired');
        }
        self::close_expired_exams();
    }

    public static function lifecycle_schedules($schedules)
    {
        $schedules['olama_exam_minute'] = array('interval' => 60, 'display' => 'Olama exam expiry (every minute)');
        return $schedules;
    }

    public static function close_expired_exams()
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}olama_exam_exams SET status = 'closed'
             WHERE status IN ('published', 'active') AND (exam_type IS NULL OR exam_type <> 'direct')
               AND end_time > '0000-00-00 00:00:00' AND end_time <= %s",
            current_time('mysql')
        ));
    }

    /** Keep every grade selector in the academic system's progression order. */
    public static function get_ordered_grades()
    {
        if (class_exists('Olama_School_Grade')) {
            return array_values(array_filter((array) Olama_School_Grade::get_grades(), function($grade) {
                return !isset($grade->is_active) || (int) $grade->is_active === 1;
            }));
        }
        global $wpdb;
        return (array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}olama_grades WHERE is_active = 1 ORDER BY CAST(grade_level AS SIGNED) ASC, id ASC");
    }
    /** Validate the mandatory class/section scope for the school exam list. */
    public static function get_section_list_scope($request)
    {
        global $wpdb;
        list($year) = olama_exam_get_active_academic_context();
        $grade_id = absint($request['grade_id'] ?? 0);
        $section_id = absint($request['section_id'] ?? 0);
        $semester_id = absint($request['semester_id'] ?? 0);
        if (!$year || !$grade_id || !$section_id || !$semester_id) {
            return new WP_Error('missing_scope', 'اختر الفصل الدراسي والصف والشعبة لعرض الاختبارات.');
        }
        $semesters = class_exists('Olama_School_Academic') ? Olama_School_Academic::get_semesters($year->id) : array();
        if (!in_array($semester_id, array_map('intval', array_column($semesters, 'id')), true)) {
            return new WP_Error('invalid_semester', 'الفصل الدراسي لا يتبع العام الأكاديمي النشط.');
        }
        $section = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_sections WHERE id = %d AND grade_id = %d AND academic_year_id = %d",
            $section_id, $grade_id, $year->id
        ));
        if (!$section) {
            return new WP_Error('invalid_section', 'الشعبة لا تتبع الصف والعام الأكاديمي المحددين.');
        }
        $subject_id = absint($request['subject_id'] ?? 0);
        if ($subject_id && !$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_subjects WHERE id = %d AND grade_id = %d AND is_active = 1", $subject_id, $grade_id
        ))) {
            return new WP_Error('invalid_subject', 'المادة لا تتبع الصف المحدد.');
        }
        return array('academic_year_id' => intval($year->id), 'semester_id' => $semester_id, 'grade_id' => $grade_id, 'section_id' => $section_id);
    }

    public static function get_direct_lesson($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, u.subject_id, u.grade_id, u.semester_id, u.unit_name
             FROM {$wpdb->prefix}olama_curriculum_lessons l
             JOIN {$wpdb->prefix}olama_curriculum_units u ON u.id = l.unit_id WHERE l.id = %d", intval($id)
        ));
    }
    /** Enable results for school exams in an explicit academic scope. */
    public static function publish_results($year_id, $semester_id, $grade_id = 0)
    {
        return self::set_results_visibility($year_id, $semester_id, $grade_id, true);
    }

    public static function hide_results($year_id, $semester_id, $grade_id = 0)
    {
        return self::set_results_visibility($year_id, $semester_id, $grade_id, false);
    }

    private static function set_results_visibility($year_id, $semester_id, $grade_id, $show)
    {
        global $wpdb;
        if (!Olama_Exam_Ajax::can_supervise_exams()) {
            return new WP_Error('forbidden', 'ليس لديك صلاحية التحكم بإظهار النتائج.');
        }
        if (!$year_id || !$semester_id) {
            return new WP_Error('missing_scope', 'يجب تحديد العام والفصل الدراسي النشطين.');
        }
        $visible = $show ? 1 : 0;
        $previous = $show ? 0 : 1;
        $sql = "UPDATE {$wpdb->prefix}olama_exam_exams e
                LEFT JOIN {$wpdb->prefix}olama_sections s ON s.id = e.section_id
                LEFT JOIN {$wpdb->prefix}olama_subjects sub ON sub.id = e.subject_id
                SET e.show_results = {$visible}
                WHERE e.academic_year_id = %d AND e.semester_id = %d
                  AND e.is_placement = 0 AND e.status IN ('published', 'active', 'closed')
                  AND (e.exam_type IN ('exam', 'quiz') OR e.exam_type IS NULL OR e.exam_type = '')
                  AND e.show_results = {$previous}";
        $params = array(absint($year_id), absint($semester_id));
        if ($grade_id) {
            $sql .= ' AND COALESCE(s.grade_id, sub.grade_id) = %d';
            $params[] = absint($grade_id);
        }
        $updated = $wpdb->query($wpdb->prepare($sql, $params));
        return false === $updated ? new WP_Error('update_failed', 'تعذر تحديث إظهار النتائج. حاول مرة أخرى.') : (int) $updated;
    }

    /** Published, read-only result summaries for the current principal's student. */
    public static function get_student_results($student_uid, $scope = array())
    {
        global $wpdb;
        $student_uid = sanitize_text_field((string) $student_uid);
        if (!class_exists('Olama_Exam_Identity') || !Olama_Exam_Identity::can_access_student($student_uid)) {
            return array();
        }

        $query = "SELECT a.id AS attempt_id, a.exam_id, a.attempt_number,
                         a.score, a.max_score, a.percentage, a.result, a.submitted_at,
                         e.title AS exam_title, e.exam_type, sub.subject_name
                  FROM {$wpdb->prefix}olama_exam_attempts a
                  JOIN {$wpdb->prefix}olama_exam_exams e ON e.id = a.exam_id
                  LEFT JOIN {$wpdb->prefix}olama_subjects sub ON sub.id = e.subject_id
                  WHERE a.student_uid = %s AND a.submitted_at IS NOT NULL
                    AND a.is_preview = 0 AND a.exam_type = 'school' AND e.is_placement = 0
                    AND (e.show_results = 1 OR e.exam_type = 'direct') AND e.status IN ('active', 'closed')";
        $query .= self::latest_direct_attempt_condition();
        $params = array($student_uid);
        foreach (array('academic_year_id', 'semester_id') as $key) {
            if (!empty($scope[$key])) {
                $query .= " AND e.{$key} = %d";
                $params[] = absint($scope[$key]);
            }
        }
        $query .= ' ORDER BY a.submitted_at DESC, a.id DESC';
        return (array) $wpdb->get_results($wpdb->prepare($query, $params));
    }

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
                    e.random_difficulty, e.manual_question_ids, e.question_limit, e.show_results, e.is_placement, e.exam_type, e.direct_lesson_id, e.password, e.status, e.created_at,
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
            if (is_array($filters['subject_id'])) {
                $ids = array_map('intval', $filters['subject_id']);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $query .= " AND e.subject_id IN ($placeholders)";
                foreach ($ids as $id) $params[] = $id;
            } else {
                $query .= " AND e.subject_id = %d";
                $params[] = intval($filters['subject_id']);
            }
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
        if (!empty($filters['exam_type'])) {
            if ($filters['exam_type'] === 'exam') {
                $query .= " AND (e.exam_type = 'exam' OR e.exam_type IS NULL OR e.exam_type = '')";
            } else {
                $query .= " AND e.exam_type = %s";
                $params[] = sanitize_text_field($filters['exam_type']);
            }
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
        $exam = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, s.section_name, g.grade_name, g.id as grade_id, sub.subject_name
             FROM {$wpdb->prefix}olama_exam_exams e
             LEFT JOIN {$wpdb->prefix}olama_sections s ON e.section_id = s.id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON s.grade_id = g.id
             LEFT JOIN {$wpdb->prefix}olama_subjects sub ON e.subject_id = sub.id
             WHERE e.id = %d",
            intval($id)
        ));
        if (self::is_direct($exam)) {
            $exam->duration_minutes = 0;
            $exam->max_attempts = 0;
            $exam->show_results = 1;
            $exam->show_correct_answers = 1;
        }
        return $exam;
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
            'show_correct_answers' => intval($data['show_correct_answers'] ?? 0),
            'is_placement' => (isset($data['is_placement']) && ($data['is_placement'] === 'on' || $data['is_placement'] == 1)) ? 1 : 0,
            'exam_type' => sanitize_text_field($data['exam_type'] ?? 'exam'),
            'password' => sanitize_text_field(wp_unslash($data['password'] ?? '')),
            'question_limit' => !empty($data['question_limit']) ? intval($data['question_limit']) : null,
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
            if (!is_array($ids)) {
                return new WP_Error('invalid_questions', 'قائمة الأسئلة غير صالحة.');
            }
            $fields['manual_question_ids'] = json_encode(array_map('intval', $ids));
            $fields['random_count'] = null;
            $fields['random_category_id'] = null;
            $fields['random_unit_id'] = null;
            $fields['random_lesson_id'] = null;
            $fields['random_difficulty'] = null;
        }

        if ($id > 0) {
            // The stored lesson binding cannot be removed by submitting a different exam type.
            $existing = self::get_exam($id);
            if ($existing && !empty($existing->direct_lesson_id)) {
                $data['direct_lesson_id'] = $existing->direct_lesson_id;
                $fields['exam_type'] = 'direct';
            }
        }
        if ($fields['exam_type'] === 'direct') {
            $fields['start_time'] = '1970-01-01 00:00:00';
            $fields['end_time'] = '9999-12-31 23:59:59';
            $fields['duration_minutes'] = 0;
            $fields['max_attempts'] = 0;
            $fields['show_results'] = 1;
            $fields['show_correct_answers'] = 1;
            $lesson = self::get_direct_lesson(intval($data['direct_lesson_id'] ?? 0));
            if (!$lesson || intval($lesson->subject_id) !== $fields['subject_id'] ||
                intval($lesson->semester_id) !== $fields['semester_id'] || $fields['is_placement']) {
                return new WP_Error('invalid_lesson', 'يجب اختيار درس من المادة والفصل الدراسي المحددين.');
            }
            $section_grade = $wpdb->get_var($wpdb->prepare("SELECT grade_id FROM {$wpdb->prefix}olama_sections WHERE id = %d", $fields['section_id']));
            if (intval($section_grade) !== intval($lesson->grade_id)) {
                return new WP_Error('invalid_section', 'الشعبة المحددة لا تتبع صف الدرس.');
            }
            $fields['direct_lesson_id'] = intval($lesson->id);
            if (!in_array($fields['question_mode'], array('manual', 'random'), true)) {
                return new WP_Error('invalid_mode', 'طريقة اختيار الأسئلة غير صالحة.');
            }
            if ($fields['question_mode'] === 'random') {
                $fields['random_lesson_id'] = intval($lesson->id);
                $fields['random_unit_id'] = intval($lesson->unit_id);
                $fields['random_category_id'] = 0;
                $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions WHERE lesson_id = %d AND unit_id = %d";
                $count_params = array($lesson->id, $lesson->unit_id);
                if ($fields['random_difficulty'] !== '') {
                    $count_sql .= ' AND difficulty = %s';
                    $count_params[] = $fields['random_difficulty'];
                }
                $available = intval($wpdb->get_var($wpdb->prepare($count_sql, $count_params)));
                if ($fields['random_count'] < 1 || $fields['random_count'] > $available) {
                    return new WP_Error('invalid_count', 'عدد الأسئلة المطلوب يتجاوز الأسئلة المتاحة لهذا الدرس.');
                }
            } else {
                $ids = array_values(array_unique(array_map('intval', json_decode($fields['manual_question_ids'], true))));
                if (empty($ids)) {
                    return new WP_Error('no_questions', 'اختر سؤالاً واحداً على الأقل من هذا الدرس.');
                }
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $valid = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions WHERE lesson_id = %d AND unit_id = %d AND id IN ($placeholders)",
                    array_merge(array($lesson->id, $lesson->unit_id), $ids)
                )));
                if ($valid !== count($ids)) {
                    return new WP_Error('outside_lesson', 'يمكن اختيار الأسئلة من بنك هذا الدرس فقط.');
                }
                $fields['manual_question_ids'] = json_encode($ids);
            }
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                olama_exam_log('Exam Engine Save Error (Update ID ' . $id . '): ' . $wpdb->last_error . ' | Data: ' . json_encode($fields));
                return new WP_Error('db_error', 'Failed to update exam. ' . $wpdb->last_error);
            }
            return $id;
        } else {
            $fields['status'] = 'draft';
            $fields['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                olama_exam_log('Exam Engine Save Error (Insert): ' . $wpdb->last_error . ' | Data: ' . json_encode($fields));
                return new WP_Error('db_error', 'Failed to create exam. ' . $wpdb->last_error);
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Update only an exam's display title.
     *
     * @return string|WP_Error The sanitized title on success.
     */
    public static function update_title($id, $title)
    {
        global $wpdb;

        $id = intval($id);
        $title = sanitize_text_field($title);

        if ($id <= 0 || !self::get_exam($id)) {
            return new WP_Error('not_found', olama_exam_translate('Exam not found.'));
        }

        if ($title === '') {
            return new WP_Error('empty_title', olama_exam_translate('Exam title is required.'));
        }

        $result = $wpdb->update(
            "{$wpdb->prefix}olama_exam_exams",
            array('title' => $title),
            array('id' => $id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            olama_exam_log('Exam Engine Rename Error (ID ' . $id . '): ' . $wpdb->last_error);
            return new WP_Error('db_error', olama_exam_translate('Failed to update exam name.'));
        }

        return $title;
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

        if ($new_status === 'active' && !self::is_direct($exam) && (empty($exam->end_time) || $exam->end_time <= current_time('mysql'))) {
            return new WP_Error('expired_exam', 'لا يمكن تفعيل امتحان انتهى موعده. حدّث موعد الامتحان أولاً.');
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

        if (!empty($exam->direct_lesson_id)) {
            $lesson = self::get_direct_lesson($exam->direct_lesson_id);
            if (!$lesson || intval($lesson->subject_id) !== intval($exam->subject_id)) {
                return array();
            }
            $exam = clone $exam;
            $exam->random_lesson_id = $lesson->id;
            $exam->random_unit_id = $lesson->unit_id;
        }
        if ($exam->question_mode === 'random') {
            return self::resolve_random_questions($exam);
        }

        $questions = self::resolve_manual_questions($exam);
        if (!empty($exam->direct_lesson_id)) {
            $questions = array_values(array_filter($questions, function($question) use ($lesson) {
                return intval($question->lesson_id) === intval($lesson->id) && intval($question->unit_id) === intval($lesson->unit_id);
            }));
        }
        return $questions;
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
            if (!empty($exam->direct_lesson_id)) {
                $id_query .= " AND q.unit_id = %d";
                $params[] = intval($exam->random_unit_id);
            }
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
        $count = !empty($exam->random_count) ? intval($exam->random_count) : (!empty($exam->question_limit) ? intval($exam->question_limit) : count($all_ids));
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
     * Replicate an exam
     */
    public static function replicate_exam($id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}olama_exam_exams";
        
        $exam = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$exam) {
            return new WP_Error('not_found', 'Exam not found.');
        }

        // Prepare for duplication
        unset($exam['id']);
        $exam['title'] = $exam['title'] . ' (' . olama_exam_translate('Copy') . ')';
        $exam['status'] = 'draft';
        $exam['created_at'] = current_time('mysql');

        $result = $wpdb->insert($table, $exam);
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to replicate exam. ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
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
