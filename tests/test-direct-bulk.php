<?php
/** Run with: php tests/test-direct-bulk.php (requires pdo_sqlite). */
define('ABSPATH', __DIR__);
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return stripslashes($value); }
function get_current_user_id() { return 1; }
function current_time($format) { return '2026-11-02 12:00:00'; }
function olama_exam_translate($value) { return $value; }
function olama_exam_log($value) {}
function olama_exam_get_active_academic_context() { return array((object) array('id'=>1), (object) array('id'=>5)); }
class Olama_Exam_Ajax {
    public static $supervisor = false;
    public static function can_teacher_access_exam($exam) { return self::$supervisor || $exam->section_id === 7; }
}
class Bulk_DB {
    public $prefix = 'wp_', $last_error = '', $insert_id = 0, $fail = '', $locked = false;
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('FIELD', function($value, ...$values) { return array_search($value, $values) + 1; });
        $this->db->exec('CREATE TABLE wp_olama_subjects (id INTEGER, grade_id INTEGER, is_active INTEGER, subject_name TEXT);
            INSERT INTO wp_olama_subjects VALUES (3,2,1,"Math"),(4,2,1,"Science");
            CREATE TABLE wp_olama_sections (id INTEGER, grade_id INTEGER, academic_year_id INTEGER, section_name TEXT);
            INSERT INTO wp_olama_sections VALUES (7,2,1,"A"),(8,2,1,"B"),(9,2,2,"Old");
            CREATE TABLE wp_olama_grades (id INTEGER, grade_name TEXT);
            INSERT INTO wp_olama_grades VALUES (2,"Grade");
            CREATE TABLE wp_olama_curriculum_units (id INTEGER, subject_id INTEGER, grade_id INTEGER, semester_id INTEGER, unit_name TEXT, unit_number INTEGER);
            INSERT INTO wp_olama_curriculum_units VALUES (4,3,2,5,"Unit",1),(5,4,2,5,"Other subject",2);
            CREATE TABLE wp_olama_curriculum_lessons (id INTEGER, unit_id INTEGER, lesson_title TEXT);
            INSERT INTO wp_olama_curriculum_lessons VALUES (11,4,"Lesson"),(12,4,"Small"),(13,4,"Empty"),(14,5,"Other");
            CREATE TABLE wp_olama_exam_questions (id INTEGER PRIMARY KEY, lesson_id INTEGER, unit_id INTEGER);
            CREATE TABLE wp_olama_exam_attempts (id INTEGER PRIMARY KEY, exam_id INTEGER, exam_type TEXT);
            CREATE TABLE wp_olama_exam_essay_grades (id INTEGER PRIMARY KEY, attempt_id INTEGER);');
        $columns = array('id INTEGER PRIMARY KEY', 'direct_bulk_key TEXT UNIQUE');
        foreach (array('title','start_time','end_time','question_mode','random_difficulty','manual_question_ids','password','exam_type','status','created_at') as $field) $columns[] = "$field TEXT";
        foreach (array('section_id','subject_id','teacher_id','academic_year_id','semester_id','duration_minutes','passing_grade','max_attempts','show_results','show_correct_answers','is_placement','question_limit','direct_lesson_id','random_count','random_category_id','random_unit_id','random_lesson_id') as $field) $columns[] = "$field INTEGER";
        $this->db->exec('CREATE TABLE wp_olama_exam_exams (' . implode(',', $columns) . ')');
        for ($i=1; $i<=59; $i++) $this->db->exec("INSERT INTO wp_olama_exam_questions VALUES ($i,11,4)");
        $this->db->exec('INSERT INTO wp_olama_exam_questions VALUES (60,12,4),(61,11,99),(62,14,5)');
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function($m) use ($args, &$i) {
            $value = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $value : $this->db->quote($value);
        }, $sql);
    }
    public function get_var($sql) {
        if (strpos($sql, 'GET_LOCK') !== false) { if ($this->locked) return 0; $this->locked = true; return 1; }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { $this->locked = false; return 1; }
        return $this->db->query($sql)->fetchColumn();
    }
    public function get_results($sql) { return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
    public function get_row($sql) { return $this->db->query($sql)->fetch(PDO::FETCH_OBJ) ?: null; }
    public function get_col($sql) { return $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    public function query($sql) {
        if ($this->fail && strpos($sql, $this->fail) !== false) return false;
        return $this->db->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql);
    }
    public function insert($table, $fields) {
        $sql = "INSERT INTO $table (" . implode(',', array_keys($fields)) . ') VALUES (' . implode(',', array_fill(0,count($fields),'?')) . ')';
        $this->db->prepare($sql)->execute(array_values($fields));
        $this->insert_id = (int) $this->db->lastInsertId();
        return 1;
    }
    public function update($table, $fields, $where) {
        if ($this->fail === 'activate' && ($fields['status'] ?? '') === 'active') return false;
        $sql = "UPDATE $table SET " . implode(',', array_map(function($key) { return "$key = ?"; }, array_keys($fields))) . ' WHERE id = ?';
        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge(array_values($fields), array($where['id'])));
        return $statement->rowCount();
    }
}
require dirname(__DIR__) . '/includes/class-exam-manager.php';
require dirname(__DIR__) . '/includes/class-exam-direct-bulk.php';
function check_bulk($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
function execute_bulk($operation, $scope) {
    $plan = Olama_Exam_Direct_Bulk::plan($scope);
    return Olama_Exam_Direct_Bulk::execute(array_merge($scope, array('fingerprint'=>$plan['fingerprint'])), $operation);
}
$wpdb = new Bulk_DB();
$scope = array('grade_id'=>2,'subject_id'=>3,'academic_year_id'=>1,'semester_id'=>5);
$manual = array_merge($scope, array('title'=>'Manual','section_id'=>7,'exam_type'=>'direct','direct_lesson_id'=>11,'question_mode'=>'random','random_count'=>2));
Olama_Exam_Manager::save_exam($manual);
$plan = Olama_Exam_Direct_Bulk::plan($scope);
check_bulk($plan['sections'] === 1 && $plan['ready_lessons'] === 2 && $plan['empty_lessons'] === 1 && $plan['create_count'] === 2, 'Teacher scope, lessons, and empty-bank filtering');
check_bulk($plan['_jobs'][0]['random_count'] === 24 && $plan['_jobs'][1]['random_count'] === 1, 'Rounded 40% and minimum one, excluding mismatched unit questions');
check_bulk(!is_wp_error(execute_bulk('create', $scope)), 'Teacher creation succeeds');
check_bulk((int) $wpdb->get_var("SELECT COUNT(*) FROM wp_olama_exam_exams WHERE status='active' AND max_attempts=0 AND duration_minutes=0 AND show_results=1 AND show_correct_answers=1") === 2, 'Generated exams active and practice policy enforced');
check_bulk($wpdb->get_var('SELECT status FROM wp_olama_exam_exams WHERE id=1') === 'draft', 'Manual exam unchanged');
check_bulk(Olama_Exam_Direct_Bulk::plan($scope)['create_count'] === 0 && !is_wp_error(execute_bulk('create', $scope)), 'Repeat execution does not duplicate');
Olama_Exam_Ajax::$supervisor = true;
check_bulk(Olama_Exam_Direct_Bulk::plan($scope)['create_count'] === 2, 'Supervisor sees missing second-section exams');
check_bulk(!is_wp_error(execute_bulk('create', $scope)), 'Supervisor fills missing section');
$invalid = Olama_Exam_Direct_Bulk::plan(array_merge($scope, array('academic_year_id'=>2)));
check_bulk(is_wp_error($invalid) && $invalid->code === 'invalid_scope', 'Reject inactive academic context');
$invalid = Olama_Exam_Direct_Bulk::plan(array_merge($scope, array('grade_id'=>99)));
check_bulk(is_wp_error($invalid) && $invalid->code === 'invalid_subject', 'Reject subject-grade mismatch');
$old = Olama_Exam_Direct_Bulk::plan($scope);
$wpdb->db->exec("INSERT INTO wp_olama_exam_attempts VALUES (1,1,'school'),(2,1,'acceptance'); INSERT INTO wp_olama_exam_essay_grades VALUES (1,1),(2,2)");
$stale = Olama_Exam_Direct_Bulk::execute(array_merge($scope,array('fingerprint'=>$old['fingerprint'])), 'delete');
check_bulk(is_wp_error($stale) && $stale->code === 'changed' && !$wpdb->locked, 'Stale destructive plan rejected and lock released');
$wpdb->fail = 'DELETE FROM wp_olama_exam_exams';
check_bulk(is_wp_error(execute_bulk('delete', $scope)), 'Inject deletion failure');
check_bulk((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_essay_grades') === 2 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_attempts') === 2, 'Failed deletion rolls back dependent rows');
$wpdb->fail = '';
Olama_Exam_Ajax::$supervisor = false;
check_bulk(!is_wp_error(execute_bulk('delete', $scope)), 'Teacher bulk deletion succeeds');
check_bulk((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_exams') === 2, 'Other section exams retained');
check_bulk((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_attempts') === 1 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_essay_grades') === 1, 'School attempts and essays removed; colliding acceptance IDs retained');
check_bulk((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_questions') === 62, 'Question bank retained');
$wpdb->fail = 'activate';
check_bulk(is_wp_error(execute_bulk('create', $scope)), 'Inject activation failure');
check_bulk((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_olama_exam_exams') === 2, 'Creation and publishing roll back if activation fails');
$wpdb->fail = '';
check_bulk(!is_wp_error(execute_bulk('create', $scope)), 'Recreate after deletion');
$wpdb->locked = true;
check_bulk(execute_bulk('create', $scope)->code === 'busy', 'Concurrent bulk actions refused');
echo "Direct bulk scope, rounding, activation, idempotency, permissions, deletion isolation, stale plans, locking and rollback passed.\n";
