<?php
/** Run with: php tests/test-subject-source.php (requires pdo_sqlite and sibling olama-school). */
define('ABSPATH', __DIR__);
function check_ajax_referer(...$args) { return true; }
function is_user_logged_in() { return true; }
function current_user_can($capability) { return true; }
class Subject_Response extends Exception {
    public $subjects;
    public function __construct($subjects) { $this->subjects = $subjects; }
}
function wp_send_json_success($subjects) { throw new Subject_Response($subjects); }
class Olama_School_Academic_Bridge {
    public static function get_study_year() { return '2026-2027'; }
    public static function sync($year) { return true; }
}
class Subject_Core {
    public function read_models() { return $this; }
    public function table($name) { return 'wp_core_' . $name; }
}
function olama_core() { return new Subject_Core(); }
class Subject_Source_DB {
    public $prefix = 'wp_';
    private $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_olama_subjects (
            id INTEGER, grade_id INTEGER, subject_name TEXT, is_active INTEGER,
            appear_in_student_gateway INTEGER, core_study_year TEXT,
            core_grade_id TEXT, core_subject_id TEXT)');
        $this->db->exec("INSERT INTO wp_olama_subjects VALUES
            (90,10,'Stale English name',1,1,'2026-2027','G10','12'),
            (91,10,'English',1,1,NULL,NULL,NULL),
            (92,10,'English',1,1,'2025-2026','G10','12'),
            (93,10,'Science',1,0,'2026-2027','G10','13'),
            (94,10,'Inactive',1,1,'2026-2027','G10','14'),
            (95,11,'Other grade',1,1,'2026-2027','G11','15')");
        $this->db->exec('CREATE TABLE wp_core_academic_grade_subjects (
            study_year TEXT, grade_id TEXT, subject_id TEXT, subject_name TEXT,
            is_active INTEGER, grade_name TEXT, last_synced_at TEXT)');
        $this->db->exec("INSERT INTO wp_core_academic_grade_subjects VALUES
            ('2026-2027','G10','12','English',1,'Grade 10',''),
            ('2025-2026','G10','12','English',1,'Grade 10',''),
            ('2026-2027','G10','13','Science',1,'Grade 10',''),
            ('2026-2027','G10','14','Inactive',0,'Grade 10',''),
            ('2026-2027','G11','15','Other grade',1,'Grade 11','')");
    }
    public function prepare($sql, ...$args) {
        $index = 0;
        return preg_replace_callback('/%[sd]/', function ($match) use ($args, &$index) {
            $value = $args[$index++];
            return $match[0] === '%d' ? (string) (int) $value : $this->db->quote($value);
        }, $sql);
    }
    public function get_results($sql) { return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
}
require dirname(__DIR__, 2) . '/olama-school/includes/class-subject.php';
require dirname(__DIR__) . '/includes/class-exam-ajax.php';
$wpdb = new Subject_Source_DB();
foreach (array(
    array(array('grade_id'=>10, 'question_bank'=>1), array(90)),
    array(array('grade_id'=>10), array(90,93)),
    array(array('grade_id'=>11, 'question_bank'=>1), array(95)),
    array(array('grade_id'=>0, 'question_bank'=>1), array()),
) as $case) {
    $_POST = $case[0];
    try { Olama_Exam_Ajax::handle_get_subjects_by_grade(); }
    catch (Subject_Response $response) {
        if (array_column($response->subjects, 'id') !== $case[1]) {
            throw new Exception('Unexpected subject IDs: ' . json_encode($response->subjects));
        }
        if ($case[1] && $case[1][0] === 90 && $response->subjects[0]->subject_name !== 'English') {
            throw new Exception('Subject name must come from Academic Management / Core.');
        }
    }
}
echo "Subject source passed: current-year Core names, stable School IDs, no legacy duplicates, active/grade/gateway filtering.\n";
