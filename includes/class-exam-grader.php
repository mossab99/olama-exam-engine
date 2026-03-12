<?php
/**
 * Exam Grader — Auto-grading per question type
 * Grades 6 types automatically, marks essays as pending for manual grading.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Grader
{
    /**
     * Grade an entire attempt
     */
    public static function grade($attempt_id)
    {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_attempts WHERE id = %d",
            $attempt_id
        ));

        if (!$attempt) {
            return new WP_Error('not_found', 'Attempt not found.');
        }

        $snapshot = json_decode($attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($attempt->answers_json, true) ?: array();

        // DEBUG: Log to a file we can read
        $debug_info = "Attempt ID: " . $attempt_id . "\n";
        $debug_info .= "Snapshot: " . print_r($snapshot, true) . "\n";
        $debug_info .= "Answers: " . print_r($answers, true) . "\n";
        file_put_contents(WP_CONTENT_DIR . '/grading_debug.log', $debug_info);

        $total_score = 0;
        $max_score = 0;
        $has_essay = false;
        $results = array();

        foreach ($snapshot as $idx => $question) {
            $q_id = $question['question_id'];
            $type = $question['type'];
            $points = $question['points'] ?? 1;
            $correct_data = $question['correct'] ?? array();
            $student_answer = $answers[$q_id] ?? null;

            $max_score += $points;

            if ($type === 'essay') {
                $has_essay = true;
                $results[] = array(
                    'question_id' => $q_id,
                    'type' => $type,
                    'status' => 'pending',
                    'score' => 0,
                    'max_score' => $points,
                    'text' => $question['question_text'],
                );
                continue;
            }

            $earned = self::grade_question($type, $student_answer, $correct_data, $points);
            $total_score += $earned;

            $results[] = array(
                'question_id' => $q_id,
                'type' => $type,
                'status' => ($earned >= $points) ? 'correct' : (($earned > 0) ? 'partial' : 'incorrect'),
                'score' => $earned,
                'max_score' => $points,
                'student_answer' => $student_answer,
                'correct_answer' => $correct_data,
                'text' => $question['question_text'],
                'explanation' => $question['explanation'] ?? null,
            );
        }

        $percentage = $max_score > 0 ? round(($total_score / $max_score) * 100, 2) : 0;

        // Determine result
        $exam = Olama_Exam_Manager::get_exam($attempt->exam_id);
        $passing = $exam ? $exam->passing_grade : 50;

        if ($has_essay) {
            $result_status = 'pending'; // Can't determine until essays are graded
        } else {
            $result_status = ($percentage >= $passing) ? 'pass' : 'fail';
        }

        // Update attempt row
        $wpdb->update(
            "{$wpdb->prefix}olama_exam_attempts",
            array(
                'score' => $total_score,
                'max_score' => $max_score,
                'percentage' => $percentage,
                'result' => $result_status,
            ),
            array('id' => $attempt_id)
        );

        return array(
            'attempt_id' => $attempt_id,
            'score' => $total_score,
            'max_score' => $max_score,
            'percentage' => $percentage,
            'result' => $result_status,
            'passing' => $passing,
            'has_essay' => $has_essay,
            'details' => $results,
            'show_results' => $exam ? intval($exam->show_results) : 0,
        );
    }

    /**
     * Grade a single question
     */
    public static function grade_question($type, $student_answer, $correct_data, $max_points)
    {
        if ($student_answer === null || $student_answer === '' || $student_answer === array()) {
            return 0;
        }

        switch ($type) {
            case 'mcq':
                return self::grade_mcq($student_answer, $correct_data, $max_points);
            case 'tf':
                return self::grade_tf($student_answer, $correct_data, $max_points);
            case 'short':
                return self::grade_short($student_answer, $correct_data, $max_points);
            case 'matching':
                return self::grade_matching($student_answer, $correct_data, $max_points);
            case 'ordering':
                return self::grade_ordering($student_answer, $correct_data, $max_points);
            case 'fill_blank':
                return self::grade_fill_blank($student_answer, $correct_data, $max_points);
            default:
                return 0;
        }
    }

    /**
     * MCQ: compare selected index vs correct index
     */
    private static function grade_mcq($answer, $correct, $points)
    {
        $correct_idx = $correct['correct'] ?? 0;
        return (intval($answer) === intval($correct_idx)) ? $points : 0;
    }

    /**
     * True/False: compare boolean values
     */
    private static function grade_tf($answer, $correct, $points)
    {
        $correct_val = $correct['correct'] ?? true;
        // Normalize to boolean
        $student_bool = filter_var($answer, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $correct_bool = filter_var($correct_val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return ($student_bool === $correct_bool) ? $points : 0;
    }

    /**
     * Short Answer: case-insensitive match against accepted answers
     */
    private static function grade_short($answer, $correct, $points)
    {
        $accepted = $correct['answers'] ?? array();
        if (!is_array($accepted)) {
            $accepted = array($accepted);
        }

        $normalized_answer = mb_strtolower(trim($answer));
        foreach ($accepted as $acc) {
            if (mb_strtolower(trim($acc)) === $normalized_answer) {
                return $points;
            }
        }
        return 0;
    }

    /**
     * Matching: all pairs must be correctly matched (all-or-nothing)
     */
    private static function grade_matching($answer, $correct, $points)
    {
        $correct_pairs = $correct['pairs'] ?? array();
        if (!is_array($answer) || count($answer) !== count($correct_pairs)) {
            return 0;
        }

        $all_correct = true;
        foreach ($correct_pairs as $i => $pair) {
            $expected_right = mb_strtolower(trim($pair['right']));
            $student_right = isset($answer[$i]) ? mb_strtolower(trim($answer[$i])) : '';
            if ($expected_right !== $student_right) {
                $all_correct = false;
                break;
            }
        }

        return $all_correct ? $points : 0;
    }

    /**
     * Ordering: exact sequence match (all-or-nothing)
     */
    private static function grade_ordering($answer, $correct, $points)
    {
        $correct_order = $correct['correct_order'] ?? array();
        if (!is_array($answer) || count($answer) !== count($correct_order)) {
            return 0;
        }

        for ($i = 0; $i < count($correct_order); $i++) {
            if (mb_strtolower(trim($answer[$i])) !== mb_strtolower(trim($correct_order[$i]))) {
                return 0;
            }
        }

        return $points;
    }

    /**
     * Fill in the Blank: case-insensitive per blank
     */
    private static function grade_fill_blank($answer, $correct, $points)
    {
        $correct_answers = $correct['answers'] ?? array();
        if (!is_array($answer)) {
            // Single blank
            $answer = array($answer);
        }
        if (!is_array($correct_answers)) {
            $correct_answers = array($correct_answers);
        }

        $blank_count = count($correct_answers);
        if ($blank_count === 0)
            return 0;

        $correct_count = 0;
        foreach ($correct_answers as $i => $expected) {
            $student_val = isset($answer[$i]) ? mb_strtolower(trim($answer[$i])) : '';
            // Support multiple accepted answers per blank (pipe-separated)
            $accepted = is_array($expected) ? $expected : explode('|', $expected);
            foreach ($accepted as $acc) {
                if (mb_strtolower(trim($acc)) === $student_val) {
                    $correct_count++;
                    break;
                }
            }
        }

        // Proportional scoring for fill-in-the-blank
        return round(($correct_count / $blank_count) * $points, 2);
    }

    /**
     * Grade an essay question manually
     */
    public static function grade_essay($attempt_id, $question_id, $score, $comment)
    {
        global $wpdb;

        // Insert or update essay grade
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_exam_essay_grades 
             WHERE attempt_id = %d AND question_id = %d",
            $attempt_id,
            $question_id
        ));

        $data = array(
            'attempt_id' => $attempt_id,
            'question_id' => $question_id,
            'score' => floatval($score),
            'max_score' => 1, // default, will be overridden
            'teacher_comment' => sanitize_textarea_field($comment),
            'graded_by' => get_current_user_id(),
            'graded_at' => current_time('mysql'),
        );

        if ($existing) {
            $wpdb->update("{$wpdb->prefix}olama_exam_essay_grades", $data, array('id' => $existing->id));
        } else {
            $wpdb->insert("{$wpdb->prefix}olama_exam_essay_grades", $data);
        }

        // Recalculate total score
        return self::recalculate_attempt($attempt_id);
    }

    /**
     * Recalculate attempt score after essay grading
     */
    private static function recalculate_attempt($attempt_id)
    {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_attempts WHERE id = %d",
            $attempt_id
        ));

        if (!$attempt)
            return false;

        $snapshot = json_decode($attempt->questions_snapshot_json, true) ?: array();
        $answers = json_decode($attempt->answers_json, true) ?: array();

        $total_score = 0;
        $max_score = 0;
        $pending_essays = 0;

        foreach ($snapshot as $question) {
            $q_id = $question['question_id'];
            $type = $question['type'];
            $points = $question['points'] ?? 1;
            $max_score += $points;

            if ($type === 'essay') {
                $essay_grade = $wpdb->get_row($wpdb->prepare(
                    "SELECT score FROM {$wpdb->prefix}olama_exam_essay_grades 
                     WHERE attempt_id = %d AND question_id = %d",
                    $attempt_id,
                    $q_id
                ));
                if ($essay_grade) {
                    $total_score += floatval($essay_grade->score);
                } else {
                    $pending_essays++;
                }
            } else {
                $correct_data = $question['correct'] ?? array();
                $student_answer = $answers[$q_id] ?? null;
                $total_score += self::grade_question($type, $student_answer, $correct_data, $points);
            }
        }

        $percentage = $max_score > 0 ? round(($total_score / $max_score) * 100, 2) : 0;

        $exam = Olama_Exam_Manager::get_exam($attempt->exam_id);
        $passing = $exam ? $exam->passing_grade : 50;

        $result = 'pending';
        if ($pending_essays === 0) {
            $result = ($percentage >= $passing) ? 'pass' : 'fail';
        }

        $wpdb->update(
            "{$wpdb->prefix}olama_exam_attempts",
            array(
                'score' => $total_score,
                'max_score' => $max_score,
                'percentage' => $percentage,
                'result' => $result,
            ),
            array('id' => $attempt_id)
        );

        return array(
            'score' => $total_score,
            'max_score' => $max_score,
            'percentage' => $percentage,
            'result' => $result,
        );
    }
}
