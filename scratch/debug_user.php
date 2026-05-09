<?php
define('WP_USE_THEMES', false);
require_once('../../../../wp-load.php');

$username = 'emp105';
$user = get_user_by('login', $username);

if (!$user) {
    file_put_contents('debug_user.txt', "User $username not found\n");
    exit;
}

$output = "User: " . $user->display_name . " (ID: " . $user->ID . ")\n";
$output .= "Roles: " . implode(', ', $user->roles) . "\n";

if (class_exists('Olama_School_Permissions')) {
    $output .= "olama_access_supervision: " . (Olama_School_Permissions::can('olama_access_supervision', $user->ID) ? 'YES' : 'NO') . "\n";
    $output .= "olama_access_academic_mgmt: " . (Olama_School_Permissions::can('olama_access_academic_mgmt', $user->ID) ? 'YES' : 'NO') . "\n";
}

$output .= "manage_options: " . (user_can($user->ID, 'manage_options') ? 'YES' : 'NO') . "\n";

if (class_exists('Olama_Exam_Ajax')) {
    wp_set_current_user($user->ID);
    $output .= "can_supervise_exams(): " . (Olama_Exam_Ajax::can_supervise_exams() ? 'YES' : 'NO') . "\n";
    
    if (class_exists('Olama_School_Academic')) {
        $active_year = Olama_School_Academic::get_active_year();
        $active_year_id = $active_year ? $active_year->id : 0;
        $output .= "Active Year ID: $active_year_id\n";
        
        if (class_exists('Olama_School_Teacher')) {
            $assignments = Olama_School_Teacher::get_all_assignments($user->ID, $active_year_id);
            $output .= "Assignments Count: " . count($assignments) . "\n";
            foreach ($assignments as $a) {
                $output .= "- Grade: {$a->grade_id}, Section: {$a->section_id}, Subject: {$a->subject_id}\n";
            }
        }
    }
}

file_put_contents('debug_user.txt', $output);
