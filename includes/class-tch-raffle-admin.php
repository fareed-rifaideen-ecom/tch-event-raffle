<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'TCH_Raffle_Admin' ) ) {

    class TCH_Raffle_Admin {

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'register_menus' ) );
            add_action( 'admin_init', array( $this, 'handle_form_actions' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        }

        /**
         * Available Live Stage Engines
         */
        public function get_engine_options() {
            return array(
                'slot'           => '1. Digital Slot Odometer (Classic Fast Scramble)',
                'drum'           => '2. 3D Vertical Color Drum (Curved Rainbow Barrel)',
                'cyber_wheel'    => '3. Neon Cyber Wheel (Futuristic Blue Radial Wheel)',
                'carnival_wheel' => '4. Carnival Spin Wheel (Game-Show Red & Gold LED Wheel)',
                'lotto_cage'     => '5. Lotto Ball Cage & Chute (Spinning Glass Drum & 5-Ball Drop)',
            );
        }

        /**
         * Register Top-Level Menu & Submenus (Accessible to Admins, Shop Managers & Editors)
         */
        public function register_menus() {
            $cap = tch_raffle_admin_cap();

            add_menu_page(
                'TCH Raffle',
                'TCH Raffle',
                $cap,
                'tch-raffle-settings',
                array( $this, 'render_settings_page' ),
                'dashicons-tickets-alt',
                26
            );

            add_submenu_page(
                'tch-raffle-settings',
                'Event & Page Settings',
                'Settings & Content',
                $cap,
                'tch-raffle-settings',
                array( $this, 'render_settings_page' )
            );

            add_submenu_page(
                'tch-raffle-settings',
                'Raffle Entries',
                'Entries & Archives',
                $cap,
                'tch-raffle-entries',
                array( $this, 'render_entries_page' )
            );

            add_submenu_page(
                'tch-raffle-settings',
                'Live Draw Stage',
                'Live Stage & Broadcast',
                $cap,
                'tch-raffle-stage',
                array( $this, 'render_stage_control_page' )
            );
        }

        /**
         * Load WP Media Uploader on our Settings Page
         */
        public function enqueue_admin_assets( $hook ) {
            if ( strpos( (string) $hook, 'tch-raffle' ) === false ) {
                return;
            }
            wp_enqueue_media();
        }

        /**
         * Handle Settings Save, Event Archiving, Single Entry Delete, and CSV Export
         */
        public function handle_form_actions() {
            if ( ! tch_raffle_user_can_operate() ) {
                return;
            }

            // 1. Save Settings
            if ( isset( $_POST['tch_raffle_save_settings'] ) && check_admin_referer( 'tch_raffle_settings_nonce' ) ) {
                $current = tch_raffle_get_settings();
                $slug    = sanitize_title( wp_unslash( $_POST['campaign_slug'] ?? $current['campaign_slug'] ) );
                if ( empty( $slug ) ) {
                    $slug = 'event-' . gmdate( 'Y-m-d' );
                }

                $engine = sanitize_key( wp_unslash( $_POST['stage_engine'] ?? 'slot' ) );
                $pin    = sanitize_text_field( wp_unslash( $_POST['operator_pin'] ?? '2026' ) );

                $settings = array(
                    'campaign_slug'   => $slug,
                    'event_name'      => sanitize_text_field( wp_unslash( $_POST['event_name'] ?? '' ) ),
                    'event_place'     => sanitize_text_field( wp_unslash( $_POST['event_place'] ?? '' ) ),
                    'event_date'      => sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) ),
                    'open_time'       => sanitize_text_field( wp_unslash( $_POST['open_time'] ?? '' ) ),
                    'close_time'      => sanitize_text_field( wp_unslash( $_POST['close_time'] ?? '' ) ),
                    'live_draw_time'  => sanitize_text_field( wp_unslash( $_POST['live_draw_time'] ?? '' ) ),
                    'ticket_prefix'   => sanitize_text_field( wp_unslash( $_POST['ticket_prefix'] ?? 'TCH-' ) ),
                    'stage_engine'    => $engine,
                    'operator_pin'    => $pin,
                    'prize_image_url' => esc_url_raw( wp_unslash( $_POST['prize_image_url'] ?? '' ) ),
                    'headline'        => sanitize_text_field( wp_unslash( $_POST['headline'] ?? '' ) ),
                    'subheadline'     => sanitize_textarea_field( wp_unslash( $_POST['subheadline'] ?? '' ) ),
                    'ig_url'          => esc_url_raw( wp_unslash( $_POST['ig_url'] ?? '' ) ),
                    'ig_label'        => sanitize_text_field( wp_unslash( $_POST['ig_label'] ?? '' ) ),
                    'yt_url'          => esc_url_raw( wp_unslash( $_POST['yt_url'] ?? '' ) ),
                    'yt_label'        => sanitize_text_field( wp_unslash( $_POST['yt_label'] ?? '' ) ),
                    'sender_email'    => sanitize_email( wp_unslash( $_POST['sender_email'] ?? 'marketing@thecyclehub.com' ) ),
                    'sender_name'     => sanitize_text_field( wp_unslash( $_POST['sender_name'] ?? 'The Cycle Hub' ) ),
                    'terms_content'   => wp_kses_post( wp_unslash( $_POST['terms_content'] ?? '' ) ),
                );

                update_option( 'tch_raffle_settings', $settings );
                tch_raffle_update_live_state( array(
                    'engine'        => $engine,
                    'campaign_slug' => $slug,
                ) );

                wp_safe_redirect( add_query_arg( 'updated', 'true', admin_url( 'admin.php?page=tch-raffle-settings' ) ) );
                exit;
            }

            // 2. Archive Current Event & Start Fresh New Raffle
            if ( isset( $_POST['tch_archive_and_new_event'] ) && check_admin_referer( 'tch_archive_event_nonce' ) ) {
                $s        = tch_raffle_get_settings();
                $archives = tch_raffle_get_archives();

                $archives[ $s['campaign_slug'] ] = array(
                    'campaign_slug' => $s['campaign_slug'],
                    'event_name'    => $s['event_name'],
                    'event_place'   => $s['event_place'],
                    'event_date'    => $s['event_date'],
                    'archived_at'   => current_time( 'mysql' ),
                );
                update_option( 'tch_raffle_archives', $archives );

                $new_name   = sanitize_text_field( wp_unslash( $_POST['new_event_name'] ?? 'New TCH Raffle Event' ) );
                $new_place  = sanitize_text_field( wp_unslash( $_POST['new_event_place'] ?? 'Dubai' ) );
                $new_date   = sanitize_text_field( wp_unslash( $_POST['new_event_date'] ?? gmdate( 'Y-m-d' ) ) );
                $new_prefix = strtoupper( sanitize_text_field( wp_unslash( $_POST['new_ticket_prefix'] ?? 'TCH-' ) ) );
                $new_slug   = sanitize_title( $new_name . '-' . $new_date );

                $s['campaign_slug'] = $new_slug;
                $s['event_name']    = $new_name;
                $s['event_place']   = $new_place;
                $s['event_date']    = $new_date;
                $s['ticket_prefix'] = $new_prefix;

                update_option( 'tch_raffle_settings', $s );
                tch_raffle_update_live_state( array(
                    'status'        => 'idle',
                    'spin_id'       => 0,
                    'candidate'     => null,
                    'campaign_slug' => $new_slug,
                ) );

                wp_safe_redirect( add_query_arg( 'archived', 'true', admin_url( 'admin.php?page=tch-raffle-settings' ) ) );
                exit;
            }

            // 3. Export CSV (Filtered by Campaign Slug or All)
            if ( isset( $_GET['action'] ) && 'tch_export_csv' === $_GET['action'] && check_admin_referer( 'tch_export_csv_nonce' ) ) {
                global $wpdb;
                $table_name  = $wpdb->prefix . TCH_RAFFLE_TABLE;
                $filter_slug = isset( $_GET['campaign_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_filter'] ) ) : '';

                if ( ! empty( $filter_slug ) && 'ALL' !== $filter_slug ) {
                    $entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$table_name` WHERE campaign_slug = %s ORDER BY created_at DESC", $filter_slug ), ARRAY_A );
                    $fname   = 'tch-raffle-' . $filter_slug . '-' . gmdate( 'Y-m-d' ) . '.csv';
                } else {
                    $entries = $wpdb->get_results( "SELECT * FROM `$table_name` ORDER BY created_at DESC", ARRAY_A );
                    $fname   = 'tch-raffle-all-events-' . gmdate( 'Y-m-d' ) . '.csv';
                }

                header( 'Content-Type: text/csv; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename=' . $fname );
                $output = fopen( 'php://output', 'w' );

                fputcsv( $output, array( 'ID', 'Event Campaign Slug', 'Ticket Reference', 'Full Name', 'Email', 'Mobile / WhatsApp', 'IG Confirmed', 'YT Confirmed', 'Status', 'IP Address', 'Registered At (GST)' ) );

                if ( ! empty( $entries ) ) {
                    foreach ( $entries as $row ) {
                        fputcsv( $output, $row );
                    }
                }
                fclose( $output );
                exit;
            }

            // 4. Delete Single Entry
            if ( isset( $_GET['action'] ) && 'delete_entry' === $_GET['action'] && ! empty( $_GET['entry_id'] ) && check_admin_referer( 'tch_delete_entry_nonce' ) ) {
                global $wpdb;
                $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
                $wpdb->delete( $table_name, array( 'id' => absint( $_GET['entry_id'] ) ), array( '%d' ) );
                wp_safe_redirect( add_query_arg( 'deleted', 'true', admin_url( 'admin.php?page=tch-raffle-entries' ) ) );
                exit;
            }
        }

        /**
         * Render Settings & Landing Page Content Editor
         */
        public function render_settings_page() {
            $s       = tch_raffle_get_settings();
            $engines = $this->get_engine_options();
            ?>
            <div class="wrap">
                <h1 style="display:flex;align-items:center;gap:10px;">
                    <span class="dashicons dashicons-tickets-alt" style="font-size:28px;width:28px;height:28px;"></span>
                    TCH Event Raffle v2.0 — Settings, Stage Engines & Multi-Event Manager
                </h1>

                <?php if ( isset( $_GET['updated'] ) ) : ?>
                    <div class="notice notice-success is-dismissible"><p><strong>Settings saved!</strong> Your Landing Page and Live Stage have been updated.</p></div>
                <?php endif; ?>

                <?php if ( isset( $_GET['archived'] ) ) : ?>
                    <div class="notice notice-success is-dismissible"><p><strong>Previous event archived!</strong> Your new event campaign is now live with a fresh entry pool.</p></div>
                <?php endif; ?>

                <div style="background:#fff;border-left:4px solid #00a32a;padding:12px 16px;margin:15px 0;box-shadow:0 1px 2px rgba(0,0,0,0.06);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;">
                    <div>
                        <strong>Landing Page Shortcode:</strong>
                        <code style="font-size:14px;padding:4px 8px;background:#f0f0f1;margin-left:8px;user-select:all;">[tch_raffle_landing]</code>
                        <span style="color:#646970;margin-left:8px;">Active Campaign ID: <code><?php echo esc_html( $s['campaign_slug'] ); ?></code></span>
                    </div>
                    <div>
                        <a href="<?php echo esc_url( home_url( '/?tch_live_stage=1' ) ); ?>" target="_blank" class="button button-primary">
                            🎰 Open Live Stage (Operator / Spectator) ↗
                        </a>
                    </div>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field( 'tch_raffle_settings_nonce' ); ?>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:20px;margin-top:20px;">
                        
                        <!-- CARD 1: Event Schedule, Stage Engine & Team PIN -->
                        <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:6px;">
                            <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">1. Event Schedule, Stage Engine & Team Access</h2>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th><label>Active Campaign Slug</label></th>
                                    <td>
                                        <input type="text" name="campaign_slug" value="<?php echo esc_attr( $s['campaign_slug'] ); ?>" class="regular-text" required>
                                        <p class="description">Unique ID grouping entries for this event (e.g. <code>sa-day-els-2026</code>).</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Event Name</label></th>
                                    <td><input type="text" name="event_name" value="<?php echo esc_attr( $s['event_name'] ); ?>" class="regular-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>Venue / Place</label></th>
                                    <td><input type="text" name="event_place" value="<?php echo esc_attr( $s['event_place'] ); ?>" class="regular-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>Event Date</label></th>
                                    <td><input type="date" name="event_date" value="<?php echo esc_attr( $s['event_date'] ); ?>" required></td>
                                </tr>
                                <tr>
                                    <th><label>Registration Opens</label></th>
                                    <td><input type="time" name="open_time" value="<?php echo esc_attr( $s['open_time'] ); ?>" required></td>
                                </tr>
                                <tr>
                                    <th><label>Registration Closes</label></th>
                                    <td>
                                        <input type="time" name="close_time" value="<?php echo esc_attr( $s['close_time'] ); ?>" required>
                                        <p class="description">Countdown target. Form auto-locks after this time.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Live Stage Draw Time</label></th>
                                    <td><input type="time" name="live_draw_time" value="<?php echo esc_attr( $s['live_draw_time'] ); ?>"></td>
                                </tr>
                                <tr>
                                    <th><label>Ticket ID Prefix</label></th>
                                    <td><input type="text" name="ticket_prefix" value="<?php echo esc_attr( $s['ticket_prefix'] ); ?>" style="width:120px;"></td>
                                </tr>
                                <tr>
                                    <th><label>Default Live Stage Engine</label></th>
                                    <td>
                                        <select name="stage_engine" style="max-width:100%;">
                                            <?php foreach ( $engines as $key => $label ) : ?>
                                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['stage_engine'], $key ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">You can also switch engines live on the Stage screen.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Team Operator PIN</label></th>
                                    <td>
                                        <input type="text" name="operator_pin" value="<?php echo esc_attr( $s['operator_pin'] ); ?>" style="width:120px;font-weight:700;letter-spacing:2px;" required>
                                        <p class="description">Share this PIN with your booth staff so they can unlock Operator Controls on the Live Stage from any device.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- CARD 2: Landing Page Visuals & Headlines -->
                        <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:6px;">
                            <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">2. Prize Image & Landing Page Copy</h2>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th><label>Prize Bike Image</label></th>
                                    <td>
                                        <div style="margin-bottom:10px;">
                                            <img id="tch-prize-preview" src="<?php echo esc_url( $s['prize_image_url'] ); ?>" style="max-width:240px;max-height:150px;border-radius:6px;border:1px solid #ddd;display:<?php echo $s['prize_image_url'] ? 'block' : 'none'; ?>;" />
                                        </div>
                                        <input type="text" id="tch_prize_image_url" name="prize_image_url" value="<?php echo esc_attr( $s['prize_image_url'] ); ?>" class="regular-text" placeholder="https://...">
                                        <button type="button" class="button button-secondary" id="tch-upload-prize-btn">Select / Upload Image</button>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Main Headline</label></th>
                                    <td><input type="text" name="headline" value="<?php echo esc_attr( $s['headline'] ); ?>" class="large-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>Raffle Details / Subheadline</label></th>
                                    <td><textarea name="subheadline" rows="3" class="large-text"><?php echo esc_textarea( $s['subheadline'] ); ?></textarea></td>
                                </tr>
                                <tr>
                                    <th><label>Sender Email</label></th>
                                    <td><input type="email" name="sender_email" value="<?php echo esc_attr( $s['sender_email'] ); ?>" class="regular-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>Sender Name</label></th>
                                    <td><input type="text" name="sender_name" value="<?php echo esc_attr( $s['sender_name'] ); ?>" class="regular-text" required></td>
                                </tr>
                            </table>
                        </div>

                        <!-- CARD 3: Social Actions & Checkboxes -->
                        <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:6px;">
                            <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">3. Social Links & Checkboxes</h2>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th><label>Instagram Profile URL</label></th>
                                    <td><input type="url" name="ig_url" value="<?php echo esc_attr( $s['ig_url'] ); ?>" class="large-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>Checkbox 1 Label (IG)</label></th>
                                    <td><input type="text" name="ig_label" value="<?php echo esc_attr( $s['ig_label'] ); ?>" class="large-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>YouTube Channel URL</label></th>
                                    <td><input type="url" name="yt_url" value="<?php echo esc_attr( $s['yt_url'] ); ?>" class="large-text" required></td>
                                </tr>
                                <tr>
                                    <th><label>Checkbox 2 Label (YT)</label></th>
                                    <td><input type="text" name="yt_label" value="<?php echo esc_attr( $s['yt_label'] ); ?>" class="large-text" required></td>
                                </tr>
                            </table>
                        </div>

                        <!-- CARD 4: Raffle Terms & Conditions -->
                        <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:6px;">
                            <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">4. Raffle Terms & Conditions (Popup Modal)</h2>
                            <?php
                            wp_editor(
                                $s['terms_content'],
                                'terms_content',
                                array(
                                    'textarea_name' => 'terms_content',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny'         => true,
                                )
                            );
                            ?>
                        </div>

                    </div>

                    <p class="submit">
                        <button type="submit" name="tch_raffle_save_settings" class="button button-primary button-hero">Save All Event & Landing Page Settings</button>
                    </p>
                </form>

                <!-- CARD 5: Multi-Event Archiver (How to start a new event after Oct 31st) -->
                <div style="background:#fff8e5;padding:22px;border:1px solid #f0c33c;border-radius:8px;margin-top:20px;max-width:980px;">
                    <h2 style="margin-top:0;">📦 Done with the Current Event? Archive & Start a New Event Raffle</h2>
                    <p style="color:#3c434a;">
                        When <strong><?php echo esc_html( $s['event_name'] ); ?></strong> is finished, use this tool to safely archive all its entries and winners under <code><?php echo esc_html( $s['campaign_slug'] ); ?></code> and immediately reset the Landing Page and Live Stage for your next event.
                    </p>
                    <form method="post" onsubmit="return confirm('Archive the current event and switch the Landing Page to a new event campaign? All current entries remain safely stored in Entries & Archives.');" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;align-items:end;margin-top:14px;">
                        <?php wp_nonce_field( 'tch_archive_event_nonce' ); ?>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:4px;">New Event Name</label>
                            <input type="text" name="new_event_name" placeholder="e.g. Dubai Ride 2026" class="regular-text" style="width:100%;" required>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:4px;">New Venue / Place</label>
                            <input type="text" name="new_event_place" placeholder="e.g. Sheikh Zayed Rd" class="regular-text" style="width:100%;" required>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:4px;">New Event Date</label>
                            <input type="date" name="new_event_date" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+7 days' ) ) ); ?>" style="width:100%;" required>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;margin-bottom:4px;">New Ticket Prefix</label>
                            <input type="text" name="new_ticket_prefix" value="DR26-" style="width:100%;" required>
                        </div>
                        <div>
                            <button type="submit" name="tch_archive_and_new_event" class="button button-secondary" style="height:36px;width:100%;font-weight:700;">
                                📦 Archive & Start New Event
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($){
                var mediaUploader;
                $('#tch-upload-prize-btn').on('click', function(e) {
                    e.preventDefault();
                    if (mediaUploader) {
                        mediaUploader.open();
                        return;
                    }
                    mediaUploader = wp.media({
                        title: 'Select Prize Bike Image',
                        button: { text: 'Use This Image' },
                        multiple: false
                    });
                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();
                        $('#tch_prize_image_url').val(attachment.url);
                        $('#tch-prize-preview').attr('src', attachment.url).show();
                    });
                    mediaUploader.open();
                });
            });
            </script>
            <?php
        }

        /**
         * Render Registered Participants Table, Campaign Filter & CSV Export
         */
        public function render_entries_page() {
            global $wpdb;
            $s               = tch_raffle_get_settings();
            $table_name      = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
            $campaign_filter = isset( $_GET['campaign_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign_filter'] ) ) : $s['campaign_slug'];

            // Distinct campaigns in DB
            $db_campaigns = $wpdb->get_col( "SELECT DISTINCT campaign_slug FROM `$table_name` ORDER BY id DESC" );
            if ( ! in_array( $s['campaign_slug'], $db_campaigns, true ) ) {
                array_unshift( $db_campaigns, $s['campaign_slug'] );
            }

            $where_clauses = array();
            $prepare_args  = array();

            if ( 'ALL' !== $campaign_filter && ! empty( $campaign_filter ) ) {
                $where_clauses[] = 'campaign_slug = %s';
                $prepare_args[]  = $campaign_filter;
            }

            if ( ! empty( $search ) ) {
                $like            = '%' . $wpdb->esc_like( $search ) . '%';
                $where_clauses[] = '(ref_code LIKE %s OR full_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
                array_push( $prepare_args, $like, $like, $like, $like );
            }

            $where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';
            $query_sql = "SELECT * FROM `$table_name` $where_sql ORDER BY created_at DESC";

            $entries = ! empty( $prepare_args )
                ? $wpdb->get_results( $wpdb->prepare( $query_sql, $prepare_args ) )
                : $wpdb->get_results( $query_sql );

            $export_url = wp_nonce_url(
                add_query_arg( array(
                    'page'            => 'tch-raffle-entries',
                    'action'          => 'tch_export_csv',
                    'campaign_filter' => $campaign_filter,
                ), admin_url( 'admin.php' ) ),
                'tch_export_csv_nonce'
            );
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">Raffle Registrations & Event Archives (<?php echo count( $entries ); ?> Shown)</h1>
                <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">📥 Export Current View to CSV</a>
                <hr class="wp-header-end">

                <?php if ( isset( $_GET['deleted'] ) ) : ?>
                    <div class="notice notice-warning is-dismissible"><p>Entry deleted.</p></div>
                <?php endif; ?>

                <form method="get" style="margin:15px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <input type="hidden" name="page" value="tch-raffle-entries">
                    
                    <label for="campaign_filter" style="font-weight:600;">Event Campaign:</label>
                    <select name="campaign_filter" id="campaign_filter" onchange="this.form.submit()">
                        <?php foreach ( $db_campaigns as $c_slug ) : ?>
                            <option value="<?php echo esc_attr( $c_slug ); ?>" <?php selected( $campaign_filter, $c_slug ); ?>>
                                <?php echo esc_html( $c_slug === $s['campaign_slug'] ? $c_slug . ' (ACTIVE EVENT)' : $c_slug ); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="ALL" <?php selected( $campaign_filter, 'ALL' ); ?>>— Show All Events Combined —</option>
                    </select>

                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search Ticket ID, Name, Email, Phone..." style="min-width:260px;">
                    <button type="submit" class="button">Filter / Search</button>
                    <?php if ( ! empty( $search ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tch-raffle-entries&campaign_filter=' . rawurlencode( $campaign_filter ) ) ); ?>" class="button">Reset Search</a>
                    <?php endif; ?>
                </form>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:130px;">Ticket Ref ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Mobile / WhatsApp</th>
                            <th style="width:140px;">Event Campaign</th>
                            <th style="width:110px;">Status</th>
                            <th style="width:160px;">Registered At</th>
                            <th style="width:80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $entries ) ) : ?>
                            <tr><td colspan="8">No raffle entries found for this filter.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $entries as $row ) : 
                                $del_url = wp_nonce_url( admin_url( 'admin.php?page=tch-raffle-entries&action=delete_entry&entry_id=' . $row->id ), 'tch_delete_entry_nonce' );
                                $badge_bg = 'winner' === $row->status ? '#16a34a' : ( 'disqualified' === $row->status ? '#dc2626' : '#475569' );
                            ?>
                                <tr>
                                    <td><strong style="background:#1d2327;color:#facc15;padding:3px 8px;border-radius:4px;font-family:monospace;"><?php echo esc_html( $row->ref_code ); ?></strong></td>
                                    <td><strong><?php echo esc_html( $row->full_name ); ?></strong></td>
                                    <td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
                                    <td><a href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $row->phone ) ); ?>" target="_blank"><?php echo esc_html( $row->phone ); ?></a></td>
                                    <td><code><?php echo esc_html( $row->campaign_slug ); ?></code></td>
                                    <td><span style="background:<?php echo esc_attr( $badge_bg ); ?>;color:#fff;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo esc_html( strtoupper( $row->status ) ); ?></span></td>
                                    <td><?php echo esc_html( $row->created_at ); ?></td>
                                    <td><a href="<?php echo esc_url( $del_url ); ?>" style="color:#d63638;" onclick="return confirm('Delete this participant?');">Delete</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        /**
         * Render Live Stage Control Center & Operator Access Instructions
         */
        public function render_stage_control_page() {
            global $wpdb;
            $s          = tch_raffle_get_settings();
            $engines    = $this->get_engine_options();
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $c_slug     = $s['campaign_slug'];

            // Handle Reset Statuses Action for Active Campaign
            if ( isset( $_POST['tch_reset_draw_statuses'] ) && check_admin_referer( 'tch_reset_draw_nonce' ) ) {
                $wpdb->update(
                    $table_name,
                    array( 'status' => 'valid' ),
                    array( 'campaign_slug' => $c_slug ),
                    array( '%s' ),
                    array( '%s' )
                );
                tch_raffle_update_live_state( array(
                    'status'    => 'idle',
                    'spin_id'   => 0,
                    'candidate' => null,
                ) );
                echo '<div class="notice notice-success is-dismissible"><p><strong>All entries for ' . esc_html( $s['event_name'] ) . ' have been reset to Eligible (valid) status!</strong></p></div>';
            }

            $valid_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid'", $c_slug ) );
            $winner_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner'", $c_slug ) );
            $dq_count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table_name` WHERE campaign_slug = %s AND status = 'disqualified'", $c_slug ) );

            $public_stage_url   = home_url( '/?tch_live_stage=1' );
            $operator_stage_url = home_url( '/?tch_live_stage=1&operator=1' );
            ?>
            <div class="wrap">
                <h1 style="display:flex;align-items:center;gap:10px;">
                    <span class="dashicons dashicons-megaphone" style="font-size:28px;width:28px;height:28px;"></span>
                    TCH Raffle — Multi-Engine Live Stage & Broadcast Control Center
                </h1>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(340px, 1fr));gap:20px;margin-top:20px;max-width:1120px;">
                    
                    <!-- Card 1: Operator Launch Card -->
                    <div style="background:#0f172a;color:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
                        <span style="background:rgba(250,204,21,0.2);color:#facc15;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;">TEAM OPERATOR MODE</span>
                        <h2 style="color:#fff;font-size:22px;margin:12px 0 8px;">Launch Operator Stage</h2>
                        <p style="color:#94a3b8;line-height:1.5;margin-bottom:14px;">
                            Active Engine: <strong style="color:#facc15;"><?php echo esc_html( $engines[ $s['stage_engine'] ] ?? 'Slot Odometer' ); ?></strong><br>
                            Team Operator PIN: <code style="background:#1e293b;color:#86efac;padding:2px 8px;border-radius:4px;font-weight:800;"><?php echo esc_html( $s['operator_pin'] ); ?></code>
                        </p>
                        <a href="<?php echo esc_url( $operator_stage_url ); ?>" target="_blank" class="button button-primary button-hero" style="background:#facc15;border-color:#eab308;color:#0f172a;font-weight:800;width:100%;text-align:center;margin-bottom:12px;">
                            🚀 Launch Operator Stage ↗
                        </a>
                        <p style="font-size:12px;color:#cbd5e1;margin:0;">
                            💡 <strong>For Team Members:</strong> Share <code><?php echo esc_url( $public_stage_url ); ?></code> with your staff. They can tap <strong>"🔑 Operator Login"</strong> in the top corner and enter PIN <strong><?php echo esc_html( $s['operator_pin'] ); ?></strong> to run the draw!
                        </p>
                    </div>

                    <!-- Card 2: Public Spectator Broadcast & Pool Status -->
                    <div style="background:#fff;padding:28px;border:1px solid #ccd0d4;border-radius:12px;">
                        <h2 style="margin-top:0;">Active Campaign Status (<code><?php echo esc_html( $c_slug ); ?></code>)</h2>
                        <ul style="font-size:15px;line-height:2;margin:12px 0 20px;">
                            <li>🟢 <strong>Eligible Tickets in Pool:</strong> <?php echo esc_html( $valid_count ); ?></li>
                            <li>🏆 <strong>Confirmed Winners:</strong> <?php echo esc_html( $winner_count ); ?></li>
                            <li>❌ <strong>Disqualified Tickets:</strong> <?php echo esc_html( $dq_count ); ?></li>
                        </ul>

                        <div style="background:#f0f6fc;border:1px solid #c5d9ed;padding:12px;border-radius:8px;margin-bottom:18px;">
                            <strong>📡 Public Live Spectator Link:</strong><br>
                            <input type="text" readonly value="<?php echo esc_url( $public_stage_url ); ?>" style="width:100%;margin-top:6px;background:#fff;" onclick="this.select();">
                            <small style="color:#50575e;display:block;margin-top:4px;">Participants opening this URL see the live draw animate in real time on their phones without operator buttons.</small>
                        </div>

                        <form method="post" onsubmit="return confirm('Reset all Winners and Disqualified entries for this event back to Eligible?');">
                            <?php wp_nonce_field( 'tch_reset_draw_nonce' ); ?>
                            <button type="submit" name="tch_reset_draw_statuses" class="button button-secondary">
                                🔄 Reset Event Tickets to Eligible (Practice Reset)
                            </button>
                        </form>
                    </div>

                </div>
            </div>
            <?php
        }
    }

    new TCH_Raffle_Admin();
}
