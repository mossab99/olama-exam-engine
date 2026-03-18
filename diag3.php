<?php
require_once dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
global $wpdb;

$unit_id = 383;
$lessons = $wpdb->get_results($wpdb->prepare(
    "SELECT cl.id, cl.lesson_number, cl.lesson_title,
            (SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions q WHERE q.lesson_id = cl.id) as question_count
     FROM {$wpdb->prefix}olama_curriculum_lessons cl
     WHERE cl.unit_id = %d
     ORDER BY CAST(lesson_number AS UNSIGNED) ASC, cl.id ASC",
    $unit_id
));
echo json_encode($lessons);
