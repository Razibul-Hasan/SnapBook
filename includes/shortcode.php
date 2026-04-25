<?php
defined('ABSPATH') || exit;

/* ─────────────────────────────────────────────────────────────
   Register assets & shortcode
───────────────────────────────────────────────────────────── */
add_action('wp_enqueue_scripts', 'fpb_register_assets');
function fpb_register_assets()
{
    wp_register_style(
        'fpb-booking-css',
        FPB_URL . 'assets/css/booking.css',
        [],
        FPB_VER
    );
    wp_register_script(
        'fpb-booking-js',
        FPB_URL . 'assets/js/booking.js',
        [],
        FPB_VER,
        true
    );
}

add_shortcode('focus_booking', 'fpb_shortcode');
function fpb_shortcode($atts)
{
    wp_enqueue_style('fpb-booking-css');
    wp_enqueue_script('fpb-booking-js');

    $has_wc = class_exists('WooCommerce');
    wp_localize_script('fpb-booking-js', 'fpbData', [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('fpb_nonce'),
        'hasWC'      => $has_wc,
        'currency'   => get_option('fpb_currency_sym', '€'),
        'depositPct' => (int) get_option('fpb_deposit_pct', 50),
        'whatsapp'   => get_option('fpb_whatsapp', ''),
    ]);

    ob_start();
    fpb_render_shortcode();
    return ob_get_clean();
}

