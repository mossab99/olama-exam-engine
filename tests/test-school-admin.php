<?php
/** Run with: php tests/test-school-admin.php */
define('ABSPATH', __DIR__);
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
function absint($v) { return abs((int) $v); }
class Olama_Exam_Ajax {
    public static $supervisor = true;
    public static function can_supervise_exams() { return self::$supervisor; }
}
class Admin_Test_DB {
    public $prefix = 'wp_';
    public $params;
    public $sql;
    public function prepare($sql, $params) { $this->params = $params; return $sql; }
    public function query($sql) { $this->sql = $sql; return 3; }
    public function get_results($sql) { $this->sql = $sql; return array(); }
}
function check_admin($value, $message) {
    if (!$value) { fwrite(STDERR, $message . "\n"); exit(1); }
}
require dirname(__DIR__) . '/includes/class-exam-manager.php';
$wpdb = new Admin_Test_DB();
Olama_Exam_Manager::get_ordered_grades();
check_admin(strpos($wpdb->sql, 'CAST(grade_level AS SIGNED) ASC') !== false, 'Fallback grade ordering must use numeric academic level.');
check_admin(Olama_Exam_Manager::publish_results(8, 9, 2) === 3, 'Publishing must return updated count.');
check_admin($wpdb->params === array(8, 9, 2), 'Publishing must preserve explicit year, semester and grade scope.');
foreach (array("e.exam_type IN ('exam', 'quiz')", "e.status IN ('published', 'active', 'closed')", 'e.is_placement = 0', 'e.academic_year_id = %d', 'e.semester_id = %d', 'COALESCE(s.grade_id, sub.grade_id) = %d') as $condition) {
    check_admin(strpos($wpdb->sql, $condition) !== false, 'Publishing scope missing: ' . $condition);
}
Olama_Exam_Ajax::$supervisor = false;
$denied = Olama_Exam_Manager::publish_results(8, 9);
check_admin($denied instanceof WP_Error && $denied->code === 'forbidden', 'Only supervisors can publish results.');
Olama_Exam_Ajax::$supervisor = true;
$missing = Olama_Exam_Manager::publish_results(0, 9);
check_admin($missing instanceof WP_Error && $missing->code === 'missing_scope', 'Publishing must require an academic year.');

// Load the source-system branch only after verifying the fallback branch.
require __DIR__ . '/fixtures/school-grade-source.php';
$grades = Olama_Exam_Manager::get_ordered_grades();
check_admin(array_column($grades, 'grade_name') === array('الروضة', 'الأول الأساسي', 'الثاني الأساسي', 'الثاني الثانوي'), 'Grade lists must preserve academic source ordering and exclude inactive grades.');
echo "School admin grade order and results publishing checks passed.\n";
