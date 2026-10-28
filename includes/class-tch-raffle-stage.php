<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TCH_Raffle_Stage {

    public function __construct() {
        add_action( 'template_redirect', array( $this, 'intercept_stage_route' ) );
        add_action( 'wp_ajax_tch_stage_action', array( $this, 'handle_stage_ajax' ) );
    }

    /**
     * Hijack frontend route ?tch_live_stage=1 for logged-in Admins
     */
    public function intercept_stage_route() {
        if ( ! isset( $_GET['tch_live_stage'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access Denied: You must be logged in as a WordPress Administrator to launch the Live Draw Stage.', 'Unauthorized', array( 'response' => 403 ) );
        }

        show_admin_bar( false );
        $this->render_fullscreen_stage();
        exit;
    }

    /**
     * Handle AJAX actions from the Live Stage (Draw, Confirm, Disqualify, Reset)
     */
    public function handle_stage_ajax() {
        check_ajax_referer( 'tch_stage_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
        $sub_action = sanitize_text_field( wp_unslash( $_POST['sub_action'] ?? '' ) );

        // 1. Get Pool & Current Stats
        if ( 'get_pool' === $sub_action ) {
            $valid_entries = $wpdb->get_results( "SELECT id, ref_code, full_name FROM $table_name WHERE status = 'valid' ORDER BY id ASC" );
            $winners       = $wpdb->get_results( "SELECT id, ref_code, full_name, phone, email FROM $table_name WHERE status = 'winner' ORDER BY id DESC" );
            $disqualified  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'disqualified'" );

            wp_send_json_success( array(
                'pool'         => $valid_entries,
                'valid_count'  => count( $valid_entries ),
                'winners'      => $winners,
                'disqualified' => $disqualified,
            ) );
        }

        // 2. Pick a Random Candidate from Valid Entries
        if ( 'draw_candidate' === $sub_action ) {
            $candidate = $wpdb->get_row( "SELECT * FROM $table_name WHERE status = 'valid' ORDER BY RAND() LIMIT 1" );

            if ( ! $candidate ) {
                wp_send_json_error( array( 'message' => 'No eligible entries left in the pool!' ) );
            }

            // Mask phone & email for public projection screen
            $masked_phone = substr( $candidate->phone, 0, 6 ) . '••••' . substr( $candidate->phone, -2 );

            wp_send_json_success( array(
                'candidate' => array(
                    'id'           => $candidate->id,
                    'ref_code'     => $candidate->ref_code,
                    'full_name'    => $candidate->full_name,
                    'masked_phone' => $masked_phone,
                    'email'        => $candidate->email,
                    'phone'        => $candidate->phone,
                ),
            ) );
        }

        // 3. Confirm Candidate as Official Winner
        if ( 'confirm_winner' === $sub_action ) {
            $entry_id = absint( $_POST['entry_id'] ?? 0 );
            $wpdb->update(
                $table_name,
                array( 'status' => 'winner' ),
                array( 'id' => $entry_id ),
                array( '%s' ),
                array( '%d' )
            );
            wp_send_json_success();
        }

        // 4. Disqualify Candidate & Prepare Redraw
        if ( 'disqualify_entry' === $sub_action ) {
            $entry_id = absint( $_POST['entry_id'] ?? 0 );
            $wpdb->update(
                $table_name,
                array( 'status' => 'disqualified' ),
                array( 'id' => $entry_id ),
                array( '%s' ),
                array( '%d' )
            );
            wp_send_json_success();
        }

        // 5. Reset All Entries back to 'valid' (For Testing before the Event)
        if ( 'reset_all_statuses' === $sub_action ) {
            $wpdb->query( "UPDATE $table_name SET status = 'valid'" );
            wp_send_json_success();
        }

        wp_send_json_error( array( 'message' => 'Invalid stage action.' ) );
    }

    /**
     * Render Standalone Full-Screen Broadcast Stage
     */
    private function render_fullscreen_stage() {
        $s          = tch_raffle_get_settings();
        $stage_css  = TCH_RAFFLE_URL . 'assets/css/tch-raffle-stage.css?v=' . TCH_RAFFLE_VERSION;
        $stage_js   = TCH_RAFFLE_URL . 'assets/js/tch-raffle-stage.js?v=' . TCH_RAFFLE_VERSION;
        $admin_back = admin_url( 'admin.php?page=tch-raffle-stage' );
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Live Raffle Draw — <?php echo esc_html( $s['event_name'] ); ?></title>
            <link rel="stylesheet" href="<?php echo esc_url( $stage_css ); ?>">
            <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
            <script>
                window.tchStageConfig = {
                    ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                    nonce: <?php echo wp_json_encode( wp_create_nonce( 'tch_stage_nonce' ) ); ?>,
                    prefix: <?php echo wp_json_encode( $s['ticket_prefix'] ); ?>,
                    igUrl: <?php echo wp_json_encode( $s['ig_url'] ); ?>
                };
            </script>
        </head>
        <body class="tch-stage-body">

            <!-- Top Broadcast Bar -->
            <header class="tch-stage-header">
                <div class="tch-stage-brand">
                    <span class="tch-live-dot"></span>
                    <strong>THE CYCLE HUB</strong>
                    <span class="tch-divider">|</span>
                    <span><?php echo esc_html( $s['event_name'] ); ?> • <?php echo esc_html( $s['event_place'] ); ?></span>
                </div>

                <div class="tch-stage-stats">
                    <div class="tch-stat-pill">
                        <span>ELIGIBLE TICKETS:</span>
                        <strong id="tch-stat-valid">0</strong>
                    </div>
                    <button type="button" id="tch-btn-fullscreen" class="tch-icon-btn" title="Toggle Fullscreen">⛶ Fullscreen</button>
                    <a href="<?php echo esc_url( $admin_back ); ?>" class="tch-icon-btn">✕ Exit</a>
                </div>
            </header>

            <!-- Main Stage Grid -->
            <main class="tch-stage-main">

                <!-- Left: Prize Showcase -->
                <section class="tch-stage-prize">
                    <div class="tch-prize-glow"></div>
                    <span class="tch-grand-tag">🏆 GRAND PRIZE DRAW</span>
                    <h1><?php echo esc_html( $s['headline'] ); ?></h1>
                    <?php if ( ! empty( $s['prize_image_url'] ) ) : ?>
                        <img src="<?php echo esc_url( $s['prize_image_url'] ); ?>" alt="Prize Bike" class="tch-stage-bike-img" />
                    <?php endif; ?>

                    <!-- Winner Podium List -->
                    <div class="tch-winners-podium" id="tch-winners-box" style="display:none;">
                        <h4>🎉 CONFIRMED WINNER</h4>
                        <div id="tch-winners-list"></div>
                    </div>
                </section>

                <!-- Right: Slot Machine / Digital Draw Engine -->
                <section class="tch-stage-machine">
                    <div class="tch-machine-card" id="tch-machine-card">
                        <div class="tch-machine-status" id="tch-machine-status">READY TO DRAW LUCKY WINNER</div>

                        <!-- Digital Slot Roller Display -->
                        <div class="tch-slot-window" id="tch-slot-window">
                            <div class="tch-slot-code" id="tch-slot-code"><?php echo esc_html( $s['ticket_prefix'] ); ?>•••••</div>
                        </div>

                        <!-- Candidate Reveal & Verification Panel -->
                        <div class="tch-candidate-panel" id="tch-candidate-panel" style="display:none;">
                            <div class="tch-candidate-name" id="tch-candidate-name">-</div>
                            <div class="tch-candidate-meta" id="tch-candidate-meta">-</div>

                            <div class="tch-verify-checklist" id="tch-verify-controls">
                                <p class="tch-verify-prompt">🔍 <strong>Admin Audit:</strong> Verify participant followed <code>@thecyclehub</code>, posted Bike Selfie & subscribed on YouTube:</p>
                                <div class="tch-audit-buttons">
                                    <button type="button" id="tch-btn-confirm" class="tch-audit-btn tch-btn-win">
                                        🏆 Confirm Official Winner!
                                    </button>
                                    <button type="button" id="tch-btn-disqualify" class="tch-audit-btn tch-btn-dq">
                                        ❌ Disqualify & Redraw
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Primary Spin Trigger -->
                        <div class="tch-spin-controls" id="tch-spin-controls">
                            <button type="button" id="tch-btn-spin" class="tch-spin-btn">
                                🎰 SPIN TO DRAW WINNER
                            </button>
                        </div>
                    </div>
                </section>

            </main>

            <script src="<?php echo esc_url( $stage_js ); ?>"></script>
        </body>
        </html>
        <?php
    }
}

new TCH_Raffle_Stage();
