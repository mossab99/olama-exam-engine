<?php
/**
 * CRUD for Acceptance Tests
 */

if (!defined('ABSPATH')) {
    exit;
}

class OEE_Acceptance_Tests
{
    /**
     * Get all acceptance tests with joined profession info
     */
    public static function get_all($filters = array())
    {
        global $wpdb;
        $table_tests = "{$wpdb->prefix}oee_acceptance_tests";
        $table_professions = "{$wpdb->prefix}oee_professions";

        $query = "SELECT t.*, p.name_ar as profession_name_ar 
                  FROM $table_tests t 
                  LEFT JOIN $table_professions p ON t.profession_id = p.id 
                  WHERE 1=1";
        $params = array();

        if (!empty($filters['profession_id'])) {
            $query .= " AND t.profession_id = %d";
            $params[] = intval($filters['profession_id']);
        }
        if (!empty($filters['status'])) {
            $query .= " AND t.status = %s";
            $params[] = sanitize_text_field($filters['status']);
        }

        $query .= " ORDER BY t.id DESC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, $params));
        }
        return $wpdb->get_results($query);
    }

    /**
     * Get a single test
     */
    public static function get($id)
    {
        global $wpdb;
        $table_tests = "{$wpdb->prefix}oee_acceptance_tests";
        $table_professions = "{$wpdb->prefix}oee_professions";

        return $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, p.name_ar as profession_name_ar, p.name_en as profession_name_en 
             FROM $table_tests t 
             LEFT JOIN $table_professions p ON t.profession_id = p.id 
             WHERE t.id = %d",
            intval($id)
        ));
    }

    /**
     * Get a test by public token (must be active and not expired)
     */
    public static function get_by_token($token)
    {
        global $wpdb;
        $table_tests = "{$wpdb->prefix}oee_acceptance_tests";
        $table_professions = "{$wpdb->prefix}oee_professions";
        $now = current_time('mysql');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, p.name_ar as profession_name_ar, p.name_en as profession_name_en 
             FROM $table_tests t 
             LEFT JOIN $table_professions p ON t.profession_id = p.id 
             WHERE t.public_token = %s 
               AND t.status = 'active' 
               AND (t.expires_at IS NULL OR t.expires_at > %s)",
            sanitize_text_field($token),
            $now
        ));
    }

    /**
     * Save a test (insert or update)
     */
    public static function save($data)
    {
        global $wpdb;
        $table = "{$wpdb->prefix}oee_acceptance_tests";
        $id = intval($data['id'] ?? 0);

        $fields = array(
            'profession_id' => intval($data['profession_id'] ?? 0),
            'title' => sanitize_text_field($data['title'] ?? ''),
            'duration_min' => intval($data['duration_min'] ?? 45),
            'num_questions' => intval($data['num_questions'] ?? 40),
            'pass_score_pct' => intval($data['pass_score_pct'] ?? 60),
            'status' => sanitize_text_field($data['status'] ?? 'active'),
            'expires_at' => !empty($data['expires_at']) ? sanitize_text_field($data['expires_at']) : null,
        );

        if ($fields['profession_id'] <= 0) {
            return new WP_Error('empty_profession', olama_exam_translate('Profession is required.'));
        }
        if (empty($fields['title'])) {
            return new WP_Error('empty_title', olama_exam_translate('Title is required.'));
        }

        if ($id > 0) {
            $result = $wpdb->update($table, $fields, array('id' => $id));
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update test.');
            }
            return $id;
        } else {
            $fields['public_token'] = wp_generate_password(32, false);
            $fields['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $fields);
            if ($result === false) {
                return new WP_Error('db_error', 'Failed to insert test.');
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Delete a test
     */
    public static function delete($id)
    {
        global $wpdb;
        $id = intval($id);

        // Check if any results/applicants exist for this test
        $applicant_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}oee_acceptance_applicants WHERE test_id = %d",
            $id
        ));
        if ($applicant_count > 0) {
            return new WP_Error('has_applicants', olama_exam_translate('Cannot delete: this test has completed attempts.'));
        }

        $result = $wpdb->delete("{$wpdb->prefix}oee_acceptance_tests", array('id' => $id));
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to delete test.');
        }
        return true;
    }

    /**
     * Get random questions for a profession
     */
    public static function get_random_questions($profession_id, $num)
    {
        global $wpdb;
        $table_questions = "{$wpdb->prefix}olama_exam_questions";

        $num = intval($num);
        if ($num <= 0) {
            $num = 40;
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_questions 
             WHERE profession_id = %d 
             ORDER BY RAND() LIMIT %d",
            intval($profession_id),
            $num
        ));
    }
}