function fpb_render_shortcode()
{ ?>
    <div class="fpb-wrap">
        <!-- ── Step indicator ── -->
        <div class="fsteps" id="fpb-steps">
            <div class="fsp act" id="fpb-sp1"><span>1</span><?php echo esc_html(get_option('fpb_step1_label', 'Package')); ?></div>
            <div class="fsp" id="fpb-sp2"><span>2</span><?php echo esc_html(get_option('fpb_step2_label', 'Details')); ?></div>
            <div class="fsp" id="fpb-sp3"><span>3</span><?php echo esc_html(get_option('fpb_step3_label', 'Contract')); ?></div>
            <div class="fsp" id="fpb-sp4"><span>4</span><?php echo esc_html(get_option('fpb_step4_label', 'Payment')); ?></div>
        </div>

        <!-- ╔══════════════════════════════════════════════╗
       ║  STEP 1 — Choose Package                     ║
       ╚══════════════════════════════════════════════╝ -->
        <div class="fstep act" id="fpb-s1">
            <div class="fstep-inner">
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
                    <button class="btn bg" onclick="fpb.s1Next()">Continue &#8594;</button>
                </div>
                <p id="fpb-s1err" class="ferr"></p>
            </div>
        </div>

        <!-- ╔══════════════════════════════════════════════╗
       ║  STEP 2 — Client Details                     ║
       ╚══════════════════════════════════════════════╝ -->
        <div class="fstep" id="fpb-s2">
            <div class="fstep-inner">
                <h2 class="ftitle"><?php echo esc_html(get_option('fpb_step2_title', 'Your Details')); ?></h2>
                <p class="fsub"><?php echo esc_html(get_option('fpb_step2_sub', 'All fields are required.')); ?></p>

                <div class="fgrid2">
                    <div class="ffield">
                        <label for="fpb-fname">Full Name <span class="req">*</span></label>
                        <input type="text" id="fpb-fname" autocomplete="name" />
                    </div>
                    <div class="ffield">
                        <label for="fpb-femail">Email Address <span class="req">*</span></label>
                        <input type="email" id="fpb-femail" autocomplete="email" />
                    </div>
                    <div class="ffield">
                        <label for="fpb-fphone">WhatsApp / Phone <span class="req">*</span></label>
                        <input type="tel" id="fpb-fphone" autocomplete="tel" placeholder="+1 234 567 8900" pattern="[+]?[0-9 \-()]{7,20}" maxlength="20" />
                    </div>
                    <div class="ffield">
                        <label for="fpb-fcountry">Country <span class="req">*</span></label>
                        <select id="fpb-fcountry" autocomplete="country-name">
                            <option value="">— Select country —</option>
                            <option>Afghanistan</option>
                            <option>Albania</option>
                            <option>Algeria</option>
                            <option>Andorra</option>
                            <option>Angola</option>
                            <option>Argentina</option>
                            <option>Armenia</option>
                            <option>Australia</option>
                            <option>Austria</option>
                            <option>Azerbaijan</option>
                            <option>Bahamas</option>
                            <option>Bahrain</option>
                            <option>Bangladesh</option>
                            <option>Barbados</option>
                            <option>Belarus</option>
                            <option>Belgium</option>
                            <option>Belize</option>
                            <option>Benin</option>
                            <option>Bhutan</option>
                            <option>Bolivia</option>
                            <option>Bosnia and Herzegovina</option>
                            <option>Botswana</option>
                            <option>Brazil</option>
                            <option>Brunei</option>
                            <option>Bulgaria</option>
                            <option>Burkina Faso</option>
                            <option>Burundi</option>
                            <option>Cambodia</option>
                            <option>Cameroon</option>
                            <option>Canada</option>
                            <option>Cape Verde</option>
                            <option>Central African Republic</option>
                            <option>Chad</option>
                            <option>Chile</option>
                            <option>China</option>
                            <option>Colombia</option>
                            <option>Comoros</option>
                            <option>Congo</option>
                            <option>Costa Rica</option>
                            <option>Croatia</option>
                            <option>Cuba</option>
                            <option>Cyprus</option>
                            <option>Czech Republic</option>
                            <option>Denmark</option>
                            <option>Djibouti</option>
                            <option>Dominican Republic</option>
                            <option>Ecuador</option>
                            <option>Egypt</option>
                            <option>El Salvador</option>
                            <option>Equatorial Guinea</option>
                            <option>Eritrea</option>
                            <option>Estonia</option>
                            <option>Eswatini</option>
                            <option>Ethiopia</option>
                            <option>Fiji</option>
                            <option>Finland</option>
                            <option>France</option>
                            <option>Gabon</option>
                            <option>Gambia</option>
                            <option>Georgia</option>
                            <option>Germany</option>
                            <option>Ghana</option>
                            <option>Greece</option>
                            <option>Guatemala</option>
                            <option>Guinea</option>
                            <option>Guyana</option>
                            <option>Haiti</option>
                            <option>Honduras</option>
                            <option>Hungary</option>
                            <option>Iceland</option>
                            <option>India</option>
                            <option>Indonesia</option>
                            <option>Iran</option>
                            <option>Iraq</option>
                            <option>Ireland</option>
                            <option>Israel</option>
                            <option>Italy</option>
                            <option>Ivory Coast</option>
                            <option>Jamaica</option>
                            <option>Japan</option>
                            <option>Jordan</option>
                            <option>Kazakhstan</option>
                            <option>Kenya</option>
                            <option>Kuwait</option>
                            <option>Kyrgyzstan</option>
                            <option>Laos</option>
                            <option>Latvia</option>
                            <option>Lebanon</option>
                            <option>Lesotho</option>
                            <option>Liberia</option>
                            <option>Libya</option>
                            <option>Liechtenstein</option>
                            <option>Lithuania</option>
                            <option>Luxembourg</option>
                            <option>Madagascar</option>
                            <option>Malawi</option>
                            <option>Malaysia</option>
                            <option>Maldives</option>
                            <option>Mali</option>
                            <option>Malta</option>
                            <option>Mauritania</option>
                            <option>Mauritius</option>
                            <option>Mexico</option>
                            <option>Moldova</option>
                            <option>Monaco</option>
                            <option>Mongolia</option>
                            <option>Montenegro</option>
                            <option>Morocco</option>
                            <option>Mozambique</option>
                            <option>Myanmar</option>
                            <option>Namibia</option>
                            <option>Nepal</option>
                            <option>Netherlands</option>
                            <option>New Zealand</option>
                            <option>Nicaragua</option>
                            <option>Niger</option>
                            <option>Nigeria</option>
                            <option>North Korea</option>
                            <option>North Macedonia</option>
                            <option>Norway</option>
                            <option>Oman</option>
                            <option>Pakistan</option>
                            <option>Panama</option>
                            <option>Papua New Guinea</option>
                            <option>Paraguay</option>
                            <option>Peru</option>
                            <option>Philippines</option>
                            <option>Poland</option>
                            <option>Portugal</option>
                            <option>Qatar</option>
                            <option>Romania</option>
                            <option>Russia</option>
                            <option>Rwanda</option>
                            <option>Saudi Arabia</option>
                            <option>Senegal</option>
                            <option>Serbia</option>
                            <option>Sierra Leone</option>
                            <option>Singapore</option>
                            <option>Slovakia</option>
                            <option>Slovenia</option>
                            <option>Somalia</option>
                            <option>South Africa</option>
                            <option>South Korea</option>
                            <option>South Sudan</option>
                            <option>Spain</option>
                            <option>Sri Lanka</option>
                            <option>Sudan</option>
                            <option>Sweden</option>
                            <option>Switzerland</option>
                            <option>Syria</option>
                            <option>Taiwan</option>
                            <option>Tajikistan</option>
                            <option>Tanzania</option>
                            <option>Thailand</option>
                            <option>Togo</option>
                            <option>Trinidad and Tobago</option>
                            <option>Tunisia</option>
                            <option>Turkey</option>
                            <option>Turkmenistan</option>
                            <option>Uganda</option>
                            <option>Ukraine</option>
                            <option>United Arab Emirates</option>
                            <option>United Kingdom</option>
                            <option>United States</option>
                            <option>Uruguay</option>
                            <option>Uzbekistan</option>
                            <option>Venezuela</option>
                            <option>Vietnam</option>
                            <option>Yemen</option>
                            <option>Zambia</option>
                            <option>Zimbabwe</option>
                        </select>
                    </div>
                    <div class="ffield">
                        <label for="fpb-ftime">Preferred Time</label>
                        <select id="fpb-ftime">
                            <option value="">No preference</option>
                            <option value="Golden hour (sunrise)">Golden hour (sunrise)</option>
                            <option value="Morning (before noon)">Morning (before noon)</option>
                            <option value="Afternoon">Afternoon</option>
                            <option value="Golden hour (sunset)">Golden hour (sunset)</option>
                        </select>
                    </div>
                    <div class="ffield">
                        <label for="fpb-floc">Location Preference</label>
                        <input type="text" id="fpb-floc" placeholder="Beach, cave, city, open to suggestions…" />
                    </div>
                    <div class="ffield fgridspan">
                        <label for="fpb-fnotes">Special Requests / Notes</label>
                        <textarea id="fpb-fnotes" rows="3" placeholder="Mention anything that will help us craft the perfect shoot for you."></textarea>
                    </div>
                </div>

                <div class="fnav">
                    <button class="btn bo" onclick="fpb.bkGo(1)">← Back</button>
                    <button class="btn bg" onclick="fpb.s2Next()">Continue →</button>
                </div>
                <p id="fpb-s2err" class="ferr"></p>
            </div>
        </div>

        <!-- ╔══════════════════════════════════════════════╗
       ║  STEP 3 — Contract & Signature               ║
       ╚══════════════════════════════════════════════╝ -->
        <div class="fstep" id="fpb-s3">
            <div class="fstep-inner">
                <h2 class="ftitle"><?php echo esc_html(get_option('fpb_step3_title', 'Contract & Signature')); ?></h2>
                <p class="fsub"><?php echo esc_html(get_option('fpb_step3_sub', 'Please read and sign the service agreement.')); ?></p>

                <div class="fctrbox" id="fpb-contract">
                    <h3>Service Agreement</h3>
                    <p>This agreement is between <strong>Focus Photography Mauritius</strong> ("Photographer") and the client named in this booking form ("Client").</p>

                    <h4>1. Services</h4>
                    <p>The photographer will provide the photography services described in the chosen package on the confirmed session date. The photographer reserves the right to make reasonable substitutions to the schedule.</p>

                    <h4>2. Payment &amp; Deposit</h4>
                    <p>A non-refundable deposit (as indicated in the booking summary) is required to secure the date. The remaining balance is due three (3) days before the session. Failure to pay may result in the date being released.</p>

                    <h4>3. Rescheduling &amp; Cancellation</h4>
                    <p>The client may reschedule once, at least 72 hours before the session, at no charge. Cancellations within 72 hours of the session forfeit the full deposit. For bad-weather situations, the photographer will work with the client to find an alternative date at no additional cost.</p>

                    <h4>4. Delivery</h4>
                    <p>Edited digital images will be delivered via an online gallery within the timeframe stated in the package description. Raw files are not included or shared.</p>

                    <h4>5. Copyright &amp; Usage</h4>
                    <p>The photographer retains copyright to all images. The client is granted a personal, non-commercial licence to use the images for personal social media and printing. Commercial usage requires a separate written agreement.</p>

                    <h4>6. Liability</h4>
                    <p>The photographer's liability is limited to a full refund of any fees paid in the event of unforeseen circumstances preventing the session from taking place.</p>
                </div>

                <div class="fsig-wrap">
                    <div class="fsig-label">Draw your signature below <span class="req">*</span></div>
                    <canvas id="fpb-sigPad" width="600" height="150"></canvas>
                    <div class="fsig-actions">
                        <button type="button" class="btn-clr" onclick="fpb.clrSig()">Clear</button>
                        <span id="fpb-sigNote" class="fpb-sig-note"></span>
                    </div>
                    <div class="ffield" style="margin-top:1rem">
                        <label for="fpb-fsigner">Typed Full Name (confirms agreement) <span class="req">*</span></label>
                        <input type="text" id="fpb-fsigner" autocomplete="name" />
                    </div>
                </div>

                <div class="fnav">
                    <button class="btn bo" onclick="fpb.bkGo(2)">← Back</button>
                    <button class="btn bg" onclick="fpb.s3Next()">Continue →</button>
                </div>
                <p id="fpb-s3err" class="ferr"></p>
            </div>
        </div>

        <!-- ╔══════════════════════════════════════════════╗
       ║  STEP 4 — Confirm & Checkout                 ║
       ╚══════════════════════════════════════════════╝ -->
        <div class="fstep" id="fpb-s4">
            <div id="fpb-payWrap">
                <div class="fstep-inner">
                    <h2 class="ftitle"><?php echo esc_html(get_option('fpb_step4_title', 'Confirm & Pay Deposit')); ?></h2>
                    <p class="fsub"><?php echo esc_html(get_option('fpb_step4_sub', 'Review your booking then proceed to secure checkout.')); ?></p>

                    <!-- Booking summary -->
                    <div class="phead">
                        <h4>Booking Summary</h4>
                        <div class="sumr"><span class="sumk">Package</span><span class="sumv" id="fpb-pPkg">—</span></div>
                        <div class="sumr"><span class="sumk">Date</span><span class="sumv" id="fpb-pDate">—</span></div>
                        <div class="sumr"><span class="sumk">Add-ons</span><span class="sumv" id="fpb-pAddons">None</span></div>
                        <div class="sumr"><span class="sumk">Signed by</span><span class="sumv" id="fpb-pSigner">—</span></div>

                        <div class="phi">
                            <div class="phi-left">
                                <div class="phl">Session total: <strong id="fpb-pTot">—</strong></div>
                                <div class="phb">Balance <span id="fpb-pBal">—</span> due 3 days before session</div>
                            </div>
                            <div class="phn" id="fpb-pDep">—</div>
                        </div>
                        <div class="phi-note">Today you pay the deposit amount shown.</div>
                    </div>

                    <p class="fpb-sec-note">🔒 Secure checkout powered by WooCommerce — Stripe, PayPal and more accepted.</p>

                        <div class="fpb-gateway-box" id="fpb-gatewayBox">
                            <div class="fpb-gateway-title">Available payment methods</div>
                            <div class="fpb-gateway-list" id="fpb-gatewayList">
                                <p class="fpb-gateway-loading">Loading payment methods…</p>
                            </div>
                        </div>

                    <div class="fnav">
                        <button class="btn bo" onclick="fpb.bkGo(3)">← Back</button>
                        <button class="btn bg fpb-checkout-btn" id="fpb-checkoutBtn" onclick="fpb.proceedToCheckout()">
                                Proceed to Payment →
                        </button>
                    </div>
                    <p id="fpb-checkoutMsg" class="fpb-checkout-msg"></p>
                </div>
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
