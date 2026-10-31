<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'TCH_Raffle_Stage' ) ) {

    class TCH_Raffle_Stage {

        public function __construct() {
            add_action( 'template_redirect', array( $this, 'intercept_custom_routes' ) );
            add_action( 'wp_ajax_tch_stage_action', array( $this, 'handle_stage_ajax' ) );
            add_action( 'wp_ajax_nopriv_tch_stage_action', array( $this, 'handle_stage_ajax' ) );
            add_action( 'wp_ajax_tch_team_portal_action', array( $this, 'handle_team_portal_ajax' ) );
            add_action( 'wp_ajax_nopriv_tch_team_portal_action', array( $this, 'handle_team_portal_ajax' ) );
        }

        public function intercept_custom_routes() {
            if ( isset( $_GET['tch_live_stage'] ) ) {
                show_admin_bar( false );

                if ( isset( $_GET['operator'] ) && tch_raffle_user_can_operate() ) {
                    $ls = tch_raffle_get_live_state();
                    if ( 'winner_confirmed' !== $ls['status'] ) {
                        tch_raffle_update_live_state( array(
                            'status'    => 'idle',
                            'candidate' => null,
                        ) );
                    }
                }

                $this->render_fullscreen_stage();
                exit;
            }

            if ( isset( $_GET['tch_team_entries'] ) ) {
                show_admin_bar( false );
                $this->handle_team_portal_route();
                exit;
            }
        }

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

        private function verify_team_portal_access() {
            if ( tch_raffle_user_can_operate() ) {
                return true;
            }
            $s             = tch_raffle_get_settings();
            $submitted_pin = sanitize_text_field( wp_unslash( $_REQUEST['team_pin'] ?? '' ) );
            if ( ! empty( $submitted_pin ) && ( $submitted_pin === (string) $s['team_entries_pin'] || $submitted_pin === (string) $s['operator_pin'] ) ) {
                return true;
            }
            return false;
        }

        /**
         * Handle AJAX actions for Live Stage (Filtered by Correct Tyre Pressure Answer)
         */
        public function handle_stage_ajax() {
            check_ajax_referer( 'tch_stage_nonce', 'nonce' );

            global $wpdb;
            $s          = tch_raffle_get_settings();
            $c_slug     = $s['campaign_slug'];
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $sub_action = sanitize_text_field( wp_unslash( $_POST['sub_action'] ?? '' ) );

            $quiz_active  = ! empty( $s['quiz_enabled'] );
            $correct_ans  = trim( (string) $s['quiz_correct'] );

            // PUBLIC ACTION 1: Get Qualified Pool & Live Broadcast State
            if ( 'get_pool' === $sub_action ) {
                if ( $quiz_active ) {
                    $valid_entries = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT id, ref_code, full_name, quiz_answer FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' AND quiz_answer = %s ORDER BY id ASC",
                            $c_slug,
                            $correct_ans
                        )
                    );
                } else {
                    $valid_entries = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT id, ref_code, full_name, quiz_answer FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' ORDER BY id ASC",
                            $c_slug
                        )
                    );
                }

                $winners = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, ref_code, full_name, quiz_answer FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner' ORDER BY id DESC",
                        $c_slug
                    )
                );

                $live_state = tch_raffle_get_live_state();
                if ( ! empty( $winners ) ) {
                    $live_state['status'] = 'winner_confirmed';
                }

                wp_send_json_success( array(
                    'pool'        => is_array( $valid_entries ) ? $valid_entries : array(),
                    'valid_count' => is_array( $valid_entries ) ? count( $valid_entries ) : 0,
                    'winners'     => is_array( $winners ) ? $winners : array(),
                    'live_state'  => $live_state,
                ) );
            }

            // PUBLIC ACTION 2: Verify Team Operator PIN
            if ( 'verify_pin' === $sub_action ) {
                if ( $this->verify_operator_access() ) {
                    wp_send_json_success( array( 'is_operator' => true ) );
                }
                wp_send_json_error( array( 'message' => 'Incorrect Operator PIN.' ) );
            }

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

            // OPERATOR ACTION 4: Pick a Random Candidate ONLY from Qualified Correct Answers
            if ( 'draw_candidate' === $sub_action ) {
                if ( $quiz_active ) {
                    $candidate = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' AND quiz_answer = %s ORDER BY RAND() LIMIT 1",
                            $c_slug,
                            $correct_ans
                        )
                    );
                } else {
                    $candidate = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' ORDER BY RAND() LIMIT 1",
                            $c_slug
                        )
                    );
                }

                if ( ! $candidate ) {
                    wp_send_json_error( array( 'message' => 'No qualified tickets (with the correct answer "' . $correct_ans . '") left in the pool!' ) );
                }

                if ( $quiz_active ) {
                    $decoys = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT ref_code, full_name FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' AND quiz_answer = %s AND id != %d ORDER BY RAND() LIMIT 23",
                            $c_slug,
                            $correct_ans,
                            $candidate->id
                        )
                    );
                } else {
                    $decoys = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT ref_code, full_name FROM `$table_name` WHERE campaign_slug = %s AND status = 'valid' AND id != %d ORDER BY RAND() LIMIT 23",
                            $c_slug,
                            $candidate->id
                        )
                    );
                }

                $masked_phone   = substr( $candidate->phone, 0, 6 ) . '••••' . substr( $candidate->phone, -2 );
                $candidate_data = array(
                    'id'           => (int) $candidate->id,
                    'ref_code'     => $candidate->ref_code,
                    'full_name'    => $candidate->full_name,
                    'quiz_answer'  => $candidate->quiz_answer ?? '',
                    'masked_phone' => $masked_phone,
                    'decoys'       => is_array( $decoys ) ? $decoys : array(),
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
         * Render Standalone Full-Screen Broadcast Stage
         */
        private function render_fullscreen_stage() {
            global $wpdb;
            $s           = tch_raffle_get_settings();
            $c_slug      = $s['campaign_slug'];
            $table_name  = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $live_state  = tch_raffle_get_live_state();
            $is_operator = tch_raffle_user_can_operate();
            $stage_css   = TCH_RAFFLE_URL . 'assets/css/tch-raffle-stage.css?v=' . TCH_RAFFLE_VERSION;
            $stage_js    = TCH_RAFFLE_URL . 'assets/js/tch-raffle-stage.js?v=' . TCH_RAFFLE_VERSION;

            $winners = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, ref_code, full_name FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner' ORDER BY id DESC",
                    $c_slug
                )
            );
            $has_winner = ! empty( $winners );
            if ( $has_winner ) {
                $live_state['status'] = 'winner_confirmed';
            }

            $draw_date_fmt = date_i18n( 'jS M Y', strtotime( $s['live_draw_date'] ?? $s['event_date'] ) );
            $draw_time_fmt = date_i18n( 'g:i A', strtotime( $s['live_draw_time'] ) );
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
                        isOperator: <?php echo $is_operator ? 'true' : 'false'; ?>,
                        initialSpinId: <?php echo (int) ( $live_state['spin_id'] ?? 0 ); ?>,
                        initialStatus: <?php echo wp_json_encode( $live_state['status'] ?? 'idle' ); ?>,
                        initialWinners: <?php echo wp_json_encode( is_array( $winners ) ? $winners : array() ); ?>
                    };
                </script>
            </head>
            <body class="tch-stage-body">

                <header class="tch-stage-header">
                    <div class="tch-stage-brand">
                        <span class="tch-live-dot" id="tch-header-live-dot"></span>
                        <strong id="tch-header-live-title"><?php echo $has_winner ? 'OFFICIAL DRAW RESULTS' : 'THE CYCLE HUB LIVE'; ?></strong>
                        <span class="tch-divider">|</span>
                        <span><?php echo esc_html( $s['event_name'] ); ?> • <?php echo esc_html( $draw_date_fmt . ' @ ' . $draw_time_fmt ); ?></span>
                    </div>

                    <div class="tch-stage-stats">
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

                        <div class="tch-stat-pill" id="tch-eligible-pill">
                            <span>QUALIFIED TICKETS:</span>
                            <strong id="tch-stat-valid">0</strong>
                        </div>

                        <button type="button" id="tch-btn-pin-login" class="tch-icon-btn" style="display:<?php echo $is_operator ? 'none' : 'inline-flex'; ?>;">
                            🔑 Operator Login
                        </button>
                        <button type="button" id="tch-btn-fullscreen" class="tch-icon-btn" title="Toggle Fullscreen">⛶ Fullscreen</button>
                    </div>
                </header>

                <main class="tch-stage-main">

                    <section class="tch-stage-prize">
                        <span class="tch-grand-tag">🏆 OFFICIAL RAFFLE DRAW</span>
                        <h1><?php echo esc_html( $s['headline'] ); ?></h1>
                        <?php if ( ! empty( $s['prize_image_url'] ) ) : ?>
                            <img src="<?php echo esc_url( $s['prize_image_url'] ); ?>" alt="Prize Bike" class="tch-stage-bike-img" />
                        <?php endif; ?>

                        <div class="tch-winners-podium" id="tch-winners-box" style="display:none;">
                            <h4>🎉 OFFICIAL CONFIRMED WINNER</h4>
                            <div id="tch-winners-list"></div>
                        </div>
                    </section>

                    <section class="tch-stage-machine">
                        <div class="tch-machine-card <?php echo $has_winner ? 'is-winner' : ''; ?>" id="tch-machine-card">
                            <div class="tch-machine-status" id="tch-machine-status">
                                <?php echo $has_winner ? '🏁 LIVE BROADCAST ENDED — OFFICIAL DRAW RESULT' : 'READY FOR OPERATOR TO SPIN'; ?>
                            </div>

                            <div id="tch-broadcast-ended-view" class="tch-broadcast-ended-view" style="display:<?php echo $has_winner ? 'block' : 'none'; ?>;">
                                <div class="tch-ended-trophy">🏆</div>
                                <div class="tch-ended-label">WINNING RAFFLE TICKET</div>
                                <div class="tch-ended-ref" id="tch-ended-ref">
                                    <?php echo $has_winner ? esc_html( $winners[0]->ref_code ) : 'TCH-00000'; ?>
                                </div>
                                <div class="tch-ended-name" id="tch-ended-name">
                                    <?php echo $has_winner ? esc_html( $winners[0]->full_name ) : ''; ?>
                                </div>
                                <p class="tch-ended-note">Congratulations to our lucky winner! Thank you to everyone who participated at <?php echo esc_html( $s['event_name'] ); ?>.</p>
                            </div>

                            <div id="tch-active-engines-wrapper" style="display:<?php echo $has_winner ? 'none' : 'block'; ?>;">
                                <div class="tch-engine-view" id="tch-engine-slot">
                                    <div class="tch-slot-window" id="tch-slot-window">
                                        <div class="tch-slot-code" id="tch-slot-code"><?php echo esc_html( $s['ticket_prefix'] ); ?>•••••</div>
                                    </div>
                                </div>

                                <div class="tch-engine-view" id="tch-engine-drum" style="display:none;">
                                    <div class="tch-drum-viewport">
                                        <div class="tch-drum-barrel" id="tch-drum-barrel"></div>
                                    </div>
                                </div>

                                <div class="tch-engine-view" id="tch-engine-wheel" style="display:none;">
                                    <div class="tch-wheel-stage" id="tch-wheel-wrapper">
                                        <div class="tch-wheel-pointer" id="tch-wheel-pointer">▼</div>
                                        <canvas id="tch-wheel-canvas" width="440" height="440"></canvas>
                                        <div class="tch-wheel-center-cap" id="tch-wheel-cap">★</div>
                                    </div>
                                </div>

                                <div class="tch-engine-view" id="tch-engine-lotto" style="display:none;">
                                    <div class="tch-lotto-wrapper">
                                        <div class="tch-lotto-dome">
                                            <canvas id="tch-lotto-canvas" width="320" height="320"></canvas>
                                        </div>
                                        <div class="tch-lotto-rack" id="tch-lotto-rack">
                                            <div class="tch-rack-ball">?</div>
                                            <div class="tch-rack-ball">?</div>
                                            <div class="tch-rack-ball">?</div>
                                            <div class="tch-rack-ball">?</div>
                                            <div class="tch-rack-ball">?</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tch-candidate-panel" id="tch-candidate-panel" style="display:none;">
                                <div class="tch-candidate-ref-badge" id="tch-candidate-ref-badge">TCH-00000</div>
                                <div class="tch-candidate-name" id="tch-candidate-name">-</div>
                                <div class="tch-candidate-meta" id="tch-candidate-meta">-</div>

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

                            <div class="tch-spin-controls" id="tch-spin-controls" style="display:<?php echo ( $is_operator && ! $has_winner ) ? 'block' : 'none'; ?>;">
                                <button type="button" id="tch-btn-spin" class="tch-spin-btn">
                                    🎰 SPIN TO DRAW WINNER
                                </button>
                            </div>

                            <div class="tch-spectator-banner" id="tch-spectator-banner" style="display:<?php echo $is_operator ? 'none' : 'flex'; ?>;">
                                <span class="tch-live-dot" id="tch-spec-dot"></span>
                                <span id="tch-spectator-msg">
                                    <?php echo $has_winner ? 'Official Winner Announced — Live Broadcast Ended' : 'Connected to Live Broadcast — Waiting for Operator to Spin'; ?>
                                </span>
                            </div>

                        </div>
                    </section>

                </main>

                <div class="tch-pin-modal" id="tch-pin-modal" style="display:none;">
                    <div class="tch-pin-dialog">
                        <h3>🔑 Team Operator Unlock</h3>
                        <p>Enter the Operator PIN to unlock Spin & Winner Controls on this device:</p>
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

        /**
         * Frontend Team Entries Portal (?tch_team_entries=1)
         */
        private function handle_team_portal_route() {
            global $wpdb;
            $s          = tch_raffle_get_settings();
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;

            if ( isset( $_GET['export_csv'] ) && '1' === $_GET['export_csv'] ) {
                if ( ! $this->verify_team_portal_access() ) {
                    wp_die( 'Invalid Team PIN for CSV Export.', 'Unauthorized', array( 'response' => 403 ) );
                }

                $filter_slug = isset( $_GET['campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign'] ) ) : $s['campaign_slug'];
                $search      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

                $where = array();
                $args  = array();

                if ( ! empty( $filter_slug ) && 'ALL' !== $filter_slug ) {
                    $where[] = 'campaign_slug = %s';
                    $args[]  = $filter_slug;
                }
                if ( ! empty( $search ) ) {
                    $like    = '%' . $wpdb->esc_like( $search ) . '%';
                    $where[] = '(ref_code LIKE %s OR full_name LIKE %s OR email LIKE %s OR phone LIKE %s OR quiz_answer LIKE %s)';
                    array_push( $args, $like, $like, $like, $like, $like );
                }

                $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
                $sql       = "SELECT * FROM `$table_name` $where_sql ORDER BY created_at DESC";
                $entries   = ! empty( $args ) ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

                header( 'Content-Type: text/csv; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename=tch-entries-' . sanitize_file_name( $filter_slug ) . '-' . gmdate( 'Y-m-d-Hi' ) . '.csv' );
                $out = fopen( 'php://output', 'w' );
                fputcsv( $out, array( 'ID', 'Event Campaign', 'Ticket Ref', 'Full Name', 'Email', 'Mobile / WhatsApp', 'Tyre Pressure Answer', 'Qualified', 'Status', 'Registered At' ) );
                if ( ! empty( $entries ) ) {
                    foreach ( $entries as $r ) {
                        $ans  = $r['quiz_answer'] ?? '';
                        $qual = tch_raffle_is_answer_correct( $ans, $s ) ? 'YES' : 'NO';
                        fputcsv( $out, array(
                            $r['id'],
                            $r['campaign_slug'],
                            $r['ref_code'],
                            $r['full_name'],
                            $r['email'],
                            $r['phone'],
                            $ans,
                            $qual,
                            $r['status'],
                            $r['created_at'],
                        ) );
                    }
                }
                fclose( $out );
                exit;
            }

            $stage_css   = TCH_RAFFLE_URL . 'assets/css/tch-raffle-stage.css?v=' . TCH_RAFFLE_VERSION;
            $is_unlocked = tch_raffle_user_can_operate();
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Team Entries Portal — <?php echo esc_html( $s['event_name'] ); ?></title>
                <link rel="stylesheet" href="<?php echo esc_url( $stage_css ); ?>">
            </head>
            <body class="tch-stage-body">

                <header class="tch-stage-header">
                    <div class="tch-stage-brand">
                        <strong>📋 TCH TEAM ENTRIES PORTAL</strong>
                        <span class="tch-divider">|</span>
                        <span><?php echo esc_html( $s['event_name'] ); ?></span>
                    </div>
                    <div class="tch-stage-stats">
                        <a href="<?php echo esc_url( home_url( '/?tch_live_stage=1' ) ); ?>" class="tch-icon-btn">🎰 Live Stage ↗</a>
                    </div>
                </header>

                <div id="tch-team-pin-gate" class="tch-pin-modal" style="display:<?php echo $is_unlocked ? 'none' : 'flex'; ?>;">
                    <div class="tch-pin-dialog">
                        <h3>🔒 Team Entries Portal</h3>
                        <p>Enter the Team Portal PIN to view, search, and export participant entries:</p>
                        <input type="password" id="tch-team-pin-input" placeholder="••••" maxlength="12" class="tch-pin-input">
                        <div id="tch-team-pin-err" style="color:#fca5a5;font-size:13px;margin-bottom:10px;display:none;">Incorrect PIN. Please try again.</div>
                        <button type="button" id="tch-team-pin-btn" class="tch-spin-btn" style="padding:12px;font-size:15px;">Unlock Entries</button>
                    </div>
                </div>

                <main class="tch-team-portal-wrap" id="tch-team-portal-wrap" style="display:<?php echo $is_unlocked ? 'block' : 'none'; ?>;">
                    <div class="tch-tp-stats" id="tch-tp-stats">
                        <div class="tch-tp-stat-card"><small>TOTAL ENTRIES</small><strong id="tp-count-total">0</strong></div>
                        <div class="tch-tp-stat-card"><small>QUALIFIED (CORRECT PSI)</small><strong id="tp-count-qualified" style="color:#86efac;">0</strong></div>
                        <div class="tch-tp-stat-card"><small>CONFIRMED WINNER</small><strong id="tp-count-win" style="color:#facc15;">0</strong></div>
                        <div class="tch-tp-stat-card"><small>DISQUALIFIED</small><strong id="tp-count-dq" style="color:#fca5a5;">0</strong></div>
                    </div>

                    <div class="tch-tp-toolbar">
                        <select id="tp-campaign-select" class="tch-tp-input" style="max-width:240px;"></select>
                        <input type="search" id="tp-search-input" class="tch-tp-input" placeholder="Search Ticket ID, Name, Email, WhatsApp, or Answer...">
                        <button type="button" id="tp-search-btn" class="tch-icon-btn" style="background:#eab308;color:#070b14;font-weight:800;">🔍 Search</button>
                        <button type="button" id="tp-export-btn" class="tch-icon-btn" style="background:#22c55e;color:#052e16;font-weight:800;">📥 Export CSV</button>
                    </div>

                    <div class="tch-tp-table-container">
                        <table class="tch-tp-table">
                            <thead>
                                <tr>
                                    <th>Ticket ID</th>
                                    <th>Participant Name</th>
                                    <th>WhatsApp / Mobile</th>
                                    <th>Email</th>
                                    <th>Tyre Pressure Guess</th>
                                    <th>Status</th>
                                    <th>Registered At</th>
                                </tr>
                            </thead>
                            <tbody id="tp-table-body">
                                <tr><td colspan="7" style="text-align:center;padding:30px;">Loading entries...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </main>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                    const nonce      = <?php echo wp_json_encode( wp_create_nonce( 'tch_team_portal_nonce' ) ); ?>;
                    let isUnlocked   = <?php echo $is_unlocked ? 'true' : 'false'; ?>;
                    let teamPin      = sessionStorage.getItem('tch_team_portal_pin') || '';
                    let activeSlug   = <?php echo wp_json_encode( $s['campaign_slug'] ); ?>;

                    const pinGate    = document.getElementById('tch-team-pin-gate');
                    const pinInput   = document.getElementById('tch-team-pin-input');
                    const pinBtn     = document.getElementById('tch-team-pin-btn');
                    const pinErr     = document.getElementById('tch-team-pin-err');
                    const portalWrap = document.getElementById('tch-team-portal-wrap');
                    const campSelect = document.getElementById('tp-campaign-select');
                    const searchInp  = document.getElementById('tp-search-input');
                    const searchBtn  = document.getElementById('tp-search-btn');
                    const exportBtn  = document.getElementById('tp-export-btn');
                    const tbody      = document.getElementById('tp-table-body');

                    function fetchEntries() {
                        const fd = new FormData();
                        fd.append('action', 'tch_team_portal_action');
                        fd.append('nonce', nonce);
                        fd.append('team_pin', teamPin);
                        fd.append('campaign', campSelect.value || activeSlug);
                        fd.append('q', searchInp.value.trim());

                        fetch(ajaxUrl, { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(res => {
                                if (!res.success) {
                                    pinErr.style.display = 'block';
                                    return;
                                }
                                pinGate.style.display    = 'none';
                                portalWrap.style.display = 'block';
                                if (teamPin) sessionStorage.setItem('tch_team_portal_pin', teamPin);

                                const d = res.data;
                                document.getElementById('tp-count-total').textContent     = d.stats.total;
                                document.getElementById('tp-count-qualified').textContent = d.stats.qualified;
                                document.getElementById('tp-count-win').textContent       = d.stats.winner;
                                document.getElementById('tp-count-dq').textContent        = d.stats.dq;

                                if (campSelect.options.length === 0 && d.campaigns) {
                                    d.campaigns.forEach(c => {
                                        const opt = document.createElement('option');
                                        opt.value = c;
                                        opt.textContent = c === activeSlug ? c + ' (Active)' : c;
                                        if (c === d.current_campaign) opt.selected = true;
                                        campSelect.appendChild(opt);
                                    });
                                    const allOpt = document.createElement('option');
                                    allOpt.value = 'ALL';
                                    allOpt.textContent = 'All Events Combined';
                                    campSelect.appendChild(allOpt);
                                }

                                if (!d.entries || d.entries.length === 0) {
                                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:28px;color:#94a3b8;">No matching entries found.</td></tr>';
                                    return;
                                }

                                tbody.innerHTML = d.entries.map(row => {
                                    const cleanPhone = String(row.phone).replace(/[^0-9]/g, '');
                                    const badgeClass = row.status === 'winner' ? 'tp-badge-win' : (row.status === 'disqualified' ? 'tp-badge-dq' : 'tp-badge-valid');
                                    const ansBadge   = row.is_correct ? `<span style="color:#86efac;font-weight:700;">✅ ${esc(row.quiz_answer)}</span>` : `<span style="color:#fca5a5;">❌ ${esc(row.quiz_answer || '-')}</span>`;
                                    return `
                                        <tr>
                                            <td><code class="tp-ref">${esc(row.ref_code)}</code></td>
                                            <td><strong>${esc(row.full_name)}</strong></td>
                                            <td><a href="https://wa.me/${esc(cleanPhone)}" target="_blank" style="color:#38bdf8;text-decoration:none;">📱 ${esc(row.phone)}</a></td>
                                            <td><a href="mailto:${esc(row.email)}" style="color:#cbd5e1;text-decoration:none;">${esc(row.email)}</a></td>
                                            <td>${ansBadge}</td>
                                            <td><span class="tp-badge ${badgeClass}">${esc(row.status.toUpperCase())}</span></td>
                                            <td style="color:#94a3b8;font-size:12px;">${esc(row.created_at)}</td>
                                        </tr>
                                    `;
                                }).join('');
                            });
                    }

                    pinBtn.addEventListener('click', function() {
                        teamPin = pinInput.value.trim();
                        pinErr.style.display = 'none';
                        fetchEntries();
                    });
                    pinInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') pinBtn.click();
                    });

                    searchBtn.addEventListener('click', fetchEntries);
                    searchInp.addEventListener('keyup', function(e) {
                        if (e.key === 'Enter') fetchEntries();
                    });
                    campSelect.addEventListener('change', fetchEntries);

                    exportBtn.addEventListener('click', function() {
                        const c = encodeURIComponent(campSelect.value || activeSlug);
                        const q = encodeURIComponent(searchInp.value.trim());
                        const p = encodeURIComponent(teamPin);
                        window.location.href = `/?tch_team_entries=1&export_csv=1&campaign=${c}&q=${q}&team_pin=${p}`;
                    });

                    function esc(s) {
                        const d = document.createElement('div');
                        d.textContent = s;
                        return d.innerHTML;
                    }

                    if (isUnlocked || teamPin) {
                        fetchEntries();
                    }
                });
                </script>
            </body>
            </html>
            <?php
        }

        public function handle_team_portal_ajax() {
            check_ajax_referer( 'tch_team_portal_nonce', 'nonce' );

            if ( ! $this->verify_team_portal_access() ) {
                wp_send_json_error( array( 'message' => 'Invalid PIN.' ) );
            }

            global $wpdb;
            $s          = tch_raffle_get_settings();
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $campaign   = sanitize_text_field( wp_unslash( $_POST['campaign'] ?? $s['campaign_slug'] ) );
            $search     = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );

            $campaigns = $wpdb->get_col( "SELECT DISTINCT campaign_slug FROM `$table_name` ORDER BY id DESC" );
            if ( ! is_array( $campaigns ) ) {
                $campaigns = array();
            }
            if ( ! in_array( $s['campaign_slug'], $campaigns, true ) ) {
                array_unshift( $campaigns, $s['campaign_slug'] );
            }

            $where = array();
            $args  = array();

            if ( ! empty( $campaign ) && 'ALL' !== $campaign ) {
                $where[] = 'campaign_slug = %s';
                $args[]  = $campaign;
            }
            if ( ! empty( $search ) ) {
                $like    = '%' . $wpdb->esc_like( $search ) . '%';
                $where[] = '(ref_code LIKE %s OR full_name LIKE %s OR email LIKE %s OR phone LIKE %s OR quiz_answer LIKE %s)';
                array_push( $args, $like, $like, $like, $like, $like );
            }

            $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
            $sql       = "SELECT id, campaign_slug, ref_code, full_name, email, phone, quiz_answer, status, created_at FROM `$table_name` $where_sql ORDER BY created_at DESC LIMIT 500";
            $raw_rows  = ! empty( $args ) ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

            $entries = array();
            if ( is_array( $raw_rows ) ) {
                foreach ( $raw_rows as $r ) {
                    $r->is_correct = tch_raffle_is_answer_correct( $r->quiz_answer ?? '', $s );
                    $entries[]     = $r;
                }
            }

            $stat_where = ( ! empty( $campaign ) && 'ALL' !== $campaign ) ? $wpdb->prepare( 'WHERE campaign_slug = %s', $campaign ) : '';
            $total_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` $stat_where" );
            if ( ! empty( $s['quiz_enabled'] ) ) {
                $qual_where = $stat_where
                    ? $stat_where . $wpdb->prepare( " AND status = 'valid' AND quiz_answer = %s", $s['quiz_correct'] )
                    : $wpdb->prepare( "WHERE status = 'valid' AND quiz_answer = %s", $s['quiz_correct'] );
                $qual_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` $qual_where" );
            } else {
                $qual_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` " . ( $stat_where ? "$stat_where AND status = 'valid'" : "WHERE status = 'valid'" ) );
            }
            $win_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` " . ( $stat_where ? "$stat_where AND status = 'winner'" : "WHERE status = 'winner'" ) );
            $dq_cnt  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name` " . ( $stat_where ? "$stat_where AND status = 'disqualified'" : "WHERE status = 'disqualified'" ) );

            wp_send_json_success( array(
                'entries'          => $entries,
                'campaigns'        => $campaigns,
                'current_campaign' => $campaign,
                'stats'            => array(
                    'total'     => $total_cnt,
                    'qualified' => $qual_cnt,
                    'winner'    => $win_cnt,
                    'dq'        => $dq_cnt,
                ),
            ) );
        }
    }

    new TCH_Raffle_Stage();
}
