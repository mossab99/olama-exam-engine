<?php
require_once dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
global $wpdb;

$unit_id = 383;
$results = $wpdb->get_results("SELECT id, lesson_number, lesson_title FROM {$wpdb->prefix}olama_curriculum_lessons WHERE unit_id=$unit_id");
foreach ($results as $row) {
    echo "Lesson ID {$row->id} (No. {$row->lesson_number}): {$row->lesson_title}\n";
}
