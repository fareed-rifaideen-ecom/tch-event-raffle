<?php
/**
 * Plugin Name:       TCH Event Raffle Engine
 * Plugin URI:        https://thecyclehub.com
 * Description:       Multi-Event Raffle Landing Page, Skill Quiz Qualifier, Countdown Timer, VIP Ticket Generator, Team Portal & 5-Engine Live Broadcast Stage for The Cycle Hub.
 * Version:           2.2.0
 * Author:            Fareed Rifaideen - The Cycle Hub
 * Text Domain:       tch-event-raffle
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'TCH_RAFFLE_LOADED' ) ) {
    return;
}
define( 'TCH_RAFFLE_LOADED', true );
define( 'TCH_RAFFLE_VERSION', '2.2.0' );
define( 'TCH_RAFFLE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCH_RAFFLE_URL', plugin_dir_url( __FILE__ ) );
define( 'TCH_RAFFLE_TABLE', 'tch_raffle_entries' );

/**
 * Check if current logged-in user is an Admin, Shop Manager, or Editor
 */
if ( ! function_exists( 'tch_raffle_user_can_operate' ) ) {
    function tch_raffle_user_can_operate() {
        if ( ! function_exists( 'current_user_can' ) ) {
            return false;
        }
        return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_pages' );
    }
}

/**
 * Return capability required for WP Admin menu visibility
 */
if ( ! function_exists( 'tch_raffle_admin_cap' ) ) {
    function tch_raffle_admin_cap() {
        if ( ! function_exists( 'current_user_can' ) ) {
            return 'manage_options';
        }
        if ( current_user_can( 'manage_options' ) ) {
            return 'manage_options';
        }
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return 'manage_woocommerce';
        }
        return 'edit_pages';
    }
}

/**
 * 1. Crash-Proof Database Setup & Auto-Migration (v2.2.0 adds quiz_answer)
 */
if ( ! function_exists( 'tch_raffle_check_db_schema' ) ) {
    function tch_raffle_check_db_schema() {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return;
        }

        $table_name      = $wpdb->prefix . TCH_RAFFLE_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $prev_suppress = $wpdb->suppress_errors( true );

        try {
            $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `campaign_slug` VARCHAR(60) NOT NULL DEFAULT 'sa-day-els-2026',
                `ref_code` VARCHAR(25) NOT NULL,
                `full_name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(150) NOT NULL,
                `phone` VARCHAR(50) NOT NULL,
                `quiz_answer` VARCHAR(150) DEFAULT '',
                `ig_confirmed` TINYINT(1) DEFAULT 1,
                `yt_confirmed` TINYINT(1) DEFAULT 1,
                `status` VARCHAR(20) DEFAULT 'valid',
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_ref` (`ref_code`),
                KEY `campaign_status_idx` (`campaign_slug`, `status`)
            ) $charset_collate;";

            $wpdb->query( $sql );

            $col_slug = $wpdb->get_results( "SHOW COLUMNS FROM `$table_name` LIKE 'campaign_slug'" );
            if ( empty( $col_slug ) ) {
                $wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN `campaign_slug` VARCHAR(60) NOT NULL DEFAULT 'sa-day-els-2026' AFTER `id`" );
            }

            $col_quiz = $wpdb->get_results( "SHOW COLUMNS FROM `$table_name` LIKE 'quiz_answer'" );
            if ( empty( $col_quiz ) ) {
                $wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN `quiz_answer` VARCHAR(150) DEFAULT '' AFTER `phone`" );
            }

            $old_email_idx = $wpdb->get_results( "SHOW INDEX FROM `$table_name` WHERE Key_name = 'unique_email'" );
            if ( ! empty( $old_email_idx ) ) {
                $wpdb->query( "ALTER TABLE `$table_name` DROP INDEX `unique_email`" );
            }
            $old_phone_idx = $wpdb->get_results( "SHOW INDEX FROM `$table_name` WHERE Key_name = 'unique_phone'" );
            if ( ! empty( $old_phone_idx ) ) {
                $wpdb->query( "ALTER TABLE `$table_name` DROP INDEX `unique_phone`" );
            }
        } catch ( Throwable $e ) {
            // Safe catch for PHP 8+
        }

        $wpdb->suppress_errors( $prev_suppress );
    }
}

/**
 * Plugin Activation Hook
 */
if ( ! function_exists( 'tch_raffle_activate' ) ) {
    function tch_raffle_activate() {
        tch_raffle_check_db_schema();

        if ( false === get_option( 'tch_raffle_settings' ) ) {
            update_option( 'tch_raffle_settings', tch_raffle_get_settings() );
        }
        update_option( 'tch_raffle_db_version', TCH_RAFFLE_VERSION );
    }
}
register_activation_hook( __FILE__, 'tch_raffle_activate' );

add_action( 'init', function() {
    if ( get_option( 'tch_raffle_db_version' ) !== TCH_RAFFLE_VERSION ) {
        tch_raffle_check_db_schema();
        update_option( 'tch_raffle_db_version', TCH_RAFFLE_VERSION );
    }
} );

/**
 * 2. Helper to retrieve merged settings safely
 */
