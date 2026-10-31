<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'TCH_Raffle_Public' ) ) {

    class TCH_Raffle_Public {

        public function __construct() {
            add_shortcode( 'tch_raffle_landing', array( $this, 'render_landing_shortcode' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
            add_action( 'wp_ajax_tch_submit_raffle', array( $this, 'handle_ajax_submission' ) );
            add_action( 'wp_ajax_nopriv_tch_submit_raffle', array( $this, 'handle_ajax_submission' ) );
            add_action( 'wp_ajax_tch_check_ticket_status', array( $this, 'handle_ticket_lookup' ) );
            add_action( 'wp_ajax_nopriv_tch_check_ticket_status', array( $this, 'handle_ticket_lookup' ) );
        }

        /**
         * Register CSS & JS (Loaded only when shortcode is present)
         */
        public function register_assets() {
            wp_register_style(
                'tch-raffle-frontend',
                TCH_RAFFLE_URL . 'assets/css/tch-raffle-frontend.css',
                array(),
                TCH_RAFFLE_VERSION
            );

            wp_register_script(
                'tch-raffle-frontend',
                TCH_RAFFLE_URL . 'assets/js/tch-raffle-frontend.js',
                array(),
                TCH_RAFFLE_VERSION,
                true
            );
        }

        /**
         * Calculate exact Unix timestamps in WP Timezone (Dubai UTC+4)
         */
        private function get_schedule_timestamps( $s ) {
            $tz = wp_timezone();
            try {
                $open_dt  = new DateTime( $s['event_date'] . ' ' . $s['open_time'] . ':00', $tz );
                $close_dt = new DateTime( $s['event_date'] . ' ' . $s['close_time'] . ':00', $tz );
                return array(
                    'now'   => time(),
                    'open'  => $open_dt->getTimestamp(),
                    'close' => $close_dt->getTimestamp(),
                );
            } catch ( Exception $e ) {
                return array(
                    'now'   => time(),
                    'open'  => time() - 3600,
                    'close' => time() + 3600,
                );
            }
        }

        /**
         * Render [tch_raffle_landing] Shortcode
         */
        public function render_landing_shortcode() {
            global $wpdb;
            $s            = tch_raffle_get_settings();
            $times        = $this->get_schedule_timestamps( $s );
            $table_name   = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $c_slug       = $s['campaign_slug'];
            $stage_url    = home_url( '/?tch_live_stage=1' );
            $quiz_enabled = ! empty( $s['quiz_enabled'] );
            $quiz_options = tch_raffle_get_quiz_options_array( $s );

            // Check if Official Winner is Confirmed for this Active Event
            $winners = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ref_code, full_name, quiz_answer FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner' ORDER BY id DESC",
                    $c_slug
                )
            );
            $has_winner = ! empty( $winners );

            $formatted_date      = date_i18n( 'jS F Y', strtotime( $s['event_date'] ) );
            $draw_date_raw       = ! empty( $s['live_draw_date'] ) ? $s['live_draw_date'] : $s['event_date'];
            $formatted_draw_date = date_i18n( 'jS M Y', strtotime( $draw_date_raw ) );
            $formatted_draw_time = date_i18n( 'g:i A', strtotime( $s['live_draw_time'] ) );
            $draw_full_label     = $formatted_draw_date . ' @ ' . $formatted_draw_time;

            wp_enqueue_style( 'tch-raffle-frontend' );
            wp_enqueue_script( 'tch-raffle-frontend' );

            wp_localize_script( 'tch-raffle-frontend', 'tchRaffleData', array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'stageNonce'  => wp_create_nonce( 'tch_stage_nonce' ),
                'nonce'       => wp_create_nonce( 'tch_raffle_submit_nonce' ),
                'nowTs'       => $times['now'],
                'openTs'      => $times['open'],
                'closeTs'     => $times['close'],
                'hasWinner'   => $has_winner,
                'quizEnabled' => $quiz_enabled,
                'stageUrl'    => $stage_url,
                'eventName'   => $s['event_name'],
                'eventDate'   => $formatted_date,
                'drawLabel'   => $draw_full_label,
            ) );

            ob_start();
            ?>
            <div class="tch-raffle-wrapper" id="tch-raffle-app">
                <div class="tch-raffle-container">

                    <!-- LEFT COLUMN: Brand Showcase, Prize Bike & Countdown -->
                    <div class="tch-showcase-col">
                        <div class="tch-event-badges">
                            <span class="tch-badge tch-badge-live">
                                <span class="tch-pulse-dot"></span> <?php echo esc_html( $s['event_name'] ); ?>
                            </span>
                            <span class="tch-badge tch-badge-venue">
                                📍 <?php echo esc_html( $s['event_place'] ); ?> • <?php echo esc_html( $formatted_date ); ?>
                            </span>
                        </div>

                        <h1 class="tch-main-headline"><?php echo esc_html( $s['headline'] ); ?></h1>
                        <p class="tch-subheadline">
                            <?php
                            if ( $has_winner ) {
                                echo esc_html( 'The raffle draw for ' . $s['event_name'] . ' has officially concluded. View the winning ticket result!' );
                            } else {
                                echo nl2br( esc_html( $s['subheadline'] ) );
                            }
                            ?>
                        </p>

                        <div class="tch-prize-stage">
                            <?php if ( ! empty( $s['prize_image_url'] ) ) : ?>
                                <img src="<?php echo esc_url( $s['prize_image_url'] ); ?>" alt="Raffle Prize Bike" class="tch-prize-img" />
                            <?php else : ?>
                                <div class="tch-prize-placeholder">
                                    <span>🚲 Upload Prize Bike Image in WP Admin → TCH Raffle</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ( ! $has_winner ) : ?>
                            <!-- Countdown Timer Bar -->
                            <div class="tch-countdown-card" id="tch-countdown-box">
                                <div class="tch-countdown-header">
                                    <span id="tch-timer-status-label">⏳ REGISTRATION CLOSES IN:</span>
                                    <span class="tch-draw-pill">Draw: <?php echo esc_html( $draw_full_label ); ?></span>
                                </div>
                                <div class="tch-countdown-grid" id="tch-timer-digits">
                                    <div class="tch-time-unit"><span id="tch-cd-days">00</span><small>Days</small></div>
                                    <div class="tch-time-sep">:</div>
                                    <div class="tch-time-unit"><span id="tch-cd-hours">00</span><small>Hours</small></div>
                                    <div class="tch-time-sep">:</div>
                                    <div class="tch-time-unit"><span id="tch-cd-mins">00</span><small>Mins</small></div>
                                    <div class="tch-time-sep">:</div>
                                    <div class="tch-time-unit"><span id="tch-cd-secs">00</span><small>Secs</small></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- RIGHT COLUMN: Either Official Draw Result Card OR Registration Form -->
                    <div class="tch-interaction-col">

                        <!-- =========================================================
                             STATE A: OFFICIAL WINNER RESULT CARD
                             ========================================================= -->
                        <div id="tch-final-winner-card" class="tch-final-winner-card" style="display:<?php echo $has_winner ? 'block' : 'none'; ?>;">
                            <div class="tch-fw-badge">🏁 RAFFLE DRAW COMPLETED</div>
                            <div class="tch-fw-trophy">🏆</div>
                            <h2 class="tch-fw-title">Official Raffle Winner</h2>
                            <p class="tch-fw-sub"><?php echo esc_html( $s['event_name'] . ' • ' . $s['event_place'] ); ?></p>

                            <div id="tch-fw-winners-list">
                                <?php if ( $has_winner ) : ?>
                                    <?php foreach ( $winners as $w ) : ?>
                                        <div class="tch-fw-ticket-box">
                                            <span class="tch-fw-ref-label">WINNING TICKET REFERENCE</span>
                                            <div class="tch-fw-ref-code"><?php echo esc_html( $w->ref_code ); ?></div>
                                            <div class="tch-fw-winner-name">🎉 <?php echo esc_html( $w->full_name ); ?></div>
                                            <?php if ( ! empty( $w->quiz_answer ) ) : ?>
                                                <div style="margin-top:8px;font-size:12px;color:#86efac;font-weight:700;">
                                                    ✅ Correct Answer: <?php echo esc_html( $w->quiz_answer ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <p class="tch-fw-footer-note">Thank you to everyone who visited The Cycle Hub booth and participated in the giveaway!</p>
                        </div>

                        <?php if ( ! $has_winner ) : ?>
                            <!-- =========================================================
                                 STATE B: REGISTRATION FORM & 3D FLIP VIP TICKET
                                 ========================================================= -->
                            <div class="tch-flip-scene" id="tch-flip-scene">
                                <div class="tch-flip-card" id="tch-flip-card">

                                    <!-- CARD FRONT: Registration Form -->
                                    <div class="tch-card-face tch-card-front">
                                        
                                        <!-- Closed State Overlay -->
                                        <div class="tch-closed-overlay" id="tch-closed-overlay" style="display:none;">
                                            <div class="tch-closed-icon">🎰</div>
                                            <h3 id="tch-closed-title">Registration is Now Closed!</h3>
                                            <p id="tch-closed-msg">
                                                Registration has ended. Join the Live Raffle Draw broadcast scheduled for <strong><?php echo esc_html( $draw_full_label ); ?></strong>!
                                            </p>
                                            <a href="<?php echo esc_url( $stage_url ); ?>" id="tch-watch-live-btn" class="tch-submit-btn tch-watch-live-btn" style="margin-top:18px;text-decoration:none;display:inline-block;">
                                                🔴 Watch Live Raffle Draw Now ↗
                                            </a>
                                        </div>

                                        <div class="tch-form-header">
                                            <h2>Claim Your Raffle Ticket</h2>
                                            <p>Enter your details, guess the tyre pressure & complete both steps</p>
                                        </div>

                                        <form id="tch-raffle-form" novalidate>
                                            <!-- Anti-bot Honeypot -->
                                            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                                <input type="text" name="tch_hp_website" tabindex="-1" autocomplete="off">
                                            </div>

                                            <div class="tch-field-group">
                                                <label for="tch_full_name">Full Name <span>*</span></label>
                                                <input type="text" id="tch_full_name" name="full_name" placeholder="e.g. John Meyer" required autocomplete="name">
                                            </div>

                                            <div class="tch-field-row">
                                                <div class="tch-field-group">
                                                    <label for="tch_email">Email Address <span>*</span></label>
                                                    <input type="email" id="tch_email" name="email" placeholder="you@example.com" required autocomplete="email">
                                                </div>
                                                <div class="tch-field-group">
                                                    <label for="tch_phone">Mobile / WhatsApp <span>*</span></label>
                                                    <input type="tel" id="tch_phone" name="phone" placeholder="+971 50 123 4567" required autocomplete="tel">
                                                </div>
                                            </div>

                                            <?php if ( $quiz_enabled && ! empty( $quiz_options ) ) : ?>
                                                <!-- SKILL QUESTION BOX: Guess the Tyre Pressure? -->
                                                <div class="tch-quiz-card">
                                                    <div class="tch-quiz-badge">🚲 SKILL CHALLENGE — QUALIFY FOR THE DRAW</div>
                                                    <label for="tch_quiz_answer" class="tch-quiz-label">
                                                        <?php echo esc_html( $s['quiz_question'] ); ?> <span>*</span>
                                                    </label>
                                                    <div class="tch-select-wrap">
                                                        <select id="tch_quiz_answer" name="quiz_answer" required>
                                                            <option value="">— Select Your Answer —</option>
                                                            <?php foreach ( $quiz_options as $opt ) : ?>
                                                                <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Interactive Social Checkboxes -->
                                            <div class="tch-social-steps">
                                                <div class="tch-step-box">
                                                    <label class="tch-checkbox-label">
                                                        <input type="checkbox" name="ig_confirmed" id="tch_ig_check" value="1" required>
                                                        <span class="tch-custom-check"></span>
                                                        <span class="tch-check-text"><?php echo esc_html( $s['ig_label'] ); ?></span>
                                                    </label>
                                                    <a href="<?php echo esc_url( $s['ig_url'] ); ?>" target="_blank" rel="noopener" class="tch-social-btn tch-ig-btn">
                                                        📸 Open Instagram ↗
                                                    </a>
                                                </div>

                                                <div class="tch-step-box">
                                                    <label class="tch-checkbox-label">
                                                        <input type="checkbox" name="yt_confirmed" id="tch_yt_check" value="1" required>
                                                        <span class="tch-custom-check"></span>
                                                        <span class="tch-check-text"><?php echo esc_html( $s['yt_label'] ); ?></span>
                                                    </label>
                                                    <a href="<?php echo esc_url( $s['yt_url'] ); ?>" target="_blank" rel="noopener" class="tch-social-btn tch-yt-btn">
                                                        ▶️ Subscribe YouTube ↗
                                                    </a>
                                                </div>
                                            </div>

                                            <div id="tch-form-feedback" class="tch-form-feedback" style="display:none;"></div>

                                            <button type="submit" id="tch-submit-btn" class="tch-submit-btn">
                                                <span class="tch-btn-text">Get My Raffle Ticket 🎟️</span>
                                                <span class="tch-btn-loader" style="display:none;">Generating Ticket...</span>
                                            </button>

                                            <div class="tch-form-footer">
                                                <span>By registering, you agree to the </span>
                                                <button type="button" id="tch-open-terms" class="tch-link-btn">Raffle Terms & Conditions</button>
                                            </div>
                                        </form>
                                    </div>

                                    <!-- CARD BACK: 3D Flip VIP Raffle Ticket -->
                                    <div class="tch-card-face tch-card-back">
                                        <div class="tch-vip-ticket">
                                            <div class="tch-ticket-top">
                                                <span class="tch-ticket-tag">OFFICIAL RAFFLE DRAW ENTRY</span>
                                                <h3><?php echo esc_html( $s['event_name'] ); ?></h3>
                                                <p><?php echo esc_html( $s['event_place'] ); ?> • <?php echo esc_html( $formatted_date ); ?></p>
                                            </div>

                                            <div class="tch-ticket-divider">
                                                <span class="tch-dashed-line"></span>
                                            </div>

                                            <div class="tch-ticket-body">
                                                <span class="tch-ref-label">YOUR UNIQUE REFERENCE NUMBER</span>
                                                <div class="tch-ref-code" id="tch-ticket-ref">TCH-0000</div>
                                                <p class="tch-screenshot-hint">📸 Screenshot this ticket & check your email!</p>

                                                <div class="tch-ticket-meta">
                                                    <div>
                                                        <small>PARTICIPANT</small>
                                                        <strong id="tch-ticket-name">-</strong>
                                                    </div>
                                                    <div>
                                                        <small>YOUR ANSWER</small>
                                                        <strong id="tch-ticket-answer" style="color:#facc15;">-</strong>
                                                    </div>
                                                </div>

                                                <a href="<?php echo esc_url( $stage_url ); ?>" id="tch-ticket-live-link" class="tch-live-stage-link-btn">
                                                    📡 Live Draw Broadcast (<?php echo esc_html( $draw_full_label ); ?>) ↗
                                                </a>
                                            </div>

                                            <div class="tch-ticket-footer">
                                                <p>Sent from <strong><?php echo esc_html( $s['sender_email'] ); ?></strong></p>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Compact Participant Ticket Lookup & Result Verification Bar -->
                        <div class="tch-lookup-card">
                            <div class="tch-lookup-header">
                                <span>🔍 Verify Your Ticket Status:</span>
                            </div>
                            <form id="tch-lookup-form" class="tch-lookup-form">
                                <input type="text" id="tch-lookup-input" placeholder="Enter Ticket ID (e.g. TCH-84K9) or Email..." required>
                                <button type="submit" id="tch-lookup-btn">Check</button>
                            </form>
                            <div id="tch-lookup-result" class="tch-lookup-result" style="display:none;"></div>
                        </div>

                    </div>

                </div>

                <!-- Terms & Conditions Slide-Up Modal -->
                <div class="tch-modal-backdrop" id="tch-terms-modal" aria-hidden="true">
                    <div class="tch-modal-sheet">
                        <div class="tch-modal-header">
                            <h3>Raffle Details & Terms and Conditions</h3>
                            <button type="button" id="tch-close-terms" class="tch-modal-close">&times;</button>
                        </div>
                        <div class="tch-modal-body">
                            <?php echo wp_kses_post( wpautop( $s['terms_content'] ) ); ?>
                        </div>
                        <div class="tch-modal-footer">
                            <button type="button" id="tch-accept-terms" class="tch-submit-btn">Got It, Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Handle Frontend Form AJAX Submission
         */
        public function handle_ajax_submission() {
            check_ajax_referer( 'tch_raffle_submit_nonce', 'nonce' );

            if ( ! empty( $_POST['tch_hp_website'] ) ) {
                wp_send_json_error( array( 'message' => 'Spam detected.' ) );
            }

            global $wpdb;
            $s          = tch_raffle_get_settings();
            $c_slug     = $s['campaign_slug'];
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $times      = $this->get_schedule_timestamps( $s );

            $winner_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner'", $c_slug ) );
            if ( $winner_exists > 0 || $times['now'] > $times['close'] ) {
                wp_send_json_error( array( 'message' => 'Sorry, registration for this raffle draw has officially closed!' ) );
            }

            $full_name    = sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) );
            $email        = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
            $raw_phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
            $phone        = preg_replace( '/[^0-9\+]/', '', $raw_phone );
            $quiz_answer  = sanitize_text_field( wp_unslash( $_POST['quiz_answer'] ?? '' ) );
            $ig_confirmed = ! empty( $_POST['ig_confirmed'] ) ? 1 : 0;
            $yt_confirmed = ! empty( $_POST['yt_confirmed'] ) ? 1 : 0;

            if ( empty( $full_name ) || ! is_email( $email ) || strlen( $phone ) < 7 ) {
                wp_send_json_error( array( 'message' => 'Please enter your full name, a valid email, and a valid WhatsApp/Mobile number.' ) );
            }

            if ( ! empty( $s['quiz_enabled'] ) && empty( $quiz_answer ) ) {
                wp_send_json_error( array( 'message' => 'Please select your answer for "' . $s['quiz_question'] . '".' ) );
            }

            if ( ! $ig_confirmed || ! $yt_confirmed ) {
                wp_send_json_error( array( 'message' => 'Please complete both Instagram and YouTube steps and tick the checkboxes.' ) );
            }

            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ref_code, full_name, quiz_answer FROM `$table_name` WHERE campaign_slug = %s AND (email = %s OR phone = %s) LIMIT 1",
                    $c_slug,
                    $email,
                    $phone
                )
            );

            if ( $existing ) {
                wp_send_json_success( array(
                    'ref_code'    => $existing->ref_code,
                    'full_name'   => $existing->full_name,
                    'quiz_answer' => $existing->quiz_answer ?: $quiz_answer,
                    'existing'    => true,
                    'message'     => 'You are already registered for this event! Here is your Raffle Ticket.',
                ) );
            }

            $prefix   = ! empty( $s['ticket_prefix'] ) ? strtoupper( trim( $s['ticket_prefix'] ) ) : 'TCH-';
            $ref_code = $this->generate_unique_ref( $prefix );

            $inserted = $wpdb->insert(
                $table_name,
                array(
                    'campaign_slug' => $c_slug,
                    'ref_code'      => $ref_code,
                    'full_name'     => $full_name,
                    'email'         => $email,
                    'phone'         => $phone,
                    'quiz_answer'   => $quiz_answer,
                    'ig_confirmed'  => 1,
                    'yt_confirmed'  => 1,
                    'status'        => 'valid',
                    'ip_address'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
                    'created_at'    => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
            );

            if ( ! $inserted ) {
                wp_send_json_error( array( 'message' => 'Could not save your registration. Please try again.' ) );
            }

            $this->send_ticket_email( $full_name, $email, $ref_code, $quiz_answer, $s );

            wp_send_json_success( array(
                'ref_code'    => $ref_code,
                'full_name'   => $full_name,
                'quiz_answer' => $quiz_answer,
                'existing'    => false,
            ) );
        }

        /**
         * Handle Public Ticket Lookup & Result Verification
         */
        public function handle_ticket_lookup() {
            check_ajax_referer( 'tch_raffle_submit_nonce', 'nonce' );

            global $wpdb;
            $s          = tch_raffle_get_settings();
            $c_slug     = $s['campaign_slug'];
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $query      = sanitize_text_field( wp_unslash( $_POST['lookup_query'] ?? '' ) );

            if ( empty( $query ) ) {
                wp_send_json_error( array( 'message' => 'Please enter your Ticket Reference ID or Email.' ) );
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ref_code, full_name, quiz_answer, status FROM `$table_name` WHERE campaign_slug = %s AND (ref_code = %s OR email = %s) LIMIT 1",
                    $c_slug,
                    strtoupper( $query ),
                    $query
                )
            );

            if ( ! $row ) {
                wp_send_json_error( array( 'message' => 'No ticket found matching "' . esc_html( $query ) . '" for ' . esc_html( $s['event_name'] ) . '.' ) );
            }

            // Check if draw has already finished
            $has_winner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table_name` WHERE campaign_slug = %s AND status = 'winner'", $c_slug ) ) > 0;
            $ans_text   = ! empty( $row->quiz_answer ) ? ' (Answer: ' . $row->quiz_answer . ')' : '';

            if ( 'winner' === $row->status ) {
                $status_label = '🏆 CONGRATULATIONS! OFFICIAL WINNER!' . $ans_text;
            } elseif ( 'disqualified' === $row->status ) {
                $status_label = '❌ Disqualified during social verification' . $ans_text;
            } elseif ( $has_winner && ! empty( $s['quiz_enabled'] ) ) {
                // Only reveal whether their tyre pressure guess was right or wrong AFTER the winner is drawn
                $is_corr      = tch_raffle_is_answer_correct( $row->quiz_answer, $s );
                $status_label = $is_corr
                    ? '✅ Qualified with Correct Answer' . $ans_text
                    : '❌ Did Not Qualify — Correct Answer was ' . $s['quiz_correct'] . $ans_text;
            } else {
                $status_label = '🎟️ Registered' . $ans_text;
            }

            wp_send_json_success( array(
                'ref_code'     => $row->ref_code,
                'full_name'    => $row->full_name,
                'status'       => $row->status,
                'status_label' => $status_label,
            ) );
        }

        /**
         * Generate Unique Alphanumeric Reference Code
         */
        private function generate_unique_ref( $prefix ) {
            global $wpdb;
            $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;
            $chars      = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

            for ( $attempt = 0; $attempt < 10; $attempt++ ) {
                $random = '';
                for ( $i = 0; $i < 5; $i++ ) {
                    $random .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
                }
                $code   = $prefix . $random;
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table_name` WHERE ref_code = %s", $code ) );
                if ( ! $exists ) {
                    return $code;
                }
            }
            return $prefix . strtoupper( substr( uniqid(), -5 ) );
        }

        /**
         * Dispatch HTML Email Scoped to marketing@thecyclehub.com
         */
        private function send_ticket_email( $full_name, $email, $ref_code, $quiz_answer, $s ) {
            $sender_email = ! empty( $s['sender_email'] ) ? $s['sender_email'] : 'marketing@thecyclehub.com';
            $sender_name  = ! empty( $s['sender_name'] ) ? $s['sender_name'] : 'The Cycle Hub';

            $from_email_cb = function() use ( $sender_email ) { return $sender_email; };
            $from_name_cb  = function() use ( $sender_name ) { return $sender_name; };
            $html_type_cb  = function() { return 'text/html'; };

            add_filter( 'wp_mail_from', $from_email_cb, 999 );
            add_filter( 'wp_mail_from_name', $from_name_cb, 999 );
            add_filter( 'wp_mail_content_type', $html_type_cb, 999 );

            $subject             = '🎟️ Your Raffle Ticket (' . $ref_code . ') - ' . $s['event_name'];
            $formatted_date      = date_i18n( 'jS F Y', strtotime( $s['event_date'] ) );
            $draw_date_raw       = ! empty( $s['live_draw_date'] ) ? $s['live_draw_date'] : $s['event_date'];
            $formatted_draw_date = date_i18n( 'jS M Y', strtotime( $draw_date_raw ) );
            $formatted_draw_time = date_i18n( 'g:i A', strtotime( $s['live_draw_time'] ) );
            $stage_url           = home_url( '/?tch_live_stage=1' );

            $quiz_row_html = '';
            if ( ! empty( $quiz_answer ) ) {
                $quiz_row_html = '
                <tr>
                    <td style="padding:6px 0;color:#94a3b8;">🚲 ' . esc_html( $s['quiz_question'] ) . '</td>
                    <td style="padding:6px 0;text-align:right;font-weight:700;color:#facc15;">' . esc_html( $quiz_answer ) . '</td>
                </tr>';
            }

            $body = '
            <div style="background-color:#0f172a;padding:35px 15px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#f8fafc;">
                <div style="max-width:520px;margin:0 auto;background:#1e293b;border-radius:16px;overflow:hidden;border:1px solid #334155;">
                    <div style="background:linear-gradient(135deg,#eab308 0%,#ca8a04 100%);padding:20px;text-align:center;color:#0f172a;">
                        <div style="font-size:12px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;">Official Raffle Draw Ticket</div>
                        <h2 style="margin:6px 0 0;font-size:22px;font-weight:900;">' . esc_html( $s['event_name'] ) . '</h2>
                    </div>
                    <div style="padding:30px 25px;text-align:center;">
                        <p style="margin:0 0 15px;font-size:16px;color:#cbd5e1;">Hi <strong>' . esc_html( $full_name ) . '</strong>, your entry is confirmed!</p>
                        <div style="background:#0f172a;border:2px dashed #eab308;border-radius:12px;padding:20px;margin:20px 0;">
                            <div style="font-size:11px;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:6px;">Your Reference Number</div>
                            <div style="font-size:34px;font-weight:900;color:#eab308;letter-spacing:3px;font-family:monospace;">' . esc_html( $ref_code ) . '</div>
                        </div>
                        <table style="width:100%;margin-top:20px;border-top:1px solid #334155;padding-top:15px;font-size:14px;color:#cbd5e1;text-align:left;">
                            <tr>
                                <td style="padding:6px 0;color:#94a3b8;">📍 Location:</td>
                                <td style="padding:6px 0;text-align:right;font-weight:600;">' . esc_html( $s['event_place'] ) . '</td>
                            </tr>
                            <tr>
                                <td style="padding:6px 0;color:#94a3b8;">📅 Event Date:</td>
                                <td style="padding:6px 0;text-align:right;font-weight:600;">' . esc_html( $formatted_date ) . '</td>
                            </tr>
                            ' . $quiz_row_html . '
                            <tr>
                                <td style="padding:6px 0;color:#94a3b8;">⏰ Live Draw Schedule:</td>
                                <td style="padding:6px 0;text-align:right;font-weight:600;color:#eab308;">' . esc_html( $formatted_draw_date . ' @ ' . $formatted_draw_time ) . '</td>
                            </tr>
                        </table>
                        <div style="margin-top:22px;">
                            <a href="' . esc_url( $stage_url ) . '" style="display:inline-block;background:#eab308;color:#0f172a;font-weight:800;padding:12px 22px;border-radius:10px;text-decoration:none;font-size:14px;">
                                📡 Watch Live Raffle Draw Online ↗
                            </a>
                        </div>
                        <p style="font-size:13px;color:#94a3b8;margin-top:22px;line-height:1.5;">
                            Make sure you have followed <a href="' . esc_url( $s['ig_url'] ) . '" style="color:#eab308;">@thecyclehub on Instagram</a> (with your bike selfie tagged) and subscribed to our <a href="' . esc_url( $s['yt_url'] ) . '" style="color:#eab308;">YouTube Channel</a> so your ticket passes verification during the live draw!
                        </p>
                    </div>
                    <div style="background:#0f172a;padding:14px;text-align:center;font-size:12px;color:#64748b;">
                        &copy; ' . gmdate( 'Y' ) . ' The Cycle Hub Dubai • <a href="https://thecyclehub.com" style="color:#94a3b8;text-decoration:none;">thecyclehub.com</a>
                    </div>
                </div>
            </div>';

            wp_mail( $email, $subject, $body );

            remove_filter( 'wp_mail_from', $from_email_cb, 999 );
            remove_filter( 'wp_mail_from_name', $from_name_cb, 999 );
            remove_filter( 'wp_mail_content_type', $html_type_cb, 999 );
        }
    }

    new TCH_Raffle_Public();
}
