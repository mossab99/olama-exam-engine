<?php
/** Run with: php tests/test-direct-exams.php */
define('ABSPATH', __DIR__);
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return stripslashes($value); }
function get_current_user_id() { return 1; }
function current_time($format) { return '2026-10-31 12:00:00'; }
function olama_exam_translate($value) { return $value; }
function olama_exam_log($value) {}

class Direct_Exam_Test_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $exams = array();
    public $questions;
    public $lesson;
    public $last_random_query;
    public function __construct() {
        $this->lesson = (object) array('id'=>11, 'unit_id'=>4, 'grade_id'=>2, 'subject_id'=>3, 'semester_id'=>5);
        $this->questions = array(
            (object) array('id'=>101, 'lesson_id'=>11, 'unit_id'=>4, 'difficulty'=>'easy'),
            (object) array('id'=>102, 'lesson_id'=>11, 'unit_id'=>4, 'difficulty'=>'hard'),
            (object) array('id'=>201, 'lesson_id'=>12, 'unit_id'=>4, 'difficulty'=>'easy'),
            (object) array('id'=>301, 'lesson_id'=>11, 'unit_id'=>9, 'difficulty'=>'easy'),
        );
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $index = 0;
        return preg_replace_callback('/%[ds]/', function($match) use ($args, &$index) {
            $value = $args[$index++];
            return $match[0] === '%d' ? (string) intval($value) : "'" . addslashes($value) . "'";
        }, $sql);
    }
    public function get_row($sql) {
        if (strpos($sql, 'olama_curriculum_lessons') !== false) {
            return preg_match('/l.id = 11\b/', $sql) ? $this->lesson : null;
        }
        preg_match('/e.id = (\d+)/', $sql, $matches);
        return $this->exams[intval($matches[1] ?? 0)] ?? null;
    }
    public function get_results($sql) {
        return array_values(array_filter($this->questions, function($q) use ($sql) {
            foreach (array('lesson_id', 'unit_id') as $key) {
                if (preg_match('/(?:q\.)?' . $key . ' = (\d+)/', $sql, $m) && $q->$key !== intval($m[1])) return false;
            }
            if (preg_match('/difficulty = \'([^\']+)\'/', $sql, $m) && $q->difficulty !== $m[1]) return false;
            if (preg_match('/(?:q\.)?id IN \(([^)]+)\)/', $sql, $m) && !in_array($q->id, array_map('intval', explode(',', $m[1])), true)) return false;
            return true;
        }));
    }
    public function get_var($sql) {
        if (strpos($sql, 'SELECT grade_id') !== false) return strpos($sql, 'id = 7') !== false ? 2 : 99;
        return count($this->get_results($sql));
    }
    public function get_col($sql) {
        $this->last_random_query = $sql;
        return array_column($this->get_results($sql), 'id');
    }
    public function insert($table, $fields) {
        $this->exams[++$this->insert_id] = (object) array_merge(array('id'=>$this->insert_id), $fields);
        return 1;
    }
    public function update($table, $fields, $where) {
        $this->exams[$where['id']] = (object) array_merge((array) $this->exams[$where['id']], $fields);
        return 1;
    }
}
require dirname(__DIR__) . '/includes/class-exam-manager.php';
$wpdb = new Direct_Exam_Test_DB();
function check_direct($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function expect_direct_error($data, $code) {
    $result = Olama_Exam_Manager::save_exam($data);
    check_direct($result instanceof WP_Error && $result->code === $code, 'Expected rejection: ' . $code);
}
$base = array('title'=>'Lesson exam', 'section_id'=>7, 'subject_id'=>3, 'semester_id'=>5,
    'academic_year_id'=>1, 'exam_type'=>'direct', 'direct_lesson_id'=>11, 'manual_question_ids'=>'[101,102]');
$first = Olama_Exam_Manager::save_exam($base);
$second = Olama_Exam_Manager::save_exam($base);
check_direct($first === 1 && $second === 2 && $wpdb->exams[1]->direct_lesson_id === 11, 'Multiple exams for one lesson must be supported.');
expect_direct_error(array_merge($base, array('direct_lesson_id'=>999)), 'invalid_lesson');
expect_direct_error(array_merge($base, array('subject_id'=>8)), 'invalid_lesson');
expect_direct_error(array_merge($base, array('semester_id'=>9)), 'invalid_lesson');
expect_direct_error(array_merge($base, array('section_id'=>8)), 'invalid_section');
expect_direct_error(array_merge($base, array('is_placement'=>1)), 'invalid_lesson');
expect_direct_error(array_merge($base, array('manual_question_ids'=>'[101,201]')), 'outside_lesson');
expect_direct_error(array_merge($base, array('manual_question_ids'=>'[301]')), 'outside_lesson');
expect_direct_error(array_merge($base, array('manual_question_ids'=>'[]')), 'no_questions');
expect_direct_error(array_merge($base, array('manual_question_ids'=>'42')), 'invalid_questions');
expect_direct_error(array_merge($base, array('id'=>1, 'exam_type'=>'exam', 'direct_lesson_id'=>0, 'manual_question_ids'=>'[201]')), 'outside_lesson');
check_direct(Olama_Exam_Manager::save_exam(array_merge($base, array('id'=>1, 'exam_type'=>'exam', 'direct_lesson_id'=>999))) === 1 &&
    $wpdb->exams[1]->direct_lesson_id === 11 && $wpdb->exams[1]->exam_type === 'direct', 'Editing cannot remove or change the lesson binding.');
$random = array_merge($base, array('question_mode'=>'random', 'random_count'=>2, 'random_lesson_id'=>12, 'random_unit_id'=>99));
$random_id = Olama_Exam_Manager::save_exam($random);
check_direct(is_int($random_id) && $wpdb->exams[$random_id]->random_lesson_id === 11 && $wpdb->exams[$random_id]->random_unit_id === 4, 'Random mode must force the bound lesson and unit.');
expect_direct_error(array_merge($random, array('random_count'=>3)), 'invalid_count');
expect_direct_error(array_merge($random, array('random_count'=>0)), 'invalid_count');
expect_direct_error(array_merge($random, array('random_difficulty'=>'easy')), 'invalid_count');
check_direct(count(Olama_Exam_Manager::get_exam_questions($random_id)) === 2, 'Random resolution must only return lesson questions.');
check_direct(strpos($wpdb->last_random_query, 'q.lesson_id = 11') !== false && strpos($wpdb->last_random_query, 'q.unit_id = 4') !== false, 'Random selection must enforce scope in SQL.');
$wpdb->questions[1]->lesson_id = 12;
check_direct(count(Olama_Exam_Manager::get_exam_questions(1)) === 1, 'A question moved to another lesson must not appear in the direct exam.');
check_direct(Olama_Exam_Manager::save_exam(array_merge($base, array('exam_type'=>'exam', 'manual_question_ids'=>'[201]'))) === 4, 'Ordinary exams must retain existing selection behavior.');
echo "Direct exam scope, multiple creation, edit protection, and question resolution checks passed.\n";