if ( ! function_exists( 'tch_raffle_get_settings' ) ) {
    function tch_raffle_get_settings() {
        $saved = get_option( 'tch_raffle_settings', array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, array(
            'campaign_slug'    => 'sa-day-els-2026',
            'event_name'       => 'South African Day at Els Club',
            'event_place'      => 'The Els Club, Dubai',
            'event_date'       => '2026-10-31',
            'open_time'        => '15:00',
            'close_time'       => '21:00',
            'live_draw_date'   => '2026-10-31',
            'live_draw_time'   => '21:15',
            'ticket_prefix'    => 'TCH-',
            'stage_engine'     => 'slot',
            'operator_pin'     => '2026',
            'team_entries_pin' => '1234',
            'quiz_enabled'     => 1,
            'quiz_question'    => 'Guess the Tyre Pressure?',
            'quiz_options'     => "45 PSI\n55 PSI\n65 PSI\n75 PSI\n85 PSI\n95 PSI",
            'quiz_correct'     => '65 PSI',
            'prize_image_url'  => '',
            'headline'         => 'Win a Brand New Bike at South African Day!',
            'subheadline'      => 'Complete the quick steps below before the timer hits zero to receive your instant Raffle Draw Ticket.',
            'ig_url'           => 'https://www.instagram.com/thecyclehub/',
            'ig_label'         => 'Follow our Instagram, take a selfie with the Bike, post it on Instagram and tag @thecyclehub',
            'yt_url'           => 'https://www.youtube.com/@thecyclehub?sub_confirmation=1',
            'yt_label'         => 'Follow and subscribe to our YouTube Channel',
            'sender_email'     => 'marketing@thecyclehub.com',
            'sender_name'      => 'The Cycle Hub',
            'terms_content'    => '<ul><li>Participants must be attending South African Day at The Els Club on 31st October 2026.</li><li>Only one registration is permitted per email address and WhatsApp/Mobile number.</li><li>Only participants who select the correct Tyre Pressure answer and complete both social steps will qualify for the final raffle draw.</li><li>Registration closes strictly at 9:00 PM GST on 31st October 2026.</li></ul>',
        ) );
    }
}

/**
 * Helper to parse quiz options into a clean array
 */
if ( ! function_exists( 'tch_raffle_get_quiz_options_array' ) ) {
    function tch_raffle_get_quiz_options_array( $s = null ) {
        if ( null === $s ) {
            $s = tch_raffle_get_settings();
        }
        $raw   = (string) ( $s['quiz_options'] ?? '' );
        $lines = preg_split( '/\r\n|\r|\n|,/', $raw );
        $clean = array();
        if ( is_array( $lines ) ) {
            foreach ( $lines as $line ) {
                $trimmed = trim( $line );
                if ( '' !== $trimmed ) {
                    $clean[] = $trimmed;
                }
            }
        }
        return $clean;
    }
}

/**
 * Helper to check if a participant's quiz answer is qualified
 */
if ( ! function_exists( 'tch_raffle_is_answer_correct' ) ) {
    function tch_raffle_is_answer_correct( $participant_answer, $s = null ) {
        if ( null === $s ) {
            $s = tch_raffle_get_settings();
        }
        if ( empty( $s['quiz_enabled'] ) ) {
            return true;
        }
        return strcasecmp( trim( (string) $participant_answer ), trim( (string) $s['quiz_correct'] ) ) === 0;
    }
}

/**
 * 3. Helpers for Live Broadcast State Sync
 */
if ( ! function_exists( 'tch_raffle_get_live_state' ) ) {
    function tch_raffle_get_live_state() {
        $s     = tch_raffle_get_settings();
        $state = get_option( 'tch_raffle_live_state', array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        return wp_parse_args( $state, array(
            'status'        => 'idle',
            'engine'        => $s['stage_engine'],
            'spin_id'       => 0,
            'candidate'     => null,
            'updated_at'    => time(),
            'campaign_slug' => $s['campaign_slug'],
        ) );
    }
}

if ( ! function_exists( 'tch_raffle_update_live_state' ) ) {
    function tch_raffle_update_live_state( $new_data ) {
        $current               = tch_raffle_get_live_state();
        $updated               = array_merge( $current, $new_data );
        $updated['updated_at'] = time();
        update_option( 'tch_raffle_live_state', $updated, false );
        return $updated;
    }
}

/**
 * 4. Retrieve Archived Events List
 */
if ( ! function_exists( 'tch_raffle_get_archives' ) ) {
    function tch_raffle_get_archives() {
        $archives = get_option( 'tch_raffle_archives', array() );
        return is_array( $archives ) ? $archives : array();
    }
}

/**
 * 5. Safely Load Admin, Public, and Live Stage Classes
 */
if ( file_exists( TCH_RAFFLE_PATH . 'includes/class-tch-raffle-admin.php' ) ) {
    require_once TCH_RAFFLE_PATH . 'includes/class-tch-raffle-admin.php';
}

if ( file_exists( TCH_RAFFLE_PATH . 'includes/class-tch-raffle-public.php' ) ) {
    require_once TCH_RAFFLE_PATH . 'includes/class-tch-raffle-public.php';
}

if ( file_exists( TCH_RAFFLE_PATH . 'includes/class-tch-raffle-stage.php' ) ) {
    require_once TCH_RAFFLE_PATH . 'includes/class-tch-raffle-stage.php';
}
