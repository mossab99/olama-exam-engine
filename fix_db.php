<?php
require_once dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
global $wpdb;

$unit_id = 383; // Based on URL in screenshot
$lesson_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}olama_curriculum_lessons WHERE unit_id=$unit_id AND lesson_number=1 LIMIT 1");
if ($lesson_id) {
    $count = $wpdb->query("UPDATE {$wpdb->prefix}olama_exam_questions SET lesson_id=$lesson_id WHERE unit_id=$unit_id AND lesson_id=0");
    echo "Updated $count questions to lesson $lesson_id in unit $unit_id\n";
} else {
    echo "Lesson 1 in unit 383 not found\n";
}
