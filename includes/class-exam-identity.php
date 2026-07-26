<?php
/**
 * Identity bridge between Olama Users, Olama Core, and Exam Engine.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Identity
{
    /**
     * Return the Olama Users identity for a WordPress user.
     */
    public static function get_user_identity($user_id = 0)
    {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if (!$user_id) {
            return null;
        }

        if (function_exists('olama_users_get_identity')) {
            $identity = olama_users_get_identity($user_id);
            if (is_array($identity) && !empty($identity['identity_type'])) {
                return $identity;
            }
        }

        $type = sanitize_key((string) get_user_meta($user_id, 'olama_identity_type', true));
        $identifier = sanitize_text_field((string) get_user_meta($user_id, 'olama_oracle_identifier', true));
        if ($type && $identifier !== '') {
            return array(
                'wp_user_id' => $user_id,
                'identity_type' => $type,
                'oracle_identifier' => $identifier,
                'account_status' => (string) get_user_meta($user_id, 'olama_account_status', true),
            );
        }

        return null;
    }

    /**
     * Resolve the canonical Core family UID for the current principal.
     *
     * New Olama Users accounts are resolved through their identity record.
     * Legacy installations that used family_uid or the Oracle family ID as the
     * WordPress login remain supported.
     */
    public static function get_family_uid($user_id = 0)
    {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            return '';
        }

        $identity = self::get_user_identity($user_id);
        if ($identity && ($identity['identity_type'] ?? '') === 'family') {
            $family_uid = self::family_uid_from_oracle_id($identity['oracle_identifier'] ?? '');
            if ($family_uid !== '') {
                return $family_uid;
            }
        }

        if ($identity && ($identity['identity_type'] ?? '') === 'student') {
            global $wpdb;
            $identifier = sanitize_text_field((string) ($identity['oracle_identifier'] ?? ''));
            $family_uid = $wpdb->get_var($wpdb->prepare(
                "SELECT family_uid FROM {$wpdb->prefix}olama_core_students
                 WHERE student_uid = %s OR student_uid = %s
                 LIMIT 1",
                $identifier,
                (string) $user->user_login
            ));
            if ($family_uid) {
                return (string) $family_uid;
            }
        }

        $login = sanitize_text_field((string) $user->user_login);
        if ($login === '') {
            return '';
        }

        $family_uid = self::family_uid_from_oracle_id($login);
        if ($family_uid !== '') {
            return $family_uid;
        }

        global $wpdb;
        $family_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT family_uid FROM {$wpdb->prefix}olama_core_families WHERE family_uid = %s LIMIT 1",
            $login
        ));

        return $family_uid ? (string) $family_uid : $login;
    }

    /**
     * Resolve a future/legacy student login to a canonical student UID.
     */
    public static function get_student_uid($user_id = 0)
    {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            return '';
        }

        $identity = self::get_user_identity($user_id);
        if ($identity && ($identity['identity_type'] ?? '') === 'student') {
            $identifier = sanitize_text_field((string) ($identity['oracle_identifier'] ?? ''));
            if ($identifier !== '') {
                global $wpdb;
                $student_uid = $wpdb->get_var($wpdb->prepare(
                    "SELECT student_uid FROM {$wpdb->prefix}olama_core_students WHERE student_uid = %s LIMIT 1",
                    $identifier
                ));
                if ($student_uid) {
                    return (string) $student_uid;
                }
            }
        }

        global $wpdb;
        $login = sanitize_text_field((string) $user->user_login);
        $student_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT student_uid FROM {$wpdb->prefix}olama_core_students WHERE student_uid = %s LIMIT 1",
            $login
        ));

        return $student_uid ? (string) $student_uid : '';
    }

    /**
     * Determine whether a student belongs to the current Olama principal.
     */
    public static function can_access_student($student_uid, $user_id = 0)
    {
        $student_uid = sanitize_text_field((string) $student_uid);
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if ($student_uid === '' || !$user_id) {
            return false;
        }

        $own_student_uid = self::get_student_uid($user_id);
        if ($own_student_uid !== '') {
            return hash_equals($own_student_uid, $student_uid);
        }

        $family_uid = self::get_family_uid($user_id);
        if ($family_uid === '') {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_core_students
             WHERE student_uid = %s AND family_uid = %s
             LIMIT 1",
            $student_uid,
            $family_uid
        ));
    }

    private static function family_uid_from_oracle_id($oracle_family_id)
    {
        $oracle_family_id = sanitize_text_field((string) $oracle_family_id);
        if ($oracle_family_id === '') {
            return '';
        }

        if (function_exists('olama_core')) {
            $family = olama_core()->families()->get_by_oracle_id($oracle_family_id);
            if (is_array($family) && !empty($family['family_uid'])) {
                return (string) $family['family_uid'];
            }
        }

        global $wpdb;
        $family_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT family_uid FROM {$wpdb->prefix}olama_core_families WHERE oracle_family_id = %s LIMIT 1",
            $oracle_family_id
        ));

        return $family_uid ? (string) $family_uid : '';
    }
}
