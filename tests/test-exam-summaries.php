<?php
/** Run with: php tests/test-exam-summaries.php (requires pdo_sqlite). */
define('ABSPATH', __DIR__);
function absint($v) { return abs((int) $v); }
class Summary_Test_DB {
    public $prefix = 'wp_';
    public $db;
    public $queries = 0;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_olama_exam_attempts (exam_id INTEGER, result TEXT, percentage REAL, submitted_at TEXT, is_preview INTEGER, exam_type TEXT)');
        $insert = $this->db->prepare('INSERT INTO wp_olama_exam_attempts VALUES (?, ?, ?, ?, ?, ?)');
        foreach (array(
            array(1,'pass',80,'2026-10-31',0,'school'),
            array(1,'pass',100,'2026-10-31',0,'school'),
            array(1,'fail',40,'2026-10-31',0,'school'),
            array(1,'pending',0,'2026-10-31',0,'school'),
            array(1,'pass',999,'2026-10-31',1,'school'),
            array(1,'pass',999,'2026-10-31',0,'acceptance'),
            array(1,'pass',999,null,0,'school'),
            array(2,'pending',null,'2026-10-31',0,'school'),
            array(4,'pass',100,'2026-10-31',0,'school'),
        ) as $row) $insert->execute($row);
    }
    public function prepare($sql, $ids) {
        $index = 0;
        return preg_replace_callback('/%d/', function() use ($ids, &$index) { return (string) (int) $ids[$index++]; }, $sql);
    }
    public function get_results($sql) { $this->queries++; return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ); }
}
require dirname(__DIR__) . '/includes/class-exam-manager.php';
$wpdb = new Summary_Test_DB();
function check_summary($condition, $message) {
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$s = Olama_Exam_Manager::get_exam_summaries(array(1, 2, 3, 1, 0));
check_summary($wpdb->queries === 1, 'All visible exams must be aggregated in one query.');
check_summary(array_keys($s) === array(1,2,3), 'Only explicitly requested exams should be summarized.');
check_summary($s[1] === array('attempts'=>4, 'graded'=>3, 'passed'=>2, 'failed'=>1, 'pending'=>1,
    'average'=>73.3, 'highest'=>100.0, 'lowest'=>40.0, 'pass_rate'=>66.7), 'Summary must exclude previews, applicants, unsubmitted attempts and ungraded scores.');
check_summary($s[2]['attempts'] === 1 && $s[2]['pending'] === 1 && $s[2]['failed'] === 0 && $s[2]['average'] === null && $s[2]['pass_rate'] === null, 'Pending grading must not be presented as failure or zero performance.');
check_summary($s[3]['attempts'] === 0 && $s[3]['average'] === null && $s[3]['pass_rate'] === null, 'An exam without attempts must have an empty summary.');
check_summary(Olama_Exam_Manager::get_exam_summaries(array()) === array() && $wpdb->queries === 1, 'An empty list must not query the database.');
echo "Exam summaries: scope, aggregate accuracy, empty states and pending grading passed.\n";
