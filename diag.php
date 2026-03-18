<?php
require_once dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
global $wpdb;

$unit_id = 383;
$results = $wpdb->get_results("SELECT lesson_id, COUNT(*) as c FROM {$wpdb->prefix}olama_exam_questions WHERE unit_id=$unit_id GROUP BY lesson_id");
foreach ($results as $row) {
    echo "Lesson ID {$row->lesson_id}: {$row->c} questions\n";
}
