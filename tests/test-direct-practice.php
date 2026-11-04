<?php
/** Run with: php tests/test-direct-practice.php (requires pdo_sqlite). */
ob_start();
require __DIR__ . '/test-exam-lifecycle.php';
ob_end_clean();
function sanitize_textarea_field($value) { return trim((string) $value); }
function get_current_user_id() { return 1; }
class Practice_DB extends Lifecycle_DB {
    public $insert_id;
    public $last_error = '';
    public function __construct() {
        parent::__construct();
        foreach (array('duration_minutes INTEGER DEFAULT 60','max_attempts INTEGER DEFAULT 1',
            "manual_question_ids TEXT DEFAULT '[101]'", "question_mode TEXT DEFAULT 'manual'",'passing_grade INTEGER DEFAULT 50',
            'show_correct_answers INTEGER DEFAULT 0', 'direct_lesson_id INTEGER DEFAULT 11') as $column) {
            $this->db->exec('ALTER TABLE wp_olama_exam_exams ADD COLUMN ' . $column);
        }
        foreach (array('questions_snapshot_json TEXT','answers_json TEXT','started_at TEXT','submit_type TEXT') as $column) {
            $this->db->exec('ALTER TABLE wp_olama_exam_attempts ADD COLUMN ' . $column);
        }
        $this->db->exec("CREATE TABLE wp_olama_curriculum_units (id INTEGER, unit_name TEXT, unit_number INTEGER, subject_id INTEGER, grade_id INTEGER, semester_id INTEGER);
            INSERT INTO wp_olama_curriculum_units VALUES (4,'Unit',1,0,0,0);
            CREATE TABLE wp_olama_curriculum_lessons (id INTEGER, unit_id INTEGER);
            INSERT INTO wp_olama_curriculum_lessons VALUES (11,4);
            CREATE TABLE wp_olama_exam_questions (id INTEGER, unit_id INTEGER, lesson_id INTEGER, type TEXT, question_text TEXT, answers_json TEXT, image_filename TEXT, version INTEGER);
            INSERT INTO wp_olama_exam_questions VALUES (101,4,11,'tf','True or false?','{\"correct\":true}',NULL,1);
            CREATE TABLE wp_olama_exam_placement_info (attempt_id INTEGER, student_name TEXT);
            CREATE TABLE wp_oee_acceptance_applicants (attempt_id INTEGER, name TEXT);
            CREATE TABLE wp_oee_student_applicants (attempt_id INTEGER, student_name TEXT);
            CREATE TABLE wp_olama_students (student_uid TEXT, student_name TEXT);
            INSERT INTO wp_olama_students VALUES ('S1','Student');");
        $this->db->sqliteCreateFunction('FIELD', function($value, ...$ids) { return array_search($value, $ids) + 1; });
    }
    public function get_row($sql) { return $this->db->query($sql)->fetch(PDO::FETCH_OBJ) ?: null; }
    public function get_var($sql) { return $this->db->query($sql)->fetchColumn(); }
    public function insert($table, $fields) {
        $names = implode(',', array_keys($fields));
        $slots = implode(',', array_fill(0, count($fields), '?'));
        $statement = $this->db->prepare("INSERT INTO $table ($names) VALUES ($slots)");
        $statement->execute(array_values($fields));
        $this->insert_id = (int) $this->db->lastInsertId();
        return 1;
    }
    public function update($table, $fields, $where) {
        $set = implode(',', array_map(function($key) { return "$key = ?"; }, array_keys($fields)));
        $conditions = implode(' AND ', array_map(function($key) { return "$key = ?"; }, array_keys($where)));
        $statement = $this->db->prepare("UPDATE $table SET $set WHERE $conditions");
        $statement->execute(array_merge(array_values($fields), array_values($where)));
        return $statement->rowCount();
    }
}
$test_dir = sys_get_temp_dir() . '/oee-practice-' . uniqid();
mkdir($test_dir);
define('WP_CONTENT_DIR', $test_dir);
$old_error_log = ini_get('error_log');
ini_set('error_log', $test_dir . '/php-errors.log');
require dirname(__DIR__) . '/includes/class-exam-grader.php';
try {
    $wpdb = new Practice_DB();
    $test_now = '2026-10-31 12:00:00';
    $wpdb->exam(20, 'active', '2020-01-01 00:00:00', '2019-01-01 00:00:00');
    $wpdb->db->exec("UPDATE wp_olama_exam_exams SET exam_type='direct', show_results=0, show_correct_answers=0 WHERE id=20");
    $wpdb->db->exec("INSERT INTO wp_olama_exam_attempts (id,exam_id,student_uid,submitted_at) VALUES
        (1,20,'S1','2026-10-01 10:00:00'), (2,20,'S1','2026-10-02 10:00:00')");
    check_lifecycle(Olama_Exam_Manager::close_expired_exams() === 0, 'Direct exams must not auto-close because of old dates.');
    $data = Olama_Exam_Engine::start_exam(20, 'S1', false, false, 'school');
    check_lifecycle(is_array($data) && $data['timer_mode'] === 'elapsed' && $data['duration_minutes'] === 0, 'Practice starts without dates or an attempt limit and uses an elapsed timer.');
    check_lifecycle(!isset($data['questions'][0]['correct']), 'Correct answers must remain hidden before submission.');
    $first_id = $data['attempt_id'];
    $test_now = '2026-10-31 14:00:00';
    $resumed = Olama_Exam_Engine::resume_exam($first_id);
    check_lifecycle(is_array($resumed) && $resumed['elapsed_seconds'] === 7200 && $resumed['timer_mode'] === 'elapsed', 'Practice must resume after two hours without being timed out.');
    Olama_Exam_Engine::autosave($first_id, '{"101":true}');
    $graded = Olama_Exam_Engine::submit($first_id);
    check_lifecycle($graded['percentage'] === 100.0 && $graded['show_results'] === 1 && $graded['show_correct_answers'] === 1 && isset($graded['details'][0]['correct_answer']), 'Scores and correct answers must appear immediately after practice submission.');
    check_lifecycle(Olama_Exam_Engine::get_attempt($first_id)->submit_type === 'manual', 'Long practice attempts must not be labeled as timed out.');
    $test_now = '2026-10-31 14:01:00';
    $second = Olama_Exam_Engine::start_exam(20, 'S1', false, false, 'school');
    check_lifecycle(is_array($second) && $second['attempt_id'] > $first_id, 'Another attempt must be allowed after submission.');
    Olama_Exam_Engine::autosave($second['attempt_id'], '{"101":false}');
    Olama_Exam_Engine::submit($second['attempt_id']);
    $summary = Olama_Exam_Manager::get_exam_summaries(array(20))[20];
    check_lifecycle($summary['attempts'] === 1 && $summary['average'] === 0.0 && $summary['passed'] === 0 && $summary['failed'] === 1, 'Only the latest submitted mark, even when lower, must count in statistics.');
    $results = Olama_Exam_Manager::get_student_results('S1');
    check_lifecycle(count($results) === 1 && (int) $results[0]->attempt_id === $second['attempt_id'], 'The student result must be the latest submitted practice mark.');
    check_lifecycle((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_attempts WHERE exam_id=20') === 4, 'Previous practice attempts must be retained as history.');
    $third = Olama_Exam_Engine::start_exam(20, 'S1', false, false, 'school');
    check_lifecycle(is_array($third) && Olama_Exam_Manager::get_exam_summaries(array(20))[20]['average'] === 0.0, 'A newer unfinished attempt must not replace the latest submitted mark.');

    $wpdb->db->exec('CREATE TABLE wp_olama_exam_essay_grades (
        id INTEGER PRIMARY KEY, attempt_id INTEGER, question_id INTEGER, score REAL, max_score REAL,
        teacher_comment TEXT, graded_by INTEGER, graded_at TEXT)');
    $snapshot = array(
        array('question_id' => 101, 'type' => 'essay', 'points' => 3, 'question_text' => 'First essay'),
        array('question_id' => 102, 'type' => 'essay', 'points' => 2, 'question_text' => 'Second essay'),
        array('question_id' => 103, 'type' => 'mcq', 'points' => 1, 'question_text' => 'Pick one', 'correct' => array('correct' => 0)),
        array('question_id' => 104, 'type' => 'fill_blank', 'points' => 1, 'question_text' => 'Fill in', 'correct' => array('answers' => array(''))),
    );
    $wpdb->update('wp_olama_exam_attempts', array('questions_snapshot_json' => json_encode($snapshot)), array('id' => $third['attempt_id']));
    Olama_Exam_Engine::autosave($third['attempt_id'], '{"101":"  ","103":0,"104":[" "]}');
    $graded = Olama_Exam_Engine::submit($third['attempt_id']);
    check_lifecycle(array_column($graded['details'], 'status') === array('incorrect', 'incorrect', 'correct', 'incorrect'), 'Blank answers must be marked incorrect and option zero must still count.');
    check_lifecycle($graded['score'] === 1 && $graded['max_score'] === 7 && $graded['result'] === 'fail', 'Blank answers must earn zero while remaining in the maximum score.');
    check_lifecycle(is_wp_error(Olama_Exam_Grader::grade_essay($third['attempt_id'], 101, 3, '')), 'A blank essay cannot be manually awarded points.');

    $fourth = Olama_Exam_Engine::start_exam(20, 'S1', false, false, 'school');
    $wpdb->update('wp_olama_exam_attempts', array('questions_snapshot_json' => json_encode($snapshot)), array('id' => $fourth['attempt_id']));
    Olama_Exam_Engine::autosave($fourth['attempt_id'], '{"101":"An essay response","103":0}');
    $graded = Olama_Exam_Engine::submit($fourth['attempt_id']);
    check_lifecycle($graded['result'] === 'pending' && $graded['details'][1]['status'] === 'incorrect', 'Only the answered essay must be pending.');
    $recalculated = Olama_Exam_Grader::grade_essay($fourth['attempt_id'], 101, 3, '');
    check_lifecycle($recalculated['score'] === 4.0 && $recalculated['result'] === 'pass', 'Grading the answered essay must finalize the attempt with the blank essay at zero.');

    $wpdb->update('wp_olama_exam_attempts', array('result' => 'pending'), array('id' => $third['attempt_id']));
    $wpdb->update('wp_olama_exam_attempts', array('score' => 6, 'percentage' => 85.71), array('id' => $fourth['attempt_id']));
    $wpdb->insert('wp_olama_exam_essay_grades', array('attempt_id' => $fourth['attempt_id'], 'question_id' => 102, 'score' => 2));
    foreach (array(array('is_preview' => 1), array('submitted_at' => null), array('exam_type' => 'acceptance'), array('exam_id' => 21)) as $excluded) {
        $excluded_attempt = (array) Olama_Exam_Engine::get_attempt($third['attempt_id']);
        unset($excluded_attempt['id']);
        $wpdb->insert('wp_olama_exam_attempts', array_replace($excluded_attempt, $excluded));
        $excluded_ids[] = $wpdb->insert_id;
    }
    $exam = Olama_Exam_Manager::get_exam(20);
    check_lifecycle(Olama_Exam_Grader::apply_unanswered_zero($exam) === 2, 'Bulk grading must update the two legacy results for this exam.');
    check_lifecycle(Olama_Exam_Engine::get_attempt($third['attempt_id'])->result === 'fail', 'Bulk grading must clear pending status for blank essays.');
    $updated_attempt = Olama_Exam_Engine::get_attempt($fourth['attempt_id']);
    check_lifecycle((float) $updated_attempt->score === 4.0 && $updated_attempt->result === 'pass', 'Bulk grading must preserve the answered essay grade and remove marks previously awarded to a blank essay.');
    foreach ($excluded_ids as $excluded_id) {
        check_lifecycle(Olama_Exam_Engine::get_attempt($excluded_id)->result === 'pending', 'Bulk grading must skip attempts outside its scope.');
    }
    check_lifecycle(Olama_Exam_Grader::apply_unanswered_zero($exam) === 0, 'Running the bulk action again must leave results unchanged.');
    echo "Direct practice unlimited attempts, elapsed timer, immediate review and latest-mark reporting passed.\n";
} finally {
    ini_set('error_log', $old_error_log);
    foreach (array('grading_debug.log', 'php-errors.log') as $file) if (file_exists($test_dir . '/' . $file)) unlink($test_dir . '/' . $file);
    rmdir($test_dir);
}
