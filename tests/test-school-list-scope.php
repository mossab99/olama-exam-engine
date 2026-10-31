<?php
/** Run with: php tests/test-school-list-scope.php (requires pdo_sqlite). */
define('ABSPATH', __DIR__);
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
function absint($value) { return abs((int) $value); }
$active_test_year = (object) array('id'=>8);
function olama_exam_get_active_academic_context() { global $active_test_year; return array($active_test_year, null); }
class Olama_School_Academic {
    public static function get_semesters($year_id) {
        return $year_id === 8 ? array((object) array('id'=>11), (object) array('id'=>12)) : array();
    }
}
class List_Scope_DB {
    public $prefix = 'wp_';
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_olama_sections (id INTEGER, grade_id INTEGER, academic_year_id INTEGER)');
        $this->db->exec('INSERT INTO wp_olama_sections VALUES (4, 2, 8), (5, 3, 8), (6, 2, 7)');
        $this->db->exec('CREATE TABLE wp_olama_subjects (id INTEGER, grade_id INTEGER, is_active INTEGER)');
        $this->db->exec('INSERT INTO wp_olama_subjects VALUES (20, 2, 1), (21, 3, 1), (22, 2, 0)');
    }
    public function prepare($sql, ...$args) {
        $index = 0;
        return preg_replace_callback('/%d/', function() use ($args, &$index) { return (string) (int) $args[$index++]; }, $sql);
    }
    public function get_row($sql) { return $this->db->query($sql)->fetch(PDO::FETCH_OBJ); }
    public function get_var($sql) { return $this->db->query($sql)->fetchColumn(); }
}
require dirname(__DIR__) . '/includes/class-exam-manager.php';
$wpdb = new List_Scope_DB();
function check_scope($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$base = array('grade_id'=>2, 'section_id'=>4, 'semester_id'=>11, 'subject_id'=>20, 'academic_year_id'=>999);
$scope = Olama_Exam_Manager::get_section_list_scope($base);
check_scope($scope === array('academic_year_id'=>8, 'semester_id'=>11, 'grade_id'=>2, 'section_id'=>4), 'The list must use the active system year, with the selected semester, grade and section.');
check_scope(is_array(Olama_Exam_Manager::get_section_list_scope(array_merge($base, array('semester_id'=>12, 'subject_id'=>0)))), 'Switching semester or selecting all grade subjects must work.');
foreach (array('grade_id', 'section_id', 'semester_id') as $key) {
    $error = Olama_Exam_Manager::get_section_list_scope(array_merge($base, array($key=>0, 'status'=>'published', 'search'=>'exam')));
    check_scope($error instanceof WP_Error && $error->code === 'missing_scope', 'Status and search cannot bypass mandatory scope: ' . $key);
}
foreach (array(array('semester_id',99,'invalid_semester'), array('section_id',5,'invalid_section'), array('section_id',6,'invalid_section'), array('subject_id',21,'invalid_subject'), array('subject_id',22,'invalid_subject')) as $case) {
    $error = Olama_Exam_Manager::get_section_list_scope(array_merge($base, array($case[0]=>$case[1])));
    check_scope($error instanceof WP_Error && $error->code === $case[2], 'Mismatched scope was accepted: ' . implode('/', $case));
}
$active_test_year = null;
$error = Olama_Exam_Manager::get_section_list_scope($base);
check_scope($error instanceof WP_Error && $error->code === 'missing_scope', 'An active academic year is required.');
echo "School list mandatory scope, semester selection, and grade-dependent subjects passed.\n";
