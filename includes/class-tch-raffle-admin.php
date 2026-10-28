<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TCH_Raffle_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_init', array( $this, 'handle_form_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Register Top-Level Menu & Submenus
     */
    public function register_menus() {
        add_menu_page(
            'TCH Raffle',
            'TCH Raffle',
            'manage_options',
            'tch-raffle-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-tickets-alt',
            26
        );

        add_submenu_page(
            'tch-raffle-settings',
            'Event & Page Settings',
            'Settings & Content',
            'manage_options',
            'tch-raffle-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'tch-raffle-settings',
            'Raffle Entries',
            'Entries & Export',
            'manage_options',
            'tch-raffle-entries',
            array( $this, 'render_entries_page' )
        );

        add_submenu_page(
            'tch-raffle-settings',
            'Live Draw Stage',
            'Live Stage (Phase 2)',
            'manage_options',
            'tch-raffle-stage',
            array( $this, 'render_stage_placeholder' )
        );
    }

    /**
     * Load WP Media Uploader on our Settings Page
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'tch-raffle' ) === false ) {
            return;
        }
        wp_enqueue_media();
    }

    /**
     * Handle Settings Save, Single Entry Delete, and CSV Export
     */
    public function handle_form_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // 1. Save Settings
        if ( isset( $_POST['tch_raffle_save_settings'] ) && check_admin_referer( 'tch_raffle_settings_nonce' ) ) {
            $settings = array(
                'event_name'      => sanitize_text_field( wp_unslash( $_POST['event_name'] ?? '' ) ),
                'event_place'     => sanitize_text_field( wp_unslash( $_POST['event_place'] ?? '' ) ),
                'event_date'      => sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) ),
                'open_time'       => sanitize_text_field( wp_unslash( $_POST['open_time'] ?? '' ) ),
                'close_time'      => sanitize_text_field( wp_unslash( $_POST['close_time'] ?? '' ) ),
                'live_draw_time'  => sanitize_text_field( wp_unslash( $_POST['live_draw_time'] ?? '' ) ),
                'ticket_prefix'   => sanitize_text_field( wp_unslash( $_POST['ticket_prefix'] ?? 'TCH-' ) ),
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
            wp_safe_redirect( add_query_arg( 'updated', 'true', admin_url( 'admin.php?page=tch-raffle-settings' ) ) );
            exit;
        }

        // 2. Export CSV
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'tch_export_csv' && check_admin_referer( 'tch_export_csv_nonce' ) ) {
            global $wpdb;
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $entries    = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC", ARRAY_A );

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=tch-raffle-entries-' . date( 'Y-m-d-His' ) . '.csv' );
            $output = fopen( 'php://output', 'w' );

            fputcsv( $output, array( 'ID', 'Ticket Reference', 'Full Name', 'Email', 'Mobile / WhatsApp', 'IG Confirmed', 'YT Confirmed', 'Status', 'IP Address', 'Registered At (GST)' ) );

            foreach ( $entries as $row ) {
                fputcsv( $output, $row );
            }
            fclose( $output );
            exit;
        }

        // 3. Delete Single Entry
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_entry' && ! empty( $_GET['entry_id'] ) && check_admin_referer( 'tch_delete_entry_nonce' ) ) {
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
        $s = tch_raffle_get_settings();
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span class="dashicons dashicons-tickets-alt" style="font-size:28px;width:28px;height:28px;"></span>
                TCH Event Raffle — Settings & Landing Page Content
            </h1>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><strong>Settings saved successfully!</strong> Your landing page has been updated.</p></div>
            <?php endif; ?>

            <div style="background:#fff;border-left:4px solid #00a32a;padding:12px 16px;margin:15px 0;box-shadow:0 1px 2px rgba(0,0,0,0.06);">
                <strong>Shortcode to Embed on your Landing Page:</strong>
                <code style="font-size:14px;padding:4px 8px;background:#f0f0f1;margin-left:8px;user-select:all;">[tch_raffle_landing]</code>
                <span style="color:#646970;margin-left:10px;">(Paste this into any page using a Blank/No-Header-Footer template in Flatsome)</span>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'tch_raffle_settings_nonce' ); ?>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(460px, 1fr));gap:20px;margin-top:20px;">
                    
                    <!-- CARD 1: Event Schedule & Countdown -->
                    <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:6px;">
                        <h2 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:10px;">1. Event Schedule & Countdown Timer</h2>
                        <table class="form-table" role="presentation">
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
                                <td>
                                    <input type="time" name="open_time" value="<?php echo esc_attr( $s['open_time'] ); ?>" required>
                                    <p class="description">Default: 15:00 (3:00 PM Dubai Time)</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Registration Closes</label></th>
                                <td>
                                    <input type="time" name="close_time" value="<?php echo esc_attr( $s['close_time'] ); ?>" required>
                                    <p class="description">Countdown target. Form auto-locks after this time (Default: 21:00 / 9:00 PM).</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Live Stage Draw Time</label></th>
                                <td><input type="time" name="live_draw_time" value="<?php echo esc_attr( $s['live_draw_time'] ); ?>"></td>
                            </tr>
                            <tr>
                                <th><label>Ticket ID Prefix</label></th>
                                <td><input type="text" name="ticket_prefix" value="<?php echo esc_attr( $s['ticket_prefix'] ); ?>" style="width:120px;"> <span class="description">e.g. <code>TCH-4829</code></span></td>
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
     * Render Registered Participants Table & CSV Export
     */
    public function render_entries_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
        $search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        if ( ! empty( $search ) ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $entries = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE ref_code LIKE %s OR full_name LIKE %s OR email LIKE %s OR phone LIKE %s ORDER BY created_at DESC", $like, $like, $like, $like ) );
        } else {
            $entries = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC" );
        }

        $total_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        $export_url  = wp_nonce_url( admin_url( 'admin.php?page=tch-raffle-entries&action=tch_export_csv' ), 'tch_export_csv_nonce' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Raffle Registrations (<?php echo esc_html( $total_count ); ?> Total)</h1>
            <a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export All to CSV</a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-warning is-dismissible"><p>Entry deleted.</p></div>
            <?php endif; ?>

            <form method="get" style="margin:15px 0;display:flex;gap:8px;">
                <input type="hidden" name="page" value="tch-raffle-entries">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search Ticket ID, Name, Email, Phone..." style="min-width:280px;">
                <button type="submit" class="button">Search</button>
                <?php if ( ! empty( $search ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tch-raffle-entries' ) ); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:130px;">Ticket Ref ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Mobile / WhatsApp</th>
                        <th style="width:120px;">Social Check</th>
                        <th style="width:170px;">Registered At</th>
                        <th style="width:90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $entries ) ) : ?>
                        <tr><td colspan="7">No raffle entries found yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $entries as $row ) : 
                            $del_url = wp_nonce_url( admin_url( 'admin.php?page=tch-raffle-entries&action=delete_entry&entry_id=' . $row->id ), 'tch_delete_entry_nonce' );
                        ?>
                            <tr>
                                <td><strong style="background:#1d2327;color:#fff;padding:3px 8px;border-radius:4px;font-family:monospace;"><?php echo esc_html( $row->ref_code ); ?></strong></td>
                                <td><strong><?php echo esc_html( $row->full_name ); ?></strong></td>
                                <td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a></td>
                                <td><a href="https://wa.me/<?php echo esc_attr( preg_replace( '/[^0-9]/', '', $row->phone ) ); ?>" target="_blank"><?php echo esc_html( $row->phone ); ?></a></td>
                                <td>IG: ✅ | YT: ✅</td>
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
     * Render Phase 2 Live Stage Launcher & Test Reset Controls
     */
    public function render_stage_placeholder() {
        global $wpdb;
        $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;

        // Handle Reset Statuses Action
        if ( isset( $_POST['tch_reset_draw_statuses'] ) && check_admin_referer( 'tch_reset_draw_nonce' ) ) {
            $wpdb->query( "UPDATE $table_name SET status = 'valid'" );
            echo '<div class="notice notice-success is-dismissible"><p><strong>All entries have been reset to Eligible (valid) status!</strong> You are ready for a fresh draw.</p></div>';
        }

        $valid_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'valid'" );
        $winner_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'winner'" );
        $dq_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'disqualified'" );
        $stage_url    = home_url( '/?tch_live_stage=1' );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span class="dashicons dashicons-megaphone" style="font-size:28px;width:28px;height:28px;"></span>
                TCH Raffle — Live Draw Stage Control Center
            </h1>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;margin-top:20px;max-width:980px;">
                
                <!-- Launch Card -->
                <div style="background:#0f172a;color:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
                    <span style="background:rgba(250,204,21,0.2);color:#facc15;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;">BROADCAST MODE</span>
                    <h2 style="color:#fff;font-size:24px;margin:12px 0 10px;">Launch Full-Screen Stage</h2>
                    <p style="color:#94a3b8;line-height:1.5;margin-bottom:24px;">
                        Opens the standalone, distraction-free animation stage with synthesized drumroll sound effects, live verification controls, and confetti finale.
                    </p>
                    <a href="<?php echo esc_url( $stage_url ); ?>" target="_blank" class="button button-primary button-hero" style="background:#facc15;border-color:#eab308;color:#0f172a;font-weight:800;width:100%;text-align:center;">
                        🚀 Open Live Draw Stage in New Tab ↗
                    </a>
                </div>

                <!-- Pool Status & Reset Card -->
                <div style="background:#fff;padding:28px;border:1px solid #ccd0d4;border-radius:12px;">
                    <h2 style="margin-top:0;">Current Draw Pool Status</h2>
                    <ul style="font-size:15px;line-height:2;margin:16px 0 24px;">
                        <li>🟢 <strong>Eligible Tickets in Pool:</strong> <?php echo esc_html( $valid_count ); ?></li>
                        <li>🏆 <strong>Confirmed Winners:</strong> <?php echo esc_html( $winner_count ); ?></li>
                        <li>❌ <strong>Disqualified Tickets:</strong> <?php echo esc_html( $dq_count ); ?></li>
                    </ul>

                    <form method="post" onsubmit="return confirm('Reset all Winners and Disqualified entries back to Eligible? Use this after practice runs before the real draw.');">
                        <?php wp_nonce_field( 'tch_reset_draw_nonce' ); ?>
                        <button type="submit" name="tch_reset_draw_statuses" class="button button-secondary">
                            🔄 Reset All Tickets to Eligible (Practice Reset)
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }
