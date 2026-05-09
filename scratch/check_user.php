<?php
define('WP_USE_THEMES', false);
require_once('../../../../wp-load.php');

$username = 'emp105';
$user = get_user_by('login', $username);

if (!$user) {
    echo "User $username not found\n";
    exit;
}

echo "User: " . $user->display_name . " (ID: " . $user->ID . ")\n";
echo "Roles: " . implode(', ', $user->roles) . "\n";

if (class_exists('Olama_School_Permissions')) {
    echo "olama_access_supervision: " . (Olama_School_Permissions::can('olama_access_supervision', $user->ID) ? 'YES' : 'NO') . "\n";
    echo "olama_access_academic_mgmt: " . (Olama_School_Permissions::can('olama_access_academic_mgmt', $user->ID) ? 'YES' : 'NO') . "\n";
} else {
    echo "Olama_School_Permissions class NOT FOUND\n";
}

echo "manage_options: " . (user_can($user->ID, 'manage_options') ? 'YES' : 'NO') . "\n";

if (class_exists('Olama_Exam_Ajax')) {
    // Need to set current user to emp105 to test Olama_Exam_Ajax methods as they use get_current_user_id()
    wp_set_current_user($user->ID);
    echo "can_supervise_exams(): " . (Olama_Exam_Ajax::can_supervise_exams() ? 'YES' : 'NO') . "\n";
    
    $active_year = Olama_School_Academic::get_active_year();
    $active_year_id = $active_year ? $active_year->id : 0;
    echo "Active Year ID: $active_year_id\n";
    
    if (class_exists('Olama_School_Teacher')) {
        $assignments = Olama_School_Teacher::get_all_assignments($user->ID, $active_year_id);
        echo "Assignments Count: " . count($assignments) . "\n";
        foreach ($assignments as $a) {
            echo "- Grade: {$a->grade_id}, Section: {$a->section_id}, Subject: {$a->subject_id}\n";
        }
    }
}
