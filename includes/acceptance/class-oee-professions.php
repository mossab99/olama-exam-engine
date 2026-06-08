<?php
/**
 * CRUD for Professions (Employment Majors)
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEE_Professions
{
    /**
     * Get all professions
     */
    public static function get_all($status = '')
    {
        global $wpdb;
        $table = "{$wpdb->prefix}oee_professions";
        $query = "SELECT * FROM $table WHERE 1=1";
        $params = array();

        if (!empty($status)) {
            $query .= " AND status = %s";
            $params[] = sanitize_text_field($status);
        }

        $query .= " ORDER BY name_ar ASC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        return $wpdb->get_results($query);
    }

    /**
     * Get single profession
     */
    public static function get($id)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}oee_professions";
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            intval($id)
        ));
    }

    /**
     * Save profession (insert or update)
     */
    public static function save($data)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}oee_professions";
        $id = intval($data['id'] ?? 0);

        $fields = array(
            'name_ar' => sanitize_text_field($data['name_ar'] ?? ''),
            'name_en' => sanitize_text_field($data['name_en'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'status' => sanitize_text_field($data['status'] ?? 'active'),
        );

        if (empty($fields['name_ar'])) {
            return new WP_Error('empty_name_ar', olama_exam_translate('Arabic name is required.'));
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update profession.');
            }
            return $id;
        } else {
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to insert profession.');
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Delete profession (checks if any questions or tests are linked)
     */
    public static function delete($id)
    {
        global $wpdb;
        $id = intval($id);

        // Check if any tests are assigned
        $test_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}oee_acceptance_tests WHERE profession_id = %d",
            $id
        ));
        if ($test_count > 0) {
            return new WP_Error('linked_tests', olama_exam_translate('Cannot delete: this profession has acceptance tests linked to it.'));
        }

        // Check if any questions are assigned
        $question_count = self::get_question_count($id);
        if ($question_count > 0) {
            return new WP_Error('linked_questions', olama_exam_translate('Cannot delete: this profession has questions linked to it.'));
        }

        $result = $wpdb->delete("{$wpdb->prefix}oee_professions", array('id' => $id));
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete profession.');
        }
        return true;
    }

    /**
     * Get question count for a profession
     */
    public static function get_question_count($id)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_questions WHERE profession_id = %d",
            intval($id)
        ));
    }
}
