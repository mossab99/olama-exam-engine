<?php
/** Run with: php tests/test-exam-password.php */

define('ABSPATH', __DIR__);

class WP_Error
{
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class Exam_Password_Response extends Exception
{
    public $success;
    public $data;
    public function __construct($success, $data)
    {
        $this->success = $success;
        $this->data = $data;
    }
}

class Exam_Password_Wpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $exam;

    public function __construct() { $this->exam = (object) array('id' => 7); }
    public function prepare($query, ...$args) { return $query; }
    public function update($table, $fields, $where)
    {
        foreach ($fields as $key => $value) { $this->exam->$key = $value; }
        return 1;
    }
    public function get_row($query)
    {
        return strpos($query, 'olama_exam_attempts') !== false ? null : $this->exam;
    }
    public function get_var($query) { return 0; }
}

class Olama_Exam_Identity
{
    public static function can_access_student($student_uid) { return $student_uid === 'student-1'; }
}

class Olama_Exam_Engine
{
    public static function start_exam($exam_id, $student_uid, $is_preview, $is_admin_override, $exam_type)
    {
        return array('attempt_id' => 42);
    }
}

function wp_unslash($value) { return stripslashes($value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function olama_exam_translate($value) { return $value; }
function olama_exam_log($value, $level = '') {}
function get_current_user_id() { return 1; }
function check_ajax_referer($action, $field, $die) { return true; }
function is_user_logged_in() { return true; }
function current_user_can($capability) { return false; }
function wp_send_json_success($data) { throw new Exam_Password_Response(true, $data); }
function wp_send_json_error($data, $status = null) { throw new Exam_Password_Response(false, $data); }
function is_wp_error($value) { return $value instanceof WP_Error; }

$wpdb = new Exam_Password_Wpdb();
require_once dirname(__DIR__) . '/includes/class-exam-manager.php';
require_once dirname(__DIR__) . '/includes/class-exam-ajax.php';

$password = "O'Reilly\\2026";
$saved = Olama_Exam_Manager::save_exam(array(
    'id' => 7,
    'title' => 'Protected exam',
    'section_id' => 3,
    'password' => addslashes($password),
));
if ($saved !== 7 || $wpdb->exam->password !== $password) {
    fwrite(STDERR, "The saved password did not survive WordPress request slashing.\n");
    exit(1);
}

$_POST = array('exam_id' => 7, 'student_uid' => 'student-1', 'exam_type' => 'school', 'password' => addslashes($password));
$response_seen = false;
try {
    Olama_Exam_Ajax::handle_start();
} catch (Exam_Password_Response $response) {
    $response_seen = true;
    if (!$response->success || $response->data['attempt_id'] !== 42) {
        fwrite(STDERR, "The correct password was rejected.\n");
        exit(1);
    }
}
if (!$response_seen) {
    fwrite(STDERR, "The correct password produced no response.\n");
    exit(1);
}

$_POST['password'] = addslashes('wrong');
$response_seen = false;
try {
    Olama_Exam_Ajax::handle_start();
} catch (Exam_Password_Response $response) {
    $response_seen = true;
    if ($response->success || $response->data['code'] !== 'PASSWORD_REQUIRED') {
        fwrite(STDERR, "An incorrect password was accepted.\n");
        exit(1);
    }
}
if (!$response_seen) {
    fwrite(STDERR, "The incorrect password produced no response.\n");
    exit(1);
}

echo "Exam password checks passed.\n";
