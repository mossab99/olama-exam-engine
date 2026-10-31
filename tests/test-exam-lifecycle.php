<?php
/** Run with: php tests/test-exam-lifecycle.php (requires pdo_sqlite). */
define('ABSPATH', __DIR__);
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return trim((string) $v); }
function olama_exam_translate($v) { return $v; }
$test_now = '2026-10-31 12:00:00';
function current_time($format) { global $test_now; return $format === 'timestamp' ? strtotime($test_now) : $test_now; }
$test_events = array();
$test_hooks = array();
function add_filter($hook, $callback) { global $test_hooks; $test_hooks[$hook] = $callback; }
function add_action($hook, $callback) { global $test_hooks; $test_hooks[$hook] = $callback; }
function wp_next_scheduled($hook) { global $test_events; return $test_events[$hook]['timestamp'] ?? false; }
function wp_schedule_event($timestamp, $schedule, $hook) {
    global $test_events;
    $test_events[$hook] = array('timestamp'=>$timestamp, 'schedule'=>$schedule);
}
class Olama_Exam_Identity {
    public static function can_access_student($uid) { return $uid === 'S1'; }
}
class Passed_Start_Gate extends RuntimeException {}
class Lifecycle_DB {
    public $prefix = 'wp_';
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_olama_exam_exams (id INTEGER PRIMARY KEY, title TEXT, status TEXT,
            start_time TEXT, end_time TEXT, section_id INTEGER DEFAULT 0, subject_id INTEGER DEFAULT 0,
            is_placement INTEGER DEFAULT 0, show_results INTEGER DEFAULT 1, exam_type TEXT DEFAULT 'exam')");
        $this->db->exec('CREATE TABLE wp_olama_sections (id INTEGER, grade_id INTEGER, section_name TEXT)');
        $this->db->exec('CREATE TABLE wp_olama_grades (id INTEGER, grade_name TEXT)');
        $this->db->exec('CREATE TABLE wp_olama_subjects (id INTEGER, subject_name TEXT)');
        $this->db->exec("CREATE TABLE wp_olama_exam_attempts (id INTEGER PRIMARY KEY, exam_id INTEGER, student_uid TEXT,
            attempt_number INTEGER DEFAULT 1, score REAL DEFAULT 4, max_score REAL DEFAULT 5, percentage REAL DEFAULT 80,
            result TEXT DEFAULT 'pass', submitted_at TEXT, is_preview INTEGER DEFAULT 0, exam_type TEXT DEFAULT 'school')");
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $index = 0;
        return preg_replace_callback('/%[ds]/', function($match) use ($args, &$index) {
            $value = $args[$index++];
            return $match[0] === '%d' ? (string) (int) $value : $this->db->quote($value);
        }, $sql);
    }
    public function query($sql) { return $this->db->exec($sql); }
    public function get_row($sql) {
        if (strpos($sql, 'WHERE exam_id =') !== false) throw new Passed_Start_Gate();
        return $this->db->query($sql)->fetch(PDO::FETCH_OBJ) ?: null;
    }
    public function get_results($sql) { return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
    public function update($table, $fields, $where) {
        $sql = "UPDATE $table SET status = :status WHERE id = :id";
        $statement = $this->db->prepare($sql);
        $statement->execute(array(':status'=>$fields['status'], ':id'=>$where['id']));
        return $statement->rowCount();
    }
    public function exam($id, $status, $end = '2026-10-31 13:00:00', $start = '2026-10-31 11:00:00') {
        $statement = $this->db->prepare('INSERT OR REPLACE INTO wp_olama_exam_exams (id,title,status,start_time,end_time) VALUES (?,?,?,?,?)');
        $statement->execute(array($id, 'Exam ' . $id, $status, $start, $end));
    }
}
require dirname(__DIR__) . '/includes/class-exam-manager.php';
require dirname(__DIR__) . '/includes/class-exam-engine.php';
$wpdb = new Lifecycle_DB();
function check_lifecycle($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
function expect_gate($id, $code = null, $preview = false, $override = false) {
    try {
        $result = Olama_Exam_Engine::start_exam($id, 'S1', $preview, $override, 'school');
        check_lifecycle($code !== null && $result instanceof WP_Error && $result->code === $code, 'Unexpected start result for exam ' . $id);
    } catch (Passed_Start_Gate $error) {
        check_lifecycle($code === null, 'Exam unexpectedly passed the activation/time gate: ' . $id);
    }
}
$wpdb->exam(1, 'draft');
$wpdb->exam(2, 'published');
$wpdb->exam(3, 'active');
$wpdb->exam(4, 'closed');
expect_gate(1, 'not_active');
expect_gate(2, 'not_active');
expect_gate(3);
expect_gate(4, 'not_active');
expect_gate(2, null, true); // Teacher preview remains available before activation.
expect_gate(1, null, false, true); // Authorized administrative override is preserved.
$wpdb->exam(5, 'active', '2026-10-31 13:00:00', '2026-10-31 12:30:00');
expect_gate(5, 'outside_window');
$wpdb->exam(6, 'active', '2026-10-31 12:00:00');
expect_gate(6, 'outside_window'); // Exact deadline is closed to new attempts.
check_lifecycle(Olama_Exam_Manager::update_status(2, 'active') === true, 'Published exam must be manually activatable.');
expect_gate(2);
$wpdb->exam(7, 'published', '2026-10-31 11:59:59');
$expired = Olama_Exam_Manager::update_status(7, 'active');
check_lifecycle($expired instanceof WP_Error && $expired->code === 'expired_exam', 'An expired exam cannot be activated.');
$wpdb->exam(8, 'draft', '2026-10-31 10:00:00');
$wpdb->exam(9, 'published', null);
check_lifecycle(Olama_Exam_Manager::close_expired_exams() === 2, 'Only overdue published/active exams should auto-close.');
check_lifecycle(Olama_Exam_Manager::get_exam(6)->status === 'closed' && Olama_Exam_Manager::get_exam(7)->status === 'closed', 'Expired states were not persisted.');
check_lifecycle(Olama_Exam_Manager::get_exam(3)->status === 'active' && Olama_Exam_Manager::get_exam(8)->status === 'draft' && Olama_Exam_Manager::get_exam(9)->status === 'published', 'Expiry changed a future/draft/undated exam.');
check_lifecycle(Olama_Exam_Manager::close_expired_exams() === 0, 'Expiry sweep must be idempotent.');

// Published results stay available for closed exams, while inactive exams stay private.
$wpdb->exam(10, 'published');
$wpdb->exam(11, 'closed');
$wpdb->db->exec("INSERT INTO wp_olama_exam_attempts (id,exam_id,student_uid,submitted_at) VALUES
    (1,3,'S1','2026-10-31 11:00:00'), (2,10,'S1','2026-10-31 11:00:00'), (3,11,'S1','2026-10-31 11:00:00')");
$results = Olama_Exam_Manager::get_student_results('S1');
check_lifecycle(array_column($results, 'exam_id') === array(11, 3), 'Active and closed results must be retained; unpublished exam results must stay hidden.');
$wpdb->db->exec('UPDATE wp_olama_exam_exams SET show_results = 0 WHERE id = 11');
check_lifecycle(count(Olama_Exam_Manager::get_student_results('S1')) === 1, 'Closing must not bypass result publication settings.');
check_lifecycle(Olama_Exam_Manager::get_student_results('OTHER') === array(), 'Results must remain private to the student principal.');
$wpdb->db->exec("INSERT INTO wp_olama_exam_attempts (id,exam_id,student_uid) VALUES (4,10,'S1')");
$resume = Olama_Exam_Engine::resume_exam(4);
check_lifecycle($resume instanceof WP_Error && $resume->code === 'not_active', 'A saved attempt must not reveal an unpublished exam through resume.');
Olama_Exam_Manager::init_lifecycle();
Olama_Exam_Manager::init_lifecycle();
check_lifecycle(count($test_events) === 1 && $test_events['olama_exam_close_expired']['schedule'] === 'olama_exam_minute', 'Expiry must register a single recurring event.');
$schedules = call_user_func($test_hooks['cron_schedules'], array());
check_lifecycle($schedules['olama_exam_minute']['interval'] === 60 && is_callable($test_hooks['olama_exam_close_expired']), 'The scheduled sweep must run every minute.');
echo "Exam activation gate, automatic expiry, previews and closed-exam results passed.\n";
