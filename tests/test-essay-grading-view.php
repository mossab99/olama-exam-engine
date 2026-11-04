<?php
/** Run with: php tests/test-essay-grading-view.php (requires pdo_sqlite). */
define('ABSPATH', __DIR__);
define('OLAMA_EXAM_PATH', dirname(__DIR__) . '/');
require OLAMA_EXAM_PATH . 'includes/class-exam-grader.php';
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function olama_exam_translate($v) { return $v; }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_textarea($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function wp_kses_post($v) { return $v; }
function admin_url($v) { return '/wp-admin/' . $v; }
function wp_create_nonce($v) { return 'test-nonce'; }
function wp_die($v) { throw new RuntimeException($v); }
class Olama_Exam_Ajax {
    public static function can_teacher_access_exam($exam_id) { return (int) $exam_id !== 2; }
}
class Essay_View_DB {
    public $prefix = 'wp_';
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_olama_exam_attempts (
            id INTEGER PRIMARY KEY, exam_id INTEGER, student_uid TEXT, score REAL, max_score REAL,
            percentage REAL, result TEXT, submitted_at TEXT, questions_snapshot_json TEXT,
            answers_json TEXT, exam_type TEXT, is_preview INTEGER DEFAULT 0)');
        $this->db->exec('CREATE TABLE wp_olama_exam_exams (id INTEGER PRIMARY KEY, title TEXT, is_placement INTEGER)');
        $this->db->exec('CREATE TABLE wp_olama_students (student_uid TEXT PRIMARY KEY, student_name TEXT)');
        $this->db->exec('CREATE TABLE wp_olama_exam_placement_info (attempt_id INTEGER, student_name TEXT)');
        $this->db->exec('CREATE TABLE wp_olama_exam_essay_grades (id INTEGER PRIMARY KEY, attempt_id INTEGER, question_id INTEGER, score REAL, teacher_comment TEXT)');
        $this->db->exec("INSERT INTO wp_olama_exam_exams VALUES (1, 'School exam', 0), (2, 'Restricted exam', 0), (3, 'Placement exam', 1)");
        $this->db->exec("INSERT INTO wp_olama_students VALUES ('S-001', 'School Student')");
        $this->db->exec("INSERT INTO wp_olama_exam_placement_info VALUES (3, 'Placement Student')");
        $snapshot = json_encode(array(array('question_id'=>101, 'type'=>'essay', 'question_text'=>'Explain your answer', 'points'=>5)));
        $insert = $this->db->prepare('INSERT INTO wp_olama_exam_attempts VALUES (?, ?, ?, 0, 5, 0, ?, ?, ?, ?, ?, ?)');
        foreach (array(
            array(1, 1, 'S-001', 'pending', '2026-10-31 10:00:00', $snapshot, '{"101":"School answer"}', 'school', 0),
            array(2, 1, 'MISSING-UID', 'pending', '2026-10-31 10:00:00', $snapshot, '{"101":"Orphan answer"}', 'school', 0),
            array(3, 3, 'PLACEMENT-UID', 'pending', '2026-10-31 10:00:00', $snapshot, '{"101":"Placement answer"}', 'school', 0),
            array(4, 1, 'PREVIEW-UID', 'pending', '2026-10-31 10:00:00', $snapshot, '{}', 'school', 1),
            array(5, 1, 'ACCEPTANCE-UID', 'pending', '2026-10-31 10:00:00', $snapshot, '{}', 'acceptance', 0),
            array(6, 1, 'UNSUBMITTED-UID', 'pending', null, $snapshot, '{}', 'school', 0),
            array(7, 2, 'RESTRICTED-UID', 'pending', '2026-10-31 10:00:00', $snapshot, '{}', 'school', 0),
            array(8, 1, 'BLANK-ESSAY-UID', 'pending', '2026-10-31 10:00:00', $snapshot, '{"101":"  "}', 'school', 0),
        ) as $row) { $insert->execute($row); }
    }
    public function prepare($sql, ...$args) {
        $index = 0;
        return preg_replace_callback('/%d/', function() use ($args, &$index) { return (string) (int) $args[$index++]; }, $sql);
    }
    public function get_results($sql) { return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
    public function get_row($sql) { return $this->db->query($sql)->fetch(PDO::FETCH_OBJ) ?: null; }
    public function get_var($sql) { return $this->db->query($sql)->fetchColumn(); }
}
$wpdb = new Essay_View_DB();
function render_essay_view($attempt_id = 0) {
    global $wpdb;
    $_GET = array('page'=>'olama-exam-grade-essays', 'grade_attempt'=>$attempt_id);
    ob_start();
    try {
        include OLAMA_EXAM_PATH . 'admin/views/exam-grade-essays.php';
        return ob_get_clean();
    } catch (Throwable $error) { ob_end_clean(); throw $error; }
}
function check_essay_view($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$list = render_essay_view();
foreach (array('School Student', 'Placement Student', 'MISSING-UID') as $name) {
    check_essay_view(strpos($list, $name) !== false, 'Missing student identity: ' . $name);
}
foreach (array('PREVIEW-UID', 'ACCEPTANCE-UID', 'UNSUBMITTED-UID', 'RESTRICTED-UID', 'BLANK-ESSAY-UID') as $name) {
    check_essay_view(strpos($list, $name) === false, 'Inaccessible attempt leaked into school essay list: ' . $name);
}
foreach (array(1=>'School answer', 2=>'Orphan answer', 3=>'Placement answer') as $id=>$answer) {
    check_essay_view(strpos(render_essay_view($id), $answer) !== false, 'Essay details did not load for attempt ' . $id);
}
try {
    render_essay_view(7);
    check_essay_view(false, 'Restricted essay details should be denied.');
} catch (RuntimeException $error) {
    check_essay_view($error->getMessage() === 'Access Denied', 'Unexpected access error.');
}
echo "Essay grading list and detail queries passed against a student_uid-only database.\n";
