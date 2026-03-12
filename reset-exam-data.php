<?php
/**
 * TEMPORARY: Force-reset all exam engine data.
 * Access via: http://olama3.local/wp-content/plugins/olama-exam-engine/reset-exam-data.php
 * DELETE THIS FILE AFTER USE.
 */
require_once dirname(__FILE__, 4) . '/wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

global $wpdb;

$tables = array(
    "{$wpdb->prefix}olama_exam_essay_grades",
    "{$wpdb->prefix}olama_exam_attempts",
    "{$wpdb->prefix}olama_exam_exams",
    "{$wpdb->prefix}olama_exam_questions",
    "{$wpdb->prefix}olama_exam_question_categories",
);

echo "<h2>🧹 Resetting Exam Engine Data...</h2><ul>";

foreach ($tables as $table) {
    $wpdb->query("TRUNCATE TABLE $table");
    echo "<li>✅ Truncated <code>$table</code></li>";
}

// Reset migration & DB version flags so they re-run on next page load
delete_option('olama_exam_unit_id_migrated');
delete_option('olama_exam_db_version');
echo "<li>✅ Reset migration flags</li>";

echo "</ul><h3>✅ All exam data cleared! <a href='" . admin_url('admin.php?page=olama-exam-bank') . "'>Go to Question Bank →</a></h3>";
echo "<p style='color:red;'><strong>⚠️ Delete this file now: <code>reset-exam-data.php</code></strong></p>";
