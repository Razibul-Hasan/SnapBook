<?php
defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Register assets & shortcode
───────────────────────────────────────────────────────────── */
add_action('init', 'sb_register_assets');
add_action('wp_enqueue_scripts', 'sb_register_assets');
function sb_register_assets()
{
    wp_register_style(
        'sb-booking-css',
        SB_URL . 'assets/css/booking.css',
        [],
        SB_VER
    );
    wp_register_script(
        'sb-booking-js',
        SB_URL . 'assets/js/booking.js',
        [],
        SB_VER,
        true
    );
}

add_shortcode('snapbook', 'sb_shortcode');
add_shortcode('focus_booking', 'sb_shortcode');
function sb_shortcode($atts)
{
    wp_enqueue_style('sb-booking-css');
    wp_enqueue_script('sb-booking-js');

    $has_wc = class_exists('WooCommerce');
    $local_data = [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('fpb_nonce'),
        'hasWC'      => $has_wc,
        'currency'   => sb_get_currency_symbol(),
        'depositPct' => (int) get_option('fpb_deposit_pct', 50),
        'whatsapp'   => get_option('fpb_whatsapp', ''),
    ];
    wp_localize_script('sb-booking-js', 'fpbData', $local_data);
    wp_localize_script('sb-booking-js', 'sbData', $local_data);

    ob_start();
    sb_render_shortcode();
    return ob_get_clean();
}

function sb_render_shortcode()
{ ?>
    <div class="fpb-wrap">
        <!-- Single-step booking flow -->
        <div class="fstep act" id="fpb-s1">
            <div class="fstep-inner" id="fpb-payWrap">
                <h2 class="ftitle"><?php echo esc_html(get_option('fpb_step1_title', 'Choose Your Package')); ?></h2>
                <p class="fsub"><?php echo esc_html(get_option('fpb_step1_sub', 'Select your preferred date, session type, and the package that suits you best.')); ?></p>

                <!-- Date picker — shown first -->
                <div class="fdatesec" id="fpb-dateSec">
                    <div class="fsec-label">Preferred Session Date</div>
                    <div class="fpb-cal">
                        <div class="fpb-cal-head">
                            <button class="fpb-cal-nav" id="fpb-calPrev" type="button">&#8249;</button>
                            <span id="fpb-calMonth"></span>
                            <button class="fpb-cal-nav" id="fpb-calNext" type="button">&#8250;</button>
                        </div>
                        <div class="fpb-cal-days">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span>
                            <span>Th</span><span>Fr</span><span>Sa</span>
                        </div>
                        <div class="fpb-cal-grid" id="fpb-calGrid"></div>
                    </div>
                    <p id="fpb-selDate" class="fpb-seldate"></p>
                </div>

                <!-- Session type buttons -->
                <div class="fsec-label" style="margin-top:1.6rem">Session Type</div>
                <div class="fpb-stype-wrap" id="fpb-typeTabs">
                    <!-- JS populates one .fpb-stype-btn per session -->
                    <span class="fpb-stype-loading">Loading…</span>
                </div>

                <!-- Package cards (populated by JS) -->
                <div class="fsec-label" style="margin-top:1.6rem">Select Package</div>
                <div class="fpcards" id="fpb-pkgGrid"></div>

                <!-- Add-ons -->
                <div class="fadd" id="fpb-addonsWrap" style="display:none">
                    <div class="fadd-title">Add-ons <span class="fadd-opt">(Optional)</span></div>
                    <div class="fadd-grid" id="fpb-addonsGrid"></div>
                </div>

                <!-- Live Booking Summary -->
                <div class="bsum" id="fpb-s1-summary" style="display:none">
                    <h5>Booking Summary</h5>
                    <div class="sumr"><span>Package</span><span class="v" id="fpb-sum-pkg">—</span></div>
                    <div class="sumr"><span>Add-ons</span><span class="v" id="fpb-sum-addons">—</span></div>
                    <div class="sumt">
                        <div>
                            <div class="sumtl">Total</div>
                            <div class="sumtd" id="fpb-sum-dep"></div>
                        </div>
                        <div class="sumtn" id="fpb-sum-total">—</div>
                    </div>
                </div>

                <div class="fnav">
                    <div></div>
                    <button class="btn bg fpb-checkout-btn" id="fpb-checkoutBtn" onclick="fpb.s1Next()">Proceed to Checkout &#8594;</button>
                </div>
                <p id="fpb-s1err" class="ferr"></p>
                <p id="fpb-checkoutMsg" class="fpb-checkout-msg"></p>
            </div>
            <!-- Success state (fallback email flow) -->
            <div class="suc" id="fpb-sucWrap" style="display:none">
                <div class="fstep-inner suc-inner">
                    <div class="suc-icon">✓</div>
                    <h2 class="ftitle"><?php echo esc_html(get_option('fpb_success_title', 'Booking Requested!')); ?></h2>
                    <p><?php echo esc_html(get_option('fpb_success_msg', "We've received your request and will confirm availability within 24 hours. A confirmation will be sent to")); ?> <strong id="fpb-sucEmail"></strong>.</p>
                    <div class="fnav" style="justify-content:center;margin-top:2rem">
                        <a class="btn bg" id="fpb-waLink" href="#" target="_blank" rel="noopener"><?php echo esc_html(get_option('fpb_whatsapp_btn', 'Message us on WhatsApp')); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
}
