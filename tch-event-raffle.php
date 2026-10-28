<?php
/**
 * Plugin Name:       TCH Event Raffle Engine
 * Plugin URI:        https://thecyclehub.com
 * Description:       Optimized Event Raffle Landing Page, Countdown Timer, VIP Ticket Generator & Live Draw Engine for The Cycle Hub.
 * Version:           1.0.0
 * Author:            Fareed Rifaideen - The Cycle Hub
 * Text Domain:       tch-event-raffle
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TCH_RAFFLE_VERSION', '1.0.0' );
define( 'TCH_RAFFLE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCH_RAFFLE_URL', plugin_dir_url( __FILE__ ) );
define( 'TCH_RAFFLE_TABLE', 'tch_raffle_entries' );

/**
 * 1. Plugin Activation: Create Custom SQL Table & Default Options
 */
function tch_raffle_activate() {
    global $wpdb;
    $table_name      = $wpdb->prefix . TCH_RAFFLE_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        ref_code VARCHAR(25) NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        ig_confirmed TINYINT(1) DEFAULT 1,
        yt_confirmed TINYINT(1) DEFAULT 1,
        status VARCHAR(20) DEFAULT 'valid',
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_ref (ref_code),
        UNIQUE KEY unique_email (email),
        UNIQUE KEY unique_phone (phone),
        KEY status_idx (status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Pre-populate default settings for South African Day at Els Club
    if ( false === get_option( 'tch_raffle_settings' ) ) {
        $defaults = array(
            'event_name'      => 'South African Day at Els Club',
            'event_place'     => 'The Els Club, Dubai',
            'event_date'      => '2026-10-31',
            'open_time'       => '15:00',
            'close_time'      => '21:00',
            'live_draw_time'  => '21:15',
            'ticket_prefix'   => 'TCH-',
            'prize_image_url' => '',
            'headline'        => 'Win a Brand New Bike at South African Day!',
            'subheadline'     => 'Complete the quick steps below before the timer hits zero to receive your instant Raffle Draw Ticket.',
            'ig_url'          => 'https://www.instagram.com/thecyclehub/',
            'ig_label'        => 'Follow our Instagram, take a selfie with the Bike, post it on Instagram and tag @thecyclehub',
            'yt_url'          => 'https://www.youtube.com/@thecyclehub?sub_confirmation=1',
            'yt_label'        => 'Follow and subscribe to our YouTube Channel',
            'sender_email'    => 'marketing@thecyclehub.com',
            'sender_name'     => 'The Cycle Hub',
            'terms_content'   => "<ul><li>Participants must be attending South African Day at The Els Club on 31st October 2026.</li><li>Only one registration is permitted per email address and WhatsApp/Mobile number.</li><li>Participants must follow @thecyclehub on Instagram, post a selfie with the giveaway bike tagging @thecyclehub, and subscribe to The Cycle Hub YouTube channel.</li><li>Social actions will be verified during the live draw.</li><li>Registration closes strictly at 9:00 PM GST on 31st October 2026.</li></ul>",
        );
        update_option( 'tch_raffle_settings', $defaults );
    }
}
register_activation_hook( __FILE__, 'tch_raffle_activate' );

/**
 * 2. Helper to retrieve merged settings safely
 */
function tch_raffle_get_settings() {
    $saved = get_option( 'tch_raffle_settings', array() );
    return wp_parse_args( $saved, array(
        'event_name'      => 'South African Day at Els Club',
        'event_place'     => 'The Els Club, Dubai',
        'event_date'      => '2026-10-31',
        'open_time'       => '15:00',
        'close_time'      => '21:00',
        'live_draw_time'  => '21:15',
        'ticket_prefix'   => 'TCH-',
        'prize_image_url' => '',
        'headline'        => 'Win a Brand New Bike at South African Day!',
        'subheadline'     => 'Complete the quick steps below before the timer hits zero to receive your instant Raffle Draw Ticket.',
        'ig_url'          => 'https://www.instagram.com/thecyclehub/',
        'ig_label'        => 'Follow our Instagram, take a selfie with the Bike, post it on Instagram and tag @thecyclehub',
        'yt_url'          => 'https://www.youtube.com/@thecyclehub?sub_confirmation=1',
        'yt_label'        => 'Follow and subscribe to our YouTube Channel',
        'sender_email'    => 'marketing@thecyclehub.com',
        'sender_name'     => 'The Cycle Hub',
        'terms_content'   => '',
    ) );
}

/**
 * 3. Load Admin, Public, and Live Stage Classes
 */
require_once TCH_RAFFLE_PATH . 'includes/class-tch-raffle-admin.php';

if ( file_exists( TCH_RAFFLE_PATH . 'includes/class-tch-raffle-public.php' ) ) {
    require_once TCH_RAFFLE_PATH . 'includes/class-tch-raffle-public.php';
}

if ( file_exists( TCH_RAFFLE_PATH . 'includes/class-tch-raffle-stage.php' ) ) {
    require_once TCH_RAFFLE_PATH . 'includes/class-tch-raffle-stage.php';
}
