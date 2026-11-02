<?php
/** Scoped, repeatable bulk actions for lesson practice exams. */
if (!defined('ABSPATH')) { exit; }

class Olama_Exam_Direct_Bulk
{
    public static function plan($request)
    {
        global $wpdb;
        list($year, $semester) = olama_exam_get_active_academic_context();
        $grade = absint($request['grade_id'] ?? 0);
        $subject = absint($request['subject_id'] ?? 0);
        if (!$year || !$semester || !$grade || !$subject ||
            absint($request['academic_year_id'] ?? 0) !== (int) $year->id ||
            absint($request['semester_id'] ?? 0) !== (int) $semester->id) {
            return new WP_Error('invalid_scope', 'اختر الصف والمادة ضمن العام والفصل الدراسي النشطين.');
        }
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}olama_subjects WHERE id = %d AND grade_id = %d AND is_active = 1", $subject, $grade))) {
            return new WP_Error('invalid_subject', 'المادة لا تتبع الصف المحدد أو غير مفعلة.');
        }
        $sections = $wpdb->get_results($wpdb->prepare(
            "SELECT id, section_name FROM {$wpdb->prefix}olama_sections WHERE grade_id = %d AND academic_year_id = %d ORDER BY id", $grade, $year->id
        ));
        if (!is_array($sections)) { return new WP_Error('db_error', 'تعذر تحميل الشعب. حاول مجدداً.'); }
        $sections = array_values(array_filter($sections, function($section) use ($subject) {
            return Olama_Exam_Ajax::can_teacher_access_exam((object) array('subject_id' => $subject, 'section_id' => $section->id));
        }));
        if (!$sections) {
            return new WP_Error('no_sections', 'لا توجد شعب متاحة لك لهذه المادة في الصف والعام المحددين.');
        }
        $section_ids = implode(',', array_map('intval', array_column($sections, 'id')));
        $lessons = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.lesson_title, l.unit_id, COUNT(q.id) AS question_count
             FROM {$wpdb->prefix}olama_curriculum_lessons l
             JOIN {$wpdb->prefix}olama_curriculum_units u ON u.id = l.unit_id
             LEFT JOIN {$wpdb->prefix}olama_exam_questions q ON q.lesson_id = l.id AND q.unit_id = l.unit_id
             WHERE u.grade_id = %d AND u.subject_id = %d AND u.semester_id = %d
             GROUP BY l.id, l.lesson_title, l.unit_id ORDER BY l.id", $grade, $subject, $semester->id
        ));
        if (!is_array($lessons)) { return new WP_Error('db_error', 'تعذر تحميل الدروس. حاول مجدداً.'); }
        $exams = $wpdb->get_results($wpdb->prepare(
            "SELECT e.id, e.direct_bulk_key, e.status,
                (SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts a WHERE a.exam_id = e.id AND a.exam_type = 'school') AS attempt_count
             FROM {$wpdb->prefix}olama_exam_exams e
             WHERE e.exam_type = 'direct' AND e.is_placement = 0 AND e.academic_year_id = %d
               AND e.semester_id = %d AND e.subject_id = %d AND e.section_id IN ($section_ids) ORDER BY e.id",
            $year->id, $semester->id, $subject
        ));
        if (!is_array($exams)) { return new WP_Error('db_error', 'تعذر تحميل الامتحانات. حاول مجدداً.'); }
        $existing = array_filter(array_column($exams, 'direct_bulk_key'));
        $jobs = array();
        $ready = 0;
        $skipped = 0;
        foreach ($lessons as $lesson) {
            if (!(int) $lesson->question_count) { continue; }
            $ready++;
            foreach ($sections as $section) {
                $key = hash('sha256', implode(':', array($year->id, $semester->id, $subject, $lesson->id, $section->id)));
                if (in_array($key, $existing, true)) { $skipped++; continue; }
                $jobs[] = array('title' => 'تدريب — ' . $lesson->lesson_title, 'section_id' => (int) $section->id,
                    'subject_id' => $subject, 'academic_year_id' => (int) $year->id, 'semester_id' => (int) $semester->id,
                    'exam_type' => 'direct', 'direct_lesson_id' => (int) $lesson->id, 'question_mode' => 'random',
                    'random_count' => max(1, (int) round($lesson->question_count * 0.4)), 'key' => $key);
            }
        }
        return array('sections' => count($sections), 'ready_lessons' => $ready, 'empty_lessons' => count($lessons) - $ready,
            'create_count' => count($jobs), 'skip_count' => $skipped, 'delete_count' => count($exams),
            'attempt_count' => array_sum(array_column($exams, 'attempt_count')),
            'fingerprint' => hash('sha256', json_encode(array($jobs, $exams, $section_ids))),
            '_jobs' => $jobs, '_exams' => $exams);
    }

    public static function execute($request, $operation)
    {
        global $wpdb;
        if (!in_array($operation, array('create', 'delete'), true)) {
            return new WP_Error('invalid_operation', 'العملية غير صالحة.');
        }
        // Serialize bulk creation/deletion for the same academic subject across users.
        $lock = 'oee_bulk_' . md5($wpdb->prefix . ':' . absint($request['academic_year_id'] ?? 0) . ':' . absint($request['semester_id'] ?? 0) . ':' . absint($request['subject_id'] ?? 0));
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return new WP_Error('busy', 'توجد عملية جماعية قيد التنفيذ لهذه المادة. حاول بعد اكتمالها.');
        }
        $transaction = false;
        try {
            $plan = self::plan($request);
            if (is_wp_error($plan)) { return $plan; }
            if (!is_string($request['fingerprint'] ?? null) || !hash_equals($plan['fingerprint'], $request['fingerprint'])) {
                return new WP_Error('changed', 'تغيرت البيانات منذ عرض الملخص. راجع الملخص مجدداً قبل التنفيذ.');
            }
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException(); }
            $transaction = true;
            if ($operation === 'create') {
                foreach ($plan['_jobs'] as $job) {
                    $key = $job['key'];
                    unset($job['key']);
                    $id = Olama_Exam_Manager::save_exam($job, $key);
                    if (is_wp_error($id)) { throw new RuntimeException(); }
                    foreach (array('published', 'active') as $status) {
                        if (is_wp_error(Olama_Exam_Manager::update_status($id, $status))) { throw new RuntimeException(); }
                    }
                }
                $message = sprintf('تم إنشاء وتفعيل %d امتحان. تم تجاوز %d امتحان منشأ سابقاً و%d درس دون أسئلة.', $plan['create_count'], $plan['skip_count'], $plan['empty_lessons']);
            } else {
                $ids = implode(',', array_map('intval', array_column($plan['_exams'], 'id')));
                if ($ids !== '') {
                    $attempts = "SELECT id FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_type = 'school' AND exam_id IN ($ids)";
                    foreach (array(
                        "DELETE FROM {$wpdb->prefix}olama_exam_essay_grades WHERE attempt_id IN ($attempts)",
                        "DELETE FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_type = 'school' AND exam_id IN ($ids)",
                        "DELETE FROM {$wpdb->prefix}olama_exam_exams WHERE exam_type = 'direct' AND id IN ($ids)"
                    ) as $sql) {
                        if ($wpdb->query($sql) === false) { throw new RuntimeException(); }
                    }
                }
                $message = sprintf('تم حذف %d امتحان مباشر و%d محاولة مرتبطة بها.', $plan['delete_count'], $plan['attempt_count']);
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException(); }
            $transaction = false;
            return array('message' => $message);
        } catch (Throwable $error) {
            if ($transaction) { $wpdb->query('ROLLBACK'); }
            olama_exam_log('Direct bulk ' . $operation . ' failed: ' . $error->getMessage() . ' ' . $wpdb->last_error);
            return new WP_Error('db_error', 'تعذر إتمام العملية. حدّث القائمة وراجع البيانات قبل إعادة المحاولة.');
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
