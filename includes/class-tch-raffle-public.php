<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TCH_Raffle_Public {

    public function __construct() {
        add_shortcode( 'tch_raffle_landing', array( $this, 'render_landing_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'wp_ajax_tch_submit_raffle', array( $this, 'handle_ajax_submission' ) );
        add_action( 'wp_ajax_nopriv_tch_submit_raffle', array( $this, 'handle_ajax_submission' ) );
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
        $s     = tch_raffle_get_settings();
        $times = $this->get_schedule_timestamps( $s );

        wp_enqueue_style( 'tch-raffle-frontend' );
        wp_enqueue_script( 'tch-raffle-frontend' );

        wp_localize_script( 'tch-raffle-frontend', 'tchRaffleData', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'tch_raffle_submit_nonce' ),
            'nowTs'     => $times['now'],
            'openTs'    => $times['open'],
            'closeTs'   => $times['close'],
            'eventName' => $s['event_name'],
            'eventDate' => date_i18n( 'jS F Y', strtotime( $s['event_date'] ) ),
            'drawTime'  => date_i18n( 'g:i A', strtotime( $s['live_draw_time'] ) ),
        ) );

        $formatted_date = date_i18n( 'jS F Y', strtotime( $s['event_date'] ) );
        $formatted_draw = date_i18n( 'g:i A', strtotime( $s['live_draw_time'] ) );

        ob_start();
        ?>
        <div class="tch-raffle-wrapper" id="tch-raffle-app">
            <div class="tch-raffle-container">

                <!-- LEFT COLUMN: Brand Showcase, Prize Image & Live Countdown -->
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
                    <p class="tch-subheadline"><?php echo nl2br( esc_html( $s['subheadline'] ) ); ?></p>

                    <div class="tch-prize-stage">
                        <?php if ( ! empty( $s['prize_image_url'] ) ) : ?>
                            <img src="<?php echo esc_url( $s['prize_image_url'] ); ?>" alt="Raffle Prize Bike" class="tch-prize-img" />
                        <?php else : ?>
                            <div class="tch-prize-placeholder">
                                <span>🚲 Upload Prize Bike Image in WP Admin → TCH Raffle</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Countdown Timer Bar -->
                    <div class="tch-countdown-card" id="tch-countdown-box">
                        <div class="tch-countdown-header">
                            <span id="tch-timer-status-label">⏳ REGISTRATION CLOSES IN:</span>
                            <span class="tch-draw-pill">Live Draw: <?php echo esc_html( $formatted_draw ); ?></span>
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
                </div>

                <!-- RIGHT COLUMN: 3D Flip Card (Form Front / VIP Ticket Back) -->
                <div class="tch-interaction-col">
                    <div class="tch-flip-scene" id="tch-flip-scene">
                        <div class="tch-flip-card" id="tch-flip-card">

                            <!-- CARD FRONT: Registration Form -->
                            <div class="tch-card-face tch-card-front">
                                
                                <!-- Closed State Overlay (Hidden unless expired) -->
                                <div class="tch-closed-overlay" id="tch-closed-overlay" style="display:none;">
                                    <div class="tch-closed-icon">🔒</div>
                                    <h3 id="tch-closed-title">Registration is Now Closed</h3>
                                    <p id="tch-closed-msg">The countdown has ended. Stay tuned for the Live Raffle Draw at <?php echo esc_html( $formatted_draw ); ?>!</p>
                                </div>

                                <div class="tch-form-header">
                                    <h2>Claim Your Raffle Ticket</h2>
                                    <p>Enter your details & complete both steps below</p>
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
                                        <span class="tch-notch-left"></span>
                                        <span class="tch-dashed-line"></span>
                                        <span class="tch-notch-right"></span>
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
                                                <small>LIVE DRAW TIME</small>
                                                <strong><?php echo esc_html( $formatted_draw ); ?></strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="tch-ticket-footer">
                                        <p>A copy of this ticket has been sent from <strong><?php echo esc_html( $s['sender_email'] ); ?></strong></p>
                                    </div>
                                </div>
                            </div>

                        </div>
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

        // 1. Check Honeypot
        if ( ! empty( $_POST['tch_hp_website'] ) ) {
            wp_send_json_error( array( 'message' => 'Spam detected.' ) );
        }

        $s     = tch_raffle_get_settings();
        $times = $this->get_schedule_timestamps( $s );

        // 2. Enforce Time-Fencing Server-Side
        if ( $times['now'] > $times['close'] ) {
            wp_send_json_error( array( 'message' => 'Sorry, registration for this raffle draw has officially closed!' ) );
        }

        // 3. Sanitize & Validate Inputs
        $full_name    = sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) );
        $email        = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $raw_phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $phone        = preg_replace( '/[^0-9\+]/', '', $raw_phone );
        $ig_confirmed = ! empty( $_POST['ig_confirmed'] ) ? 1 : 0;
        $yt_confirmed = ! empty( $_POST['yt_confirmed'] ) ? 1 : 0;

        if ( empty( $full_name ) || ! is_email( $email ) || strlen( $phone ) < 7 ) {
            wp_send_json_error( array( 'message' => 'Please enter your full name, a valid email, and a valid WhatsApp/Mobile number.' ) );
        }

        if ( ! $ig_confirmed || ! $yt_confirmed ) {
            wp_send_json_error( array( 'message' => 'Please complete both Instagram and YouTube steps and tick the checkboxes.' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . TCH_RAFFLE_TABLE;

        // 4. Check for Existing Registration by Email or Phone
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ref_code, full_name FROM $table_name WHERE email = %s OR phone = %s LIMIT 1",
                $email,
                $phone
            )
        );

        if ( $existing ) {
            wp_send_json_success( array(
                'ref_code'  => $existing->ref_code,
                'full_name' => $existing->full_name,
                'existing'  => true,
                'message'   => 'You are already registered! Here is your Raffle Ticket.',
            ) );
        }

        // 5. Generate Collision-Free Unique Reference Number
        $prefix   = ! empty( $s['ticket_prefix'] ) ? strtoupper( trim( $s['ticket_prefix'] ) ) : 'TCH-';
        $ref_code = $this->generate_unique_ref( $prefix );

        // 6. Insert Participant
        $inserted = $wpdb->insert(
            $table_name,
            array(
                'ref_code'     => $ref_code,
                'full_name'    => $full_name,
                'email'        => $email,
                'phone'        => $phone,
                'ig_confirmed' => 1,
                'yt_confirmed' => 1,
                'status'       => 'valid',
                'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => 'Could not save your registration. Please try again.' ) );
        }

        // 7. Send Branded Ticket Email from marketing@thecyclehub.com
        $this->send_ticket_email( $full_name, $email, $ref_code, $s );

        wp_send_json_success( array(
            'ref_code'  => $ref_code,
            'full_name' => $full_name,
            'existing'  => false,
        ) );
    }

    /**
     * Generate Unique Alphanumeric Reference Code (e.g. TCH-84K9)
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
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE ref_code = %s", $code ) );
            if ( ! $exists ) {
                return $code;
            }
        }
        return $prefix . strtoupper( substr( uniqid(), -5 ) );
    }

    /**
     * Dispatch HTML Email Scoped to marketing@thecyclehub.com
     */
    private function send_ticket_email( $full_name, $email, $ref_code, $s ) {
        $sender_email = ! empty( $s['sender_email'] ) ? $s['sender_email'] : 'marketing@thecyclehub.com';
        $sender_name  = ! empty( $s['sender_name'] ) ? $s['sender_name'] : 'The Cycle Hub';

        $from_email_cb = function() use ( $sender_email ) { return $sender_email; };
        $from_name_cb  = function() use ( $sender_name ) { return $sender_name; };
        $html_type_cb  = function() { return 'text/html'; };

        add_filter( 'wp_mail_from', $from_email_cb, 999 );
        add_filter( 'wp_mail_from_name', $from_name_cb, 999 );
        add_filter( 'wp_mail_content_type', $html_type_cb, 999 );

        $subject        = '🎟️ Your Raffle Ticket (' . $ref_code . ') - ' . $s['event_name'];
        $formatted_date = date_i18n( 'jS F Y', strtotime( $s['event_date'] ) );
        $formatted_draw = date_i18n( 'g:i A', strtotime( $s['live_draw_time'] ) );

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
                        <tr>
                            <td style="padding:6px 0;color:#94a3b8;">⏰ Live Draw Time:</td>
                            <td style="padding:6px 0;text-align:right;font-weight:600;color:#eab308;">' . esc_html( $formatted_draw ) . '</td>
                        </tr>
                    </table>
                    <p style="font-size:13px;color:#94a3b8;margin-top:25px;line-height:1.5;">
                        Make sure you have followed <a href="' . esc_url( $s['ig_url'] ) . '" style="color:#eab308;">@thecyclehub on Instagram</a> (with your bike selfie tagged) and subscribed to our <a href="' . esc_url( $s['yt_url'] ) . '" style="color:#eab308;">YouTube Channel</a> so your ticket passes verification during the live draw!
                    </p>
                </div>
                <div style="background:#0f172a;padding:14px;text-align:center;font-size:12px;color:#64748b;">
                    &copy; ' . date( 'Y' ) . ' The Cycle Hub Dubai • <a href="https://thecyclehub.com" style="color:#94a3b8;text-decoration:none;">thecyclehub.com</a>
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
