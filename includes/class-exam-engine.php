<?php
/**
 * Exam Engine — Start, Autosave, Submit, Resume
 * Handles the student exam lifecycle with snapshot generation and time validation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Engine
{
    /**
     * Start an exam for a student
     * Creates a question snapshot and inserts an attempt row
     */
    public static function start_exam($exam_id, $student_uid, $is_preview = false, $is_admin_override = false)
    {
        global $wpdb;

        $exam = Olama_Exam_Manager::get_exam($exam_id);
        if (!$exam) {
            return new WP_Error('not_found', 'Exam not found.');
        }

        // Check exam is active (unless preview or override)
        if ($exam->status !== 'active' && !$is_preview && !$is_admin_override) {
            return new WP_Error('not_active', olama_exam_translate('This exam is not currently active.'));
        }

        // Check time window (unless preview or override)
        $now = current_time('mysql');
        if (!$is_preview && !$is_admin_override && ($now < $exam->start_time || $now > $exam->end_time)) {
            return new WP_Error('outside_window', olama_exam_translate('This exam is outside its scheduled time window.'));
        }

        // Check max attempts (unless preview or override)
        $attempt_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_attempts WHERE exam_id = %d AND student_uid = %s",
            $exam_id,
            $student_uid
        ));
        if (!$is_preview && !$is_admin_override && $attempt_count >= $exam->max_attempts) {
            return new WP_Error('max_attempts', olama_exam_translate('You have used all your attempts for this exam.'));
        }

        // Check for existing unsubmitted attempt (resume instead)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_exam_attempts 
             WHERE exam_id = %d AND student_uid = %s AND submitted_at IS NULL
             ORDER BY started_at DESC LIMIT 1",
            $exam_id,
            $student_uid
        ));
        if ($existing) {
            return self::resume_exam($existing->id);
        }

        // Get questions
        $questions = Olama_Exam_Manager::get_exam_questions($exam_id);
        if (empty($questions)) {
            return new WP_Error('no_questions', olama_exam_translate('This exam has no questions.'));
        }

        // Build snapshot — randomize order, randomize MCQ choices
        $snapshot = array();
        $question_order = range(0, count($questions) - 1);
        shuffle($question_order);

        foreach ($question_order as $idx) {
            $q = $questions[$idx];
            $answers = json_decode($q->answers_json, true) ?: array();
            $student_data = self::prepare_student_question($q->type, $answers);

            $snapshot[] = array(
                'question_id' => intval($q->id),
                'type' => $q->type,
                'question_text' => $q->question_text,
                'image_filename' => $q->image_filename,
                'version' => intval($q->version),
                'answers' => $student_data['display'],
                'correct' => $student_data['correct'],
                'points' => 1,
            );
        }

        // Insert attempt
        $wpdb->insert("{$wpdb->prefix}olama_exam_attempts", array(
            'exam_id' => $exam_id,
            'student_uid' => $student_uid,
            'attempt_number' => $is_preview ? 0 : ($attempt_count + 1),
            'questions_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'answers_json' => json_encode(array()),
            'result' => 'pending',
            'started_at' => $now,
            'is_preview' => $is_preview ? 1 : 0,
        ));

        $attempt_id = $wpdb->insert_id;

        if (!$attempt_id) {
            return new WP_Error('db_error', 'Failed to create student attempt. Database error: ' . $wpdb->last_error);
        }

        // Return data for frontend (WITHOUT correct answers)
        $display_questions = self::strip_correct_answers($snapshot);

        return array(
            'attempt_id' => $attempt_id,
            'exam_title' => $exam->title,
            'duration_minutes' => intval($exam->duration_minutes),
            'started_at' => $now,
            'questions' => $display_questions,
            'answers' => new stdClass(),
            'total_questions' => count($display_questions),
        );
    }

    /**
     * Autosave student answers
     */
    public static function autosave($attempt_id, $answers_json)
    {
        global $wpdb;
        $attempt = self::get_attempt($attempt_id);
        if (is_wp_error($attempt))
            return $attempt;

        // Verify not yet submitted
        if ($attempt->submitted_at) {
            return new WP_Error('already_submitted', olama_exam_translate('This attempt has already been submitted.'));
        }

        $wpdb->update(
            "{$wpdb->prefix}olama_exam_attempts",
            array('answers_json' => $answers_json),
            array('id' => $attempt_id)
        );

        return array('saved' => true);
    }

    /**
     * Submit an attempt — validate time, grade, and return results
     */
    public static function submit($attempt_id)
    {
        global $wpdb;
        $attempt = self::get_attempt($attempt_id);
        if (is_wp_error($attempt))
            return $attempt;

        if ($attempt->submitted_at) {
            return new WP_Error('already_submitted', olama_exam_translate('This attempt has already been submitted.'));
        }

        // Server-side time check with 30-second grace period
        $exam = Olama_Exam_Manager::get_exam($attempt->exam_id);
        $started = strtotime($attempt->started_at);
        $now = current_time('timestamp');
        $elapsed_seconds = $now - $started;
        $allowed_seconds = ($exam->duration_minutes * 60) + 30; // 30s grace

        $submit_type = 'manual';
        if ($elapsed_seconds > $allowed_seconds) {
            $submit_type = 'auto_timeout';
        }

        // Mark as submitted
        $wpdb->update(
            "{$wpdb->prefix}olama_exam_attempts",
            array(
                'submitted_at' => current_time('mysql'),
                'submit_type' => $submit_type,
            ),
            array('id' => $attempt_id)
        );

        // Grade the attempt
        $results = Olama_Exam_Grader::grade($attempt_id);

        return $results;
    }

    /**
     * Resume an existing unsubmitted attempt
     */
    public static function resume_exam($attempt_id)
    {
        global $wpdb;
        $attempt = self::get_attempt($attempt_id);
        if (is_wp_error($attempt))
            return $attempt;

        if ($attempt->submitted_at) {
            return new WP_Error('already_submitted', 'This attempt has already been submitted.');
        }

        $exam = Olama_Exam_Manager::get_exam($attempt->exam_id);

        // Check if time has expired
        $started = strtotime($attempt->started_at);
        $now = current_time('timestamp');
        $elapsed = $now - $started;
        $allowed = ($exam->duration_minutes * 60) + 30;

        if ($elapsed > $allowed) {
            // Auto-submit expired attempt
            return self::submit($attempt_id);
        }

        $snapshot = json_decode($attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($attempt->answers_json, true) ?: array();
        $display_questions = self::strip_correct_answers($snapshot);

        $remaining_seconds = max(0, ($exam->duration_minutes * 60) - $elapsed);

        return array(
            'attempt_id' => intval($attempt_id),
            'exam_title' => $exam->title,
            'duration_minutes' => intval($exam->duration_minutes),
            'started_at' => $attempt->started_at,
            'remaining_seconds' => $remaining_seconds,
            'questions' => $display_questions,
            'answers' => $answers,
            'total_questions' => count($display_questions),
            'resumed' => true,
        );
    }

    /**
     * Get and validate an attempt belongs to current user
     */
    public static function get_attempt($attempt_id, $student_uid = '')
    {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_attempts WHERE id = %d",
            $attempt_id
        ));

        if (!$attempt) {
            return new WP_Error('not_found', 'Attempt not found.');
        }

        if ($student_uid && $attempt->student_uid !== $student_uid && !Olama_Exam_Ajax::can_manage_exams()) {
            return new WP_Error('not_owner', 'This attempt does not belong to you.');
        }

        return $attempt;
    }

    /**
     * Prepare question data for student display: randomize choices, separate correct from display
     */
    private static function prepare_student_question($type, $answers)
    {
        $display = $answers;
        $correct = array();

        switch ($type) {
            case 'mcq':
                $correct['correct'] = $answers['correct'] ?? 0;
                // Shuffle choices
                if (!empty($answers['choices'])) {
                    $indexed = array();
                    foreach ($answers['choices'] as $i => $choice) {
                        $indexed[] = array('original_index' => $i, 'text' => $choice);
                    }
                    shuffle($indexed);
                    $display['choices'] = array_column($indexed, 'text');
                    // Map correct answer to new index
                    $correct['correct_text'] = $answers['choices'][$answers['correct']] ?? '';
                    foreach ($indexed as $newIdx => $item) {
                        if ($item['original_index'] == $answers['correct']) {
                            $correct['correct'] = $newIdx;
                            break;
                        }
                    }
                }
                unset($display['correct']);
                break;

            case 'tf':
                $correct['correct'] = $answers['correct'] ?? true;
                unset($display['correct']);
                break;

            case 'short':
                $correct['answers'] = $answers['answers'] ?? array();
                unset($display['answers']);
                break;

            case 'matching':
                if (!empty($answers['pairs'])) {
                    $correct['pairs'] = $answers['pairs'];
                    $rights = array_column($answers['pairs'], 'right');
                    shuffle($rights);
                    $display['lefts'] = array_column($answers['pairs'], 'left');
                    $display['rights'] = $rights;
                    unset($display['pairs']);
                }
                break;

            case 'ordering':
                if (!empty($answers['items'])) {
                    $correct['correct_order'] = $answers['items'];
                    $shuffled = $answers['items'];
                    shuffle($shuffled);
                    $display['items'] = $shuffled;
                    unset($display['correct_order']);
                }
                break;

            case 'fill_blank':
                $correct['answers'] = $answers['answers'] ?? array();
                unset($display['answers']);
                break;

            case 'essay':
                // No correct answer
                break;
        }

        return array('display' => $display, 'correct' => $correct);
    }

    /**
     * Strip correct answers from snapshot for frontend display
     */
    private static function strip_correct_answers($snapshot)
    {
        $result = array();
        foreach ($snapshot as $q) {
            $display = $q;
            unset($display['correct']);
            $result[] = $display;
        }
        return $result;
    }
}
