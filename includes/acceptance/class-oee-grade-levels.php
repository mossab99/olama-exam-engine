<?php
/**
 * CRUD for Student Grade Levels
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEE_Grade_Levels
{
    public static function get_all($status = '')
    {
        global $wpdb;
        $table = "{$wpdb->prefix}oee_grade_levels";
        $where = $status ? $wpdb->prepare("WHERE status = %s", $status) : '';
        return $wpdb->get_results(
            "SELECT * FROM $table $where ORDER BY sort_order ASC"
        );
    }

    public static function get($id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}oee_grade_levels";
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            intval($id)
        ));
    }

    public static function save($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'oee_grade_levels';
        $id = isset($data['id']) ? intval($data['id']) : 0;

        $fields = array(
            'name_ar'    => sanitize_text_field($data['name_ar'] ?? ''),
            'name_en'    => sanitize_text_field($data['name_en'] ?? ''),
            'sort_order' => intval($data['sort_order'] ?? 0),
            'status'     => sanitize_text_field($data['status'] ?? 'active'),
        );

        if (empty($fields['name_ar'])) {
            return new WP_Error('empty_name_ar', olama_exam_translate('Arabic name is required.'));
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update grade level.');
            }
            return $id;
        } else {
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to insert grade level.');
            }
            return $wpdb->insert_id;
        }
    }

    public static function delete($id)
    {
        global $wpdb;
        $id = intval($id);

        // Check if linked to any tests
        $test_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}oee_student_tests WHERE grade_level_id = %d",
            $id
        ));
        if ($test_count > 0) {
            return new WP_Error('linked_tests', olama_exam_translate('Cannot delete: this grade level has acceptance tests linked to it.'));
        }

        // Check if linked to any questions
        $question_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions WHERE grade_level_id = %d",
            $id
        ));
        if ($question_count > 0) {
            return new WP_Error('linked_questions', olama_exam_translate('Cannot delete: this grade level has questions linked to it.'));
        }

        $result = $wpdb->delete($wpdb->prefix . 'oee_grade_levels', array('id' => $id));
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete grade level.');
        }
        return true;
    }

    /**
     * Returns an array keyed by category_id with question counts.
     * Used to validate a test's subject_config is satisfiable before saving.
     */
    public static function get_question_count_by_category($grade_level_id)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT category_id, COUNT(*) AS cnt
             FROM {$wpdb->prefix}olama_exam_questions
             WHERE grade_level_id = %d
             GROUP BY category_id",
            intval($grade_level_id)
        ));
        $map = array();
        foreach ($rows as $row) {
            $map[$row->category_id] = intval($row->cnt);
        }
        return $map;
    }
}
