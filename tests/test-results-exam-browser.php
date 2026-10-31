<?php
/** Run with: php tests/test-results-exam-browser.php (requires pdo_sqlite). */
define('ABSPATH', __DIR__);
define('OLAMA_EXAM_PATH', dirname(__DIR__) . '/');
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function esc_js($v) { return addslashes((string) $v); }
function olama_exam_translate($v) { return $v; }
function admin_url($v) { return '/wp-admin/' . $v; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function selected($a, $b) { if ((string) $a === (string) $b) echo 'selected'; }
function wp_create_nonce($v) { return 'fixture'; }
class Olama_Exam_Ajax {
    public static function can_supervise_exams() { return true; }
    public static function can_teacher_access_exam($exam) { return is_object($exam) && !empty($exam->subject_id) && (int) $exam->id !== 9; }
}
class Olama_School_Academic {
    public static function get_active_year() { return (object) array('id'=>8, 'year_name'=>'2026-2027'); }
    public static function get_active_semester($year) { return (object) array('id'=>11, 'semester_name'=>'First Semester'); }
    public static function get_years() { return array(self::get_active_year()); }
    public static function get_semesters($year) { return array(self::get_active_semester($year)); }
}
class Olama_School_Grade {
    public static function get_grades() { return array((object) array('id'=>1,'grade_name'=>'أول ثانوي')); }
}
class Olama_School_Section {
    public static function get_by_grade($grade, $year) { return array((object) array('id'=>$grade, 'section_name'=>$grade === 1 ? 'أ' : 'ب')); }
}
class Results_Browser_DB {
    public $prefix = 'wp_';
    public $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_olama_exam_exams (id INTEGER PRIMARY KEY, title TEXT, status TEXT, exam_type TEXT, start_time TEXT, end_time TEXT, created_at TEXT, academic_year_id INTEGER, semester_id INTEGER, section_id INTEGER, subject_id INTEGER, is_placement INTEGER DEFAULT 0)');
        $this->db->exec("CREATE TABLE wp_olama_sections (id INTEGER, grade_id INTEGER, section_name TEXT);
            INSERT INTO wp_olama_sections VALUES (1,1,'أ'), (2,2,'ب');
            CREATE TABLE wp_olama_grades (id INTEGER, grade_name TEXT);
            INSERT INTO wp_olama_grades VALUES (1,'أول ثانوي'), (2,'ثاني ثانوي');
            CREATE TABLE wp_olama_subjects (id INTEGER, grade_id INTEGER, subject_name TEXT);
            INSERT INTO wp_olama_subjects VALUES (1,1,'الرياضيات'), (2,2,'العلوم');
            CREATE TABLE wp_olama_students (id INTEGER, student_uid TEXT, student_name TEXT);
            CREATE TABLE wp_olama_student_enrollment (student_id INTEGER, section_id INTEGER);
            CREATE TABLE wp_olama_exam_attempts (id INTEGER, exam_id INTEGER, student_uid TEXT, score REAL, max_score REAL, percentage REAL, result TEXT, submitted_at TEXT, is_preview INTEGER DEFAULT 0, exam_type TEXT DEFAULT 'school', questions_snapshot_json TEXT, answers_json TEXT);
            INSERT INTO wp_olama_exam_attempts (id,exam_id,student_uid,percentage,result,submitted_at) VALUES
                (1,2,'S1',80,'pass','2026-10-31'), (2,2,'S2',40,'fail','2026-10-31'),
                (3,2,'S3',0,'pending','2026-10-31')");
        $insert = $this->db->prepare('INSERT INTO wp_olama_exam_exams (id,title,status,exam_type,start_time,end_time,created_at,academic_year_id,semester_id,section_id,subject_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach (array(
            array(1,'Old','active','exam','2026-10-01 10:00:00','2026-10-01 11:00:00','2026-10-25',8,11,1,1),
            array(2,'Latest','closed','exam','2026-10-30 10:00:00','2026-10-30 11:00:00','2026-09-01',8,11,1,1),
            array(3,'Published','published','exam','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',8,11,1,1),
            array(4,'Secret draft','draft','exam','2026-11-01 10:00:00','2026-11-01 11:00:00','2026-10-31',8,11,1,1),
            array(5,'Quiz','active','quiz','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',8,11,1,1),
            array(6,'Direct','closed','direct','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',8,11,1,1),
            array(7,'Legacy','active',null,'2026-10-15 10:00:00','2026-10-15 11:00:00','2026-09-01',8,11,1,1),
            array(8,'Other grade','active','exam','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',8,11,2,2),
            array(9,'Restricted','active','exam','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',8,11,1,1),
            array(10,'Other year','active','exam','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',7,11,1,1),
            array(11,'Other semester','active','exam','2026-10-20 10:00:00','2026-10-20 11:00:00','2026-09-01',8,12,1,1),
            array(13,'Tied latest','closed','exam','2026-10-30 10:00:00','2026-10-30 11:00:00','2026-09-01',8,11,1,1),
        ) as $row) $insert->execute($row);
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $index = 0;
        return preg_replace_callback('/%[ds]/', function($m) use ($args, &$index) { $v = $args[$index++]; return $m[0] === '%d' ? (string) (int) $v : $this->db->quote($v); }, $sql);
    }
    public function get_results($sql) { return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
    public function get_row($sql) { return $this->db->query($sql)->fetch(PDO::FETCH_OBJ) ?: null; }
}
require OLAMA_EXAM_PATH . 'includes/class-exam-manager.php';
$wpdb = new Results_Browser_DB();
function render_results_browser($params) {
    global $wpdb;
    $_GET = array_merge(array('page'=>'olama-exam-results','grade_id'=>1,'section_id'=>1), $params);
    ob_start();
    try {
        include OLAMA_EXAM_PATH . 'admin/views/exam-results.php';
        return array('html'=>ob_get_clean(), 'ids'=>array_column($exams, 'id'), 'type'=>$selected_exam_type, 'selected'=>$selected_exam_id);
    } catch (Throwable $e) { ob_end_clean(); throw $e; }
}
function check_browser($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
$view = render_results_browser(array());
check_browser($view['ids'] === array(13,2,3,7,1), 'Assessment list must exclude drafts, other scopes and restricted exams and sort by exam date descending.');
check_browser(strpos($view['html'], 'id="exam-type-filter"') !== false && strpos($view['html'], 'id="exam-select"') === false && strpos($view['html'], 'Secret draft') === false, 'The exam type/table UI must replace the old picker without exposing drafts.');
check_browser(substr_count($view['html'], 'class="oe-exam-summary-row') === 5, 'Each visible exam needs its own summary row.');
foreach (array('60%', '50%', 'بانتظار التصحيح: 1', 'ناجح: 1', 'راسب: 1', 'لا توجد محاولات مسلّمة') as $summary_text) {
    check_browser(strpos($view['html'], $summary_text) !== false, 'Summary content missing: ' . $summary_text);
}
check_browser(render_results_browser(array('exam_type'=>'quiz'))['ids'] === array(5), 'Quiz filter is incorrect.');
check_browser(render_results_browser(array('exam_type'=>'direct'))['ids'] === array(6), 'Direct filter is incorrect.');
check_browser(render_results_browser(array('grade_id'=>2,'section_id'=>2))['ids'] === array(8), 'Grade and section selection must filter the list.');
check_browser(render_results_browser(array('section_id'=>2))['ids'] === array(), 'Grade and section must both constrain the list.');
foreach (array(array('grade_id'=>0), array('section_id'=>0), array('grade_id'=>0,'section_id'=>0), array('section_id'=>99)) as $scope) {
    $empty = render_results_browser($scope);
    check_browser($empty['ids'] === array() && strpos($empty['html'], 'اختر الصف والشعبة لعرض نتائج الامتحانات.') !== false && strpos($empty['html'], 'oe-exam-summary-row') === false, 'Both a valid grade and its section are mandatory before displaying exams and statistics.');
}
check_browser(render_results_browser(array('exam_id'=>2,'section_id'=>0))['selected'] === 0, 'An exam link must not bypass mandatory class selection.');
check_browser(render_results_browser(array('exam_id'=>8))['selected'] === 0, 'Result detail must belong to the selected section.');
check_browser(strpos($view['html'], 'oe-results-table-scroll') === false, 'Results must flow with the page rather than inside a nested scrolling container.');
check_browser(render_results_browser(array('exam_id'=>4))['selected'] === 0, 'Draft result links must be rejected.');
check_browser(render_results_browser(array('exam_id'=>9))['selected'] === 0, 'Unauthorized result links must be rejected.');
check_browser(render_results_browser(array('exam_id'=>6))['type'] === 'direct', 'Direct links must infer the proper exam type.');
echo "Results browser type filters, status exclusions, chronological ordering and access checks passed.\n";
