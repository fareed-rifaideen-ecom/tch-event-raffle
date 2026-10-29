<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'TCH_Raffle_Stage' ) ) {

    class TCH_Raffle_Stage {

        public function __construct() {
            add_action( 'template_redirect', array( $this, 'intercept_stage_route' ) );
            add_action( 'wp_ajax_tch_stage_action', array( $this, 'handle_stage_ajax' ) );
            add_action( 'wp_ajax_nopriv_tch_stage_action', array( $this, 'handle_stage_ajax' ) );
        }

        /**
         * Hijack ?tch_live_stage=1 for both Public Spectators and Team Operators
         */
        public function intercept_stage_route() {
            if ( ! isset( $_GET['tch_live_stage'] ) ) {
                return;
            }

            show_admin_bar( false );
            $this->render_fullscreen_stage();
            exit;
        }

        /**
         * Verify if request is authorized as Operator (Logged-in Staff or Valid Operator PIN)
         */
        private function verify_operator_access() {
            if ( tch_raffle_user_can_operate() ) {
                return true;
            }
            $s             = tch_raffle_get_settings();
            $submitted_pin = sanitize_text_field( wp_unslash( $_POST['operator_pin'] ?? '' ) );
            if ( ! empty( $submitted_pin ) && $submitted_pin === (string) $s['operator_pin'] ) {
                return true;
            }
            return false;
        }

        /**
         * Handle AJAX actions for both Public Spectators and Authorized Operators
         */
        public function handle_stage_ajax() {
            check_ajax_referer( 'tch_stage_nonce', 'nonce' );

            global $wpdb;
            $s          = tch_raffle_get_settings();
            $c_slug     = $s['campaign_slug'];
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $sub_action = sanitize_text_field( wp_unslash( $_POST['sub_action'] ?? '' ) );

            // PUBLIC ACTION 1: Get Pool & Live Broadcast State (Used by Spectators & Operators)
            if ( 'get_pool' === $sub_action ) {
                $valid_entries = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, ref_code, full_name FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' ORDER BY id ASC",
                        $c_slug
                    )
                );
                $winners = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, ref_code, full_name FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner' ORDER BY id DESC",
                        $c_slug
                    )
                );

                wp_send_json_success( array(
                    'pool'        => $valid_entries,
                    'valid_count' => count( $valid_entries ),
                    'winners'     => $winners,
                    'live_state'  => tch_raffle_get_live_state(),
                ) );
            }

            // PUBLIC ACTION 2: Verify Team Operator PIN
            if ( 'verify_pin' === $sub_action ) {
                if ( $this->verify_operator_access() ) {
                    wp_send_json_success( array( 'is_operator' => true ) );
                }
                wp_send_json_error( array( 'message' => 'Incorrect Operator PIN.' ) );
            }

            // --- ALL ACTIONS BELOW REQUIRE OPERATOR ACCESS ---
            if ( ! $this->verify_operator_access() ) {
                wp_send_json_error( array( 'message' => 'Operator authorization required.' ) );
            }

            // OPERATOR ACTION 3: Switch Active Animation Engine
            if ( 'switch_engine' === $sub_action ) {
                $engine            = sanitize_key( wp_unslash( $_POST['engine'] ?? 'slot' ) );
                $s['stage_engine'] = $engine;
                update_option( 'tch_raffle_settings', $s );
                $live_state = tch_raffle_update_live_state( array( 'engine' => $engine ) );
                wp_send_json_success( array( 'live_state' => $live_state ) );
            }

            // OPERATOR ACTION 4: Pick a Random Candidate & Broadcast Spin to All Spectators
            if ( 'draw_candidate' === $sub_action ) {
                $candidate = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' ORDER BY RAND() LIMIT 1",
                        $c_slug
                    )
                );

                if ( ! $candidate ) {
                    wp_send_json_error( array( 'message' => 'No eligible tickets left in the pool for this event!' ) );
                }

                // Also grab up to 23 random decoys for the 24-Slice Wheel & Drum engines
                $decoys = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ref_code, full_name FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' AND id != %d ORDER BY RAND() LIMIT 23",
                        $c_slug,
                        $candidate->id
                    )
                );

                $masked_phone   = substr( $candidate->phone, 0, 6 ) . '••••' . substr( $candidate->phone, -2 );
                $candidate_data = array(
                    'id'           => (int) $candidate->id,
                    'ref_code'     => $candidate->ref_code,
                    'full_name'    => $candidate->full_name,
                    'masked_phone' => $masked_phone,
                    'decoys'       => $decoys,
                );

                $live_state = tch_raffle_update_live_state( array(
                    'status'        => 'spinning',
                    'spin_id'       => time(),
                    'candidate'     => $candidate_data,
                    'campaign_slug' => $c_slug,
                ) );

                wp_send_json_success( array(
                    'candidate'  => $candidate_data,
                    'live_state' => $live_state,
                ) );
            }

            // OPERATOR ACTION 5: Confirm Candidate as Official Winner
            if ( 'confirm_winner' === $sub_action ) {
                $entry_id = absint( $_POST['entry_id'] ?? 0 );
                $wpdb->update(
                    $table_name,
                    array( 'status' => 'winner' ),
                    array( 'id' => $entry_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                $live_state = tch_raffle_update_live_state( array(
                    'status' => 'winner_confirmed',
                ) );

                wp_send_json_success( array( 'live_state' => $live_state ) );
            }

            // OPERATOR ACTION 6: Disqualify Candidate & Prepare Redraw
            if ( 'disqualify_entry' === $sub_action ) {
                $entry_id = absint( $_POST['entry_id'] ?? 0 );
                $wpdb->update(
                    $table_name,
                    array( 'status' => 'disqualified' ),
                    array( 'id' => $entry_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                $live_state = tch_raffle_update_live_state( array(
                    'status'    => 'disqualified',
                    'candidate' => null,
                ) );

                wp_send_json_success( array( 'live_state' => $live_state ) );
            }

            wp_send_json_error( array( 'message' => 'Unknown stage action.' ) );
        }

        /**
         * Render Standalone Full-Screen Broadcast Stage (5 Engines + Spectator/Operator Modes)
         */
        private function render_fullscreen_stage() {
            $s           = tch_raffle_get_settings();
            $live_state  = tch_raffle_get_live_state();
            $is_operator = tch_raffle_user_can_operate();
            $stage_css   = TCH_RAFFLE_URL . 'assets/css/tch-raffle-stage.css?v=' . TCH_RAFFLE_VERSION;
            $stage_js    = TCH_RAFFLE_URL . 'assets/js/tch-raffle-stage.js?v=' . TCH_RAFFLE_VERSION;
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Live Raffle Broadcast — <?php echo esc_html( $s['event_name'] ); ?></title>
                <link rel="stylesheet" href="<?php echo esc_url( $stage_css ); ?>">
                <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
                <script>
                    window.tchStageConfig = {
                        ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                        nonce: <?php echo wp_json_encode( wp_create_nonce( 'tch_stage_nonce' ) ); ?>,
                        prefix: <?php echo wp_json_encode( $s['ticket_prefix'] ); ?>,
                        defaultEngine: <?php echo wp_json_encode( $live_state['engine'] ?? $s['stage_engine'] ); ?>,
                        isOperator: <?php echo $is_operator ? 'true' : 'false'; ?>
                    };
                </script>
            </head>
            <body class="tch-stage-body">

                <!-- Top Broadcast Bar -->
                <header class="tch-stage-header">
                    <div class="tch-stage-brand">
                        <span class="tch-live-dot"></span>
                        <strong>THE CYCLE HUB LIVE</strong>
                        <span class="tch-divider">|</span>
                        <span><?php echo esc_html( $s['event_name'] ); ?> • <?php echo esc_html( $s['event_place'] ); ?></span>
                    </div>

                    <div class="tch-stage-stats">
                        <!-- Operator Engine Switcher (Visible only when Operator is unlocked) -->
                        <div id="tch-operator-toolbar" class="tch-operator-toolbar" style="display:<?php echo $is_operator ? 'flex' : 'none'; ?>;">
                            <label for="tch-engine-select">Engine:</label>
                            <select id="tch-engine-select" class="tch-engine-select">
                                <option value="slot">1. Digital Slot</option>
                                <option value="drum">2. 3D Color Drum</option>
                                <option value="cyber_wheel">3. Neon Cyber Wheel</option>
                                <option value="carnival_wheel">4. Carnival Wheel</option>
                                <option value="lotto_cage">5. Lotto Ball Cage</option>
                            </select>
                        </div>

                        <div class="tch-stat-pill">
                            <span>ELIGIBLE TICKETS:</span>
                            <strong id="tch-stat-valid">0</strong>
                        </div>

                        <button type="button" id="tch-btn-pin-login" class="tch-icon-btn" style="display:<?php echo $is_operator ? 'none' : 'inline-flex'; ?>;">
                            🔑 Operator Login
                        </button>
                        <button type="button" id="tch-btn-fullscreen" class="tch-icon-btn" title="Toggle Fullscreen">⛶ Fullscreen</button>
                    </div>
                </header>

                <!-- Main Stage Grid -->
                <main class="tch-stage-main">

                    <!-- Left Column: Prize Bike & Confirmed Winner Podium -->
                    <section class="tch-stage-prize">
                        <span class="tch-grand-tag">🏆 OFFICIAL LIVE RAFFLE DRAW</span>
                        <h1><?php echo esc_html( $s['headline'] ); ?></h1>
                        <?php if ( ! empty( $s['prize_image_url'] ) ) : ?>
                            <img src="<?php echo esc_url( $s['prize_image_url'] ); ?>" alt="Prize Bike" class="tch-stage-bike-img" />
                        <?php endif; ?>

                        <!-- Winner Podium List -->
                        <div class="tch-winners-podium" id="tch-winners-box" style="display:none;">
                            <h4>🎉 OFFICIAL CONFIRMED WINNER</h4>
                            <div id="tch-winners-list"></div>
                        </div>
                    </section>

                    <!-- Right Column: Multi-Engine Stage Container -->
                    <section class="tch-stage-machine">
                        <div class="tch-machine-card" id="tch-machine-card">
                            <div class="tch-machine-status" id="tch-machine-status">WAITING FOR LIVE DRAW TO START...</div>

                            <!-- ==========================================
                                 ENGINE 1: DIGITAL SLOT ODOMETER
                                 ========================================== -->
                            <div class="tch-engine-view" id="tch-engine-slot">
                                <div class="tch-slot-window" id="tch-slot-window">
                                    <div class="tch-slot-code" id="tch-slot-code"><?php echo esc_html( $s['ticket_prefix'] ); ?>•••••</div>
                                </div>
                            </div>

                            <!-- ==========================================
                                 ENGINE 2: 3D VERTICAL COLOR DRUM
                                 ========================================== -->
                            <div class="tch-engine-view" id="tch-engine-drum" style="display:none;">
                                <div class="tch-drum-viewport">
                                    <div class="tch-drum-barrel" id="tch-drum-barrel">
                                        <!-- Populated dynamically by JS -->
                                    </div>
                                </div>
                            </div>

                            <!-- ==========================================
                                 ENGINE 3 & 4: NEON CYBER WHEEL & CARNIVAL WHEEL
                                 ========================================== -->
                            <div class="tch-engine-view" id="tch-engine-wheel" style="display:none;">
                                <div class="tch-wheel-stage" id="tch-wheel-wrapper">
                                    <div class="tch-wheel-pointer" id="tch-wheel-pointer">▼</div>
                                    <canvas id="tch-wheel-canvas" width="440" height="440"></canvas>
                                    <div class="tch-wheel-center-cap" id="tch-wheel-cap">★</div>
                                </div>
                            </div>

                            <!-- ==========================================
                                 ENGINE 5: LOTTO BALL CAGE & CHUTE
                                 ========================================== -->
                            <div class="tch-engine-view" id="tch-engine-lotto" style="display:none;">
                                <div class="tch-lotto-wrapper">
                                    <div class="tch-lotto-dome">
                                        <canvas id="tch-lotto-canvas" width="320" height="320"></canvas>
                                    </div>
                                    <!-- Bottom Wooden/Glass Chute Rack for Winning Ref Characters -->
                                    <div class="tch-lotto-rack" id="tch-lotto-rack">
                                        <div class="tch-rack-ball">?</div>
                                        <div class="tch-rack-ball">?</div>
                                        <div class="tch-rack-ball">?</div>
                                        <div class="tch-rack-ball">?</div>
                                        <div class="tch-rack-ball">?</div>
                                    </div>
                                </div>
                            </div>

                            <!-- ==========================================
                                 CANDIDATE REVEAL & VERIFICATION PANEL
                                 ========================================== -->
                            <div class="tch-candidate-panel" id="tch-candidate-panel" style="display:none;">
                                <div class="tch-candidate-ref-badge" id="tch-candidate-ref-badge">TCH-00000</div>
                                <div class="tch-candidate-name" id="tch-candidate-name">-</div>
                                <div class="tch-candidate-meta" id="tch-candidate-meta">-</div>

                                <!-- Operator-Only Audit Buttons -->
                                <div class="tch-verify-checklist" id="tch-verify-controls" style="display:none;">
                                    <p class="tch-verify-prompt">🔍 <strong>Operator Audit:</strong> Verify participant followed <code>@thecyclehub</code>, posted Bike Selfie & subscribed on YouTube:</p>
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

                            <!-- Primary Spin Button (Visible Only to Authorized Operators) -->
                            <div class="tch-spin-controls" id="tch-spin-controls" style="display:<?php echo $is_operator ? 'block' : 'none'; ?>;">
                                <button type="button" id="tch-btn-spin" class="tch-spin-btn">
                                    🎰 SPIN TO DRAW WINNER
                                </button>
                            </div>

                            <!-- Spectator Live Indicator (Visible to Public Spectators) -->
                            <div class="tch-spectator-banner" id="tch-spectator-banner" style="display:<?php echo $is_operator ? 'none' : 'flex'; ?>;">
                                <span class="tch-live-dot"></span>
                                <span>Connected to Live Broadcast — Screen updates automatically in real time</span>
                            </div>

                        </div>
                    </section>

                </main>

                <!-- Team Operator PIN Modal -->
                <div class="tch-pin-modal" id="tch-pin-modal" style="display:none;">
                    <div class="tch-pin-dialog">
                        <h3>🔑 Team Operator Unlock</h3>
                        <p>Enter the 4-digit Operator PIN to unlock Spin & Winner Controls on this device:</p>
                        <input type="password" id="tch-pin-input" placeholder="••••" maxlength="12" class="tch-pin-input">
                        <div class="tch-pin-actions">
                            <button type="button" id="tch-pin-submit" class="tch-spin-btn" style="padding:12px;font-size:15px;">Unlock Controls</button>
                            <button type="button" id="tch-pin-cancel" class="tch-icon-btn">Cancel</button>
                        </div>
                    </div>
                </div>

                <script src="<?php echo esc_url( $stage_js ); ?>"></script>
            </body>
            </html>
            <?php
        }
    }

    new TCH_Raffle_Stage();
}
