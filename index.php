<?php
set_time_limit(180);
/**
 * Plugin Name:       CPG Payments Plugin
 * Plugin URI:        https://www.cpgpayments.com
 * Description:       A Wordpress plugin for WooCommerce which implements credit & debit card payments via CPG Payments
 * Author:            Team Alpha
 * Version:           1.1.9
 * Requires at least: 5.2
 * Requires PHP:      5.6
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This plugin is released under the GPLv2 License
 * (Please see the file "LICENSE" included with this plugin)
 *
 */
defined('ABSPATH') or exit;


// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + CPG Payments gateway
 * @since 1.1.1
 */
function wc_cbg_payments_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_CPGPayments';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_cbg_payments_add_to_gateways');


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.1.1
 */
function wc_cpg_payments_gateway_plugin_links($links)
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=cannapaid_gateway') . '">' . __('Configure', 'wc-gateway-cpg-payments') . '</a>'
    );
    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_cpg_payments_gateway_plugin_links');


/**
 * CPG Payments Gateway
 *
 * Provides an CPG Payments Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class          WC_Gateway_CPGPayments
 * @extends        WC_Payment_Gateway
 * @version        1.1.9
 * @package        WooCommerce/Classes/Payment
 * @author         Team Alpha
 */
function wc_cpg_payments_gateway_init()
{
    class WC_Gateway_CPGPayments extends WC_Payment_Gateway
    {
        const GATEWAY_NAME = 'cannapaid_gateway';
        const AUTH_TOKEN_LIVE = 'T9XU1cpC5hgls953qOLbUVmnT92S1Ml7';

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id = self::GATEWAY_NAME;
            $this->icon = apply_filters('woocommerce_cpg_payments_icon', '');
            $this->has_fields = false;
            $this->method_title = __('CPG Payments', 'wc-gateway-cpg-payments');
            $this->method_description = __('Allows CPG payments.', 'wc-gateway-cpg-payments');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();
            $this->settings['title'] = 'Pay with debit or credit card';
            $this->settings['description'] = 'Visa and Mastercard accepted. AMEX, Discover, and prepaid cards are not supported. New York and Texas are not supported.';

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->is_embed_form = $this->get_option('is_embed_form');
            $this->token = $this->get_option('payment_token');
            $this->public_key = $this->get_option('public_key');
            $this->secret_key = $this->get_option('secret_key');

            // Filter options
            add_filter('woocommerce_settings_api_sanitized_fields_' . $this->id, array($this, 'validate_form_options'));

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function validate_form_options($settings)
        {

            $token = $settings['payment_token'];
            $public_key = $settings['public_key'];
            $secret_key = $settings['secret_key'];
            $is_embed_form = $settings['is_embed_form'];
            $postback = $settings['postback'];

            if (!$token) {
                $errorMessage = 'Form token is required';
                echo '<div id="message" class="notice notice-error is-dismissible"><p>' . $errorMessage . '</p></div>';
                return $settings;
            }

            $decode_token = wc_cpg_parse_jwt($token);
            if (!$decode_token) {
                $errorMessage = 'Invalid token. Please check your token.';
                echo '<div id="message" class="notice notice-error is-dismissible"><p>' . $errorMessage . '</p></div>';
                return $settings;
            }

            $verifyBody = array(
                'token' => $token,
                'domain' => get_site_url(),
            );

            if ($postback === "yes") $verifyBody["postback_url"] = plugin_dir_url(__FILE__) . 'status_update.php';

            $api_url = $decode_token->api_url;

            $verifyTokenRequest = wp_remote_post("{$api_url}/api/form-token/verify", array(
                'method' => 'POST',
                'data_format' => 'body',
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($verifyBody),
            ));

            $verifyTokenResponse = json_decode($verifyTokenRequest['body']);

            if (isset($verifyTokenResponse->status) && !$verifyTokenResponse->status) {
                $errorMessage = $verifyTokenResponse->error; //$errorMessage = $response->errorMessage;
                echo '<div id="message" class="notice notice-error is-dismissible"><p>' . $errorMessage . '</p></div>';
                $settings['payment_token'] = '';
                return $settings;
            }

            if ($is_embed_form && (empty($public_key) || empty($secret_key))) {
                $errorMessage = 'API Keys are required to switch on embed form mode';
                echo '<div id="message" class="notice notice-error is-dismissible"><p>' . $errorMessage . '</p></div>';
                $settings['is_embed_form'] = 0;
                return $settings;
            }

            if (!empty($public_key) && !empty($secret_key)) {
                $response_key_validate = wp_remote_post("{$api_url}/api/transaction-process/healthcheck/open", array(
                    'method' => 'POST',
                    'data_format' => 'body',
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'PublicKey' => $public_key,
                        'SecretKey' => $secret_key,
                    ),
                    'body' => json_encode(array(
                        'token' => $token,
                    )),
                ));
                $response_key_body_validate = $response_key_validate['body'];
                $response_key_body_validate_json = json_decode($response_key_body_validate, TRUE);
                if (isset($response_key_body_validate_json['status'])) {
                    if (!$response_key_body_validate_json['status']) {
                        if ($response_key_body_validate_json['status_code'] === 403) {
                            $error_message = 'Invalid secret key or public key';
                        } else {
                            $error_message = $response_key_body_validate_json['error'];
                        }
                        echo '<div id="message" class="notice notice-error is-dismissible"><p>' . ucfirst($error_message) . '</p></div>';
                        $settings['public_key'] = '';
                        $settings['secret_key'] = '';
                        $settings['is_embed_form'] = 0;
                        return $settings;
                    }
                }
            }

            return $settings;
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $fields = $this->get_fields();
            $this->form_fields = apply_filters('wc_cpg_payments_form_fields', $fields);
        }

        public function get_fields()
        {
            return array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-gateway-cpg-payments'),
                    'type' => 'checkbox',
                    'label' => __('Enable CPG Payments', 'wc-gateway-cpg-payments'),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title' => __('Title', 'wc-gateway-cpg-payments'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-cpg-payments'),
                    'default' => __('Pay with debit or credit card', 'wc-gateway-cpg-payments'),
                    'desc_tip' => true,
                    'custom_attributes' => array('readonly' => 'readonly'),
                ),

                'description' => array(
                    'title' => __('Description', 'wc-gateway-cpg-payments'),
                    'type' => 'textarea',
                    'description' => __('Visa and Mastercard accepted. AMEX, Discover, and prepaid cards are not supported.', 'wc-gateway-cpg-payments'),
                    'default' => __('Visa and Mastercard accepted. Discover, AMEX, and prepaid cards are not supported.', 'wc-gateway-cpg-payments'),
                    'desc_tip' => true,
                    'custom_attributes' => array('readonly' => 'readonly'),
                ),

                'is_embed_form' => array(
                    'title' => __('Form/Redirect', 'wc-gateway-cpg-payments'),
                    'type' => 'select',
                    'options' => [__('Redirect', 'wc-gateway-cpg-payments'), __('Embed Form', 'wc-gateway-cpg-payments')],
                ),

                'payment_token' => array(
                    'title' => __('Form token', 'wc-gateway-cpg-payments'),
                    'type' => 'textarea',
                    'description' => __('CPG Payments Agent token.', 'wc-gateway-cpg-payments'),
                    'default' => '',
                    'desc_tip' => true,
                ),

                'public_key' => array(
                    'title' => __('Public Key', 'wc-gateway-cpg-payments'),
                    'type' => 'textarea',
                    'description' => __('CPG Payments Merchant Public key.', 'wc-gateway-cpg-payments'),
                    'default' => '',
                    'desc_tip' => true,
                ),

                'secret_key' => array(
                    'title' => __('Secret Key', 'wc-gateway-cpg-payments'),
                    'type' => 'textarea',
                    'description' => __('CPG Payments Merchant Secret key.', 'wc-gateway-cpg-payments'),
                    'default' => '',
                    'desc_tip' => true,
                ),

                'postback' => array(
                    'title' => __('Enable auto-update for postback URL', 'wc-gateway-cpg-payments'),
                    'type' => 'checkbox',
                    'label' => __(' ', 'wc-gateway-cpg-payments'),
                    'default' => 'no'
                ),
            );
        }

        public function payment_fields()
        {
            $payment_methods = WC()->payment_gateways->payment_gateways();
            $cpg_gateway = $payment_methods[self::GATEWAY_NAME];

            if (is_null($cpg_gateway)) {
                $formTokenError = 'Payment method not found';
                echo "<div class='woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout'><ul class='woocommerce-error' style='margin-bottom: 0 !important;' role='alert'><li>$formTokenError</li></ul></div>";
                return;
            }

            $settings = $cpg_gateway->settings;
            $public_key = $settings['public_key'];
            $secret_key = $settings['secret_key'];
            $is_embed_form = $settings['is_embed_form'];
            $payment_token = $settings['payment_token'];

            if (!$is_embed_form) {
                echo wpautop(wptexturize($this->description));
                return;
            }

            $decode_token = wc_cpg_parse_jwt($payment_token);
            if (!$decode_token) {
                $formTokenError = 'Payment gateway settings are incorrect. Please set the form token.';
                echo "<div class='woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout'><ul class='woocommerce-error' style='margin-bottom: 0 !important;' role='alert'><li>$formTokenError</li></ul></div>";
                return;
            }

            $api_url = $decode_token->api_url;

            $response_body_disclaimer_json = wc_cpg_get_disclaimer($api_url, $secret_key, $public_key, $payment_token);
            if (isset($response_body_disclaimer_json['status'])) {
                if (!$response_body_disclaimer_json['status']) {
                    $error_message = $response_body_disclaimer_json['error'];
                    echo "<div class='woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout'><ul class='woocommerce-error' style='margin-bottom: 0 !important;' role='alert'><li>$error_message</li></ul></div>";
                    return;
                }
            }

            $response_disclaimer_json = $response_body_disclaimer_json['disclaimer'];
            $response_disclaimer_text = $response_disclaimer_json['en']; // English disclaimer version
            if (!$response_disclaimer_text) $response_disclaimer_text = ' ';

            $checkout = WC()->checkout();

            $billing_country = $checkout->get_value('billing_country');
            $order_amount = $this->get_order_total();

            $request_fee = wp_remote_post("{$api_url}/api/transaction-process/amount/open", array(
                'method' => 'POST',
                'data_format' => 'body',
                'headers' => array(
                    'SecretKey' => $secret_key,
                    'PublicKey' => $public_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'token' => $payment_token,
                    'amount' => (float)$order_amount,
                    'country' => $billing_country,
                )),
            ));

            $response_body_fee_json = json_decode($request_fee['body'], TRUE);
            if (isset($response_body_fee_json['status'])) {
                if (!$response_body_fee_json['status']) {
                    $error_message = $response_body_fee_json['error'];
                    echo "<div class='woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout'><ul class='woocommerce-error' style='margin-bottom: 0 !important;' role='alert'><li>$error_message</li></ul></div>";
                    return;
                }
            }

            $customer_fee_number = $response_body_fee_json['customer_fee'];
            $customer_fee = number_format((float)$customer_fee_number, 2, '.', '');
            if (trim($this->description)) {
                echo wpautop(wptexturize($this->description));
            }

            // I will echo() the form, but you can close PHP tags and print it directly in HTML
            echo '<fieldset id="wc-' . esc_attr($this->id) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

            // Add this action hook if you want your custom payment gateway to support it
            do_action('woocommerce_credit_card_form_start', $this->id);

            // I recommend to use inique IDs, because other gateways could already use #ccNo, #expdate, #cvc
            echo '
                <style>
                    .wc-cpg-form-wrapper {
                        max-width: 360px;
                        margin-bottom: 12px;
                    }
                    .wc-cpg-form-wrapper input {
                        padding: 10px;
                        line-height: initial;
                    }
                    .wc-cpg-form-wrapper .form-row-wide label,
                    .wc-cpg-dd-mm-wrapper .form-row-first label,
                    .wc-cpg-dd-mm-wrapper .form-row-last label {
                        font-size: 12px;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-last label {
                        min-width: 105px;
                    }
                    .wc-cpg-form-wrapper .form-row-wide #cpg_ccNo {
                        width: 100%;
                        padding-left: 7px;
                        color: inherit;
                    }
                    .wc-cpg-form-wrapper .wc-cpg-dd-mm-wrapper {
                        display: flex;
                        justify-content: space-between;
                        flex-wrap: wrap;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-first {
                        width: 145px;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-first .expiration {
                        display: flex;
                        align-items: center;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-first .expiration .cpg_form_input {
                        width: 55px;
                        margin: 0!important;
                        color: inherit;
                        text-align: center;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-first .expiration span {
                        padding: 0 5px 0 5px;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-last {
                        flex-grow: 1;
                        width: min-content;
                    }
                    .wc-cpg-dd-mm-wrapper .form-row-last .cpg_form_input {
                        width: 100%;
                        padding-left: 8px;
                        color: inherit;
                        margin: 0!important;
                    }
                    .wc-credit-card-form .wc-cpg-fee {
                        font-size: 13px;
                        font-weight: bold;
                    }
                    .wc-credit-card-form .wc-cpg-disclaimer {
                        margin-top: 7px;
                        margin-bottom: 10px;
                    }
                    .wc-credit-card-form .wc-cpg-disclaimer p {
                        font-size: 11px;
                    }
                    .wc-credit-card-form .wc-cpg-checkBox-first {
                        display: flex;
                        margin-top: 7px;
                        align-items: center;
                        margin-bottom: 5px;
                    }
                    .wc-credit-card-form .wc-cpg-checkBox-first #token_disclaimer_pay,
                    .wc-credit-card-form .wc-cpg-checkBox-second #token_disclaimer_transfer{
                        margin-right: 9px;
                    }
                    .wc-credit-card-form .wc-cpg-checkBox-first label,
                    .wc-credit-card-form .wc-cpg-checkBox-second label {
                        font-size: 13px;
                    }
                    .wc-credit-card-form .wc-cpg-checkBox-second {
                        display: flex;
                        align-items: center;
                    }
                </style>
                <div class="wc-cpg-form-wrapper">
                    <div class="form-row form-row-wide">
                        <label>Card Number<span class="required">*</span></label>
                        <input id="cpg_ccNo" name="cpg_ccNo" type="tel" maxlength="16" size="16" autocomplete="cc-number">
                    </div>
                    <div class="wc-cpg-dd-mm-wrapper" >
                        <div class="form-row form-row-first">
                            <label>Expiration<span class="required">*</span></label>
                             <span class="expiration">
                                <input type="tel" id="cpg_month" class="cpg_form_input" name="cpg_month" placeholder="MM" maxlength="2" size="2" autocomplete="cc-exp-month" />
                                <span>/</span>
                                <input type="tel" id="cpg_year" class="cpg_form_input" name="cpg_year" placeholder="YY" maxlength="2" size="2" autocomplete="cc-exp-year" />
                            </span>
                        </div>
                        <div class="form-row form-row-last">
                            <label>Card Code (CVV)<span class="required">*</span></label>
                            <input id="cpg_cvv" name="cpg_cvv" class="cpg_form_input" type="password" maxlength="4" size="4" autocomplete="cc-csc" placeholder="CVV" />
                        </div>
                    </div>
                    <div class="clear"></div>
                </div>';
            echo '<script>
            var cvvElement = document.getElementById("cpg_cvv");
            var cardElement = document.getElementById("cpg_ccNo");
            var monthElement = document.getElementById("cpg_month");
            var yearElement = document.getElementById("cpg_year");
            cvvElement.addEventListener("input", checkNumber);
            cardElement.addEventListener("input", checkNumber);
            monthElement.addEventListener("input", checkMonth);
            yearElement.addEventListener("input", checkYear);
            monthElement.addEventListener("focusout", checkMonthFocus);
            function checkNumber(e) {
                const text = e.target.value;
                const myRe = new RegExp(/^\d+$/)
                if (!myRe.exec(text)) e.target.value = text.slice(0, text.length - 1);
            }
            function checkMonthFocus(e) {
                const text = monthElement.value
                if (text && text.length === 1 && text!=="0") monthElement.value = "0"+ text
            }
            function checkMonth(e) {
                checkNumber(e)
                const text = e.target.value;
                const myRe = new RegExp(/^0[1-9]$|^1[0-2]$|^[0-9]$/)
                if (!myRe.test(text)) return e.target.value = text.slice(0, text.length - 1);
                if (text.length === 2) yearElement.focus()
            }
            function checkYear(e) {
                checkNumber(e)
                const text = e.target.value;
                if (text.length === 2) cvvElement.focus()
            }
            </script>';

            echo "<div class='wc-cpg-fee'>A $$customer_fee transaction fee will be applied to your purchase</div>";

            echo "<div class='wc-cpg-disclaimer'>{$response_disclaimer_text}</div>";

            echo '<div class="wc-cpg-checkBox-first"><input onchange="check1()" type="checkbox" id="token_disclaimer_pay"><label for="token_disclaimer_pay">I understand I am purchasing a token from ';
            echo "{$response_body_disclaimer_json['company_name']} <span class='required'>*</span></label></div>";
            echo '<div class="wc-cpg-checkBox-second"><input onchange="check2()" type="checkbox" name="token_disclaimer_transfer" id="token_disclaimer_transfer"><label for="token_disclaimer_transfer">I agree to transfer my token to ';
            echo "{$response_body_disclaimer_json['site_name']} as a form of payment <span class='required'>*</span></label></div>";
            echo '<input id="token_disclaimer_pay_checker" name="token_disclaimer_pay_checker" type="hidden" value="0">';
            echo '<input id="token_disclaimer_transfer_checker" name="token_disclaimer_transfer_checker" type="hidden" value="0">';
            echo '<script>
                function check1() {
                    const chbox1 = document.getElementById("token_disclaimer_pay");
                    const chboxValue1 = document.getElementById("token_disclaimer_pay_checker")
                    chboxValue1.value = chbox1.checked ? "1" : "0";
                }
                function check2() {
                    const chbox2 = document.getElementById("token_disclaimer_transfer");
                    const chboxValue2 = document.getElementById("token_disclaimer_transfer_checker")
                    chboxValue2.value = chbox2.checked ? "1" : "0";
                }
            </script>';

            do_action('woocommerce_credit_card_form_end', $this->id);

            echo '<div class="clear"></div></fieldset>';
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            global $wp;
            $checkout = WC()->checkout();
            $order = wc_get_order($order_id);

            if (is_null($order)) {
                $error_message = 'Order not found';
                error_log($error_message);
                throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
            }

            $payment_methods = WC()->payment_gateways->payment_gateways();
            $cpg_gateway = $payment_methods[self::GATEWAY_NAME];

            if (is_null($cpg_gateway)) {
                $error_message = 'Payment method not found';
                error_log($error_message);
                throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
            }

            $settings = $cpg_gateway->settings;
            $public_key = $settings['public_key'];
            $secret_key = $settings['secret_key'];
            $is_embed_form = $settings['is_embed_form'];
            $payment_token = $settings['payment_token'];

            $decode_token = wc_cpg_parse_jwt($payment_token);
            if (!$decode_token) {
                $error_message = 'Invalid token. Please check your token.';
                error_log($error_message);
                throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
            }

            $api_url = $decode_token->api_url;

            $order_amount = $order->get_total();
            $redirect = $cpg_gateway->get_return_url($order);
            $home_url = home_url(add_query_arg(array(), $wp->request));

            if ($is_embed_form) {
                // Ember form flow

                if (empty($checkout->get_value('cpg_ccNo'))) {
                    $error_message = 'Card number is required.';
                    error_log($error_message);
                    throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
                }

                if (empty($checkout->get_value('cpg_year')) || empty($checkout->get_value('cpg_month'))) {
                    $error_message = 'Expiration date is required.';
                    error_log($error_message);
                    throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
                }

                if (empty($checkout->get_value('cpg_cvv'))) {
                    $error_message = 'CVV code is required.';
                    error_log($error_message);
                    throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
                }

                if (!$checkout->get_value('token_disclaimer_pay_checker') || !$checkout->get_value('token_disclaimer_transfer_checker')) {
                    throw new Exception(__('You must agree to the terms and conditions before placing your order', 'wc-gateway-cpg-payments'));
                }

                $response_body_disclaimer_json = wc_cpg_get_disclaimer($api_url, $secret_key, $public_key, $payment_token);
                if (isset($response_body_disclaimer_json['status'])) {
                    if (!$response_body_disclaimer_json['status']) {
                        $error_message = $response_body_disclaimer_json['error'];
                        error_log($error_message);
                        throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
                    }
                }

                $request = wp_remote_post("{$api_url}/api/transaction-process/do-transaction/open", array(
                    'method' => 'POST',
                    'timeout' => 180,
                    'data_format' => 'body',
                    'headers' => array(
                        'SecretKey' => $secret_key,
                        'PublicKey' => $public_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode([
                        'token' => $payment_token,
                        'customer' => [
                            'first_name' => $checkout->get_value('billing_first_name'),
                            'last_name' => $checkout->get_value('billing_last_name'),
                            'phone' => $checkout->get_value('billing_phone'),
                            'email' => $checkout->get_value('billing_email'),
                            'address' => [
                                'city' => $checkout->get_value('billing_city'),
                                'zip' => $checkout->get_value('billing_postcode'),
                                'country' => $checkout->get_value('billing_country'),
                                'state' => $checkout->get_value('billing_state'),
                                'address1' => $checkout->get_value('billing_address_1'),
                            ],
                            'card' => [
                                'number' => $checkout->get_value('cpg_ccNo'),
                                'year' => '20' . $checkout->get_value('cpg_year'),
                                'month' => $checkout->get_value('cpg_month'),
                                'cvv' => $checkout->get_value('cpg_cvv'),
                            ],
                        ],
                        'amount' => (float)$order_amount,
                        'order_id' => $order_id,
                        'disclaimer_version' => (int)$response_body_disclaimer_json['disclaimer_version'],
                    ]),
                ));

                $response_body = $request['body'];
                $response_body_json = json_decode($response_body, TRUE);

                $unexpected_error_message = 'Unexpected error.';

                if (!isset($response_body_json['status'])) {
                    throw new Exception(__($unexpected_error_message, 'wc-gateway-cpg-payments'));
                }

                if (!$response_body_json['status']) {
                    $error_message = array_key_exists('error', $response_body_json) ? $response_body_json['error'] : $unexpected_error_message;
                    throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
                }

                if (!isset($response_body_json['transaction_id']) || !isset($response_body_json['reference'])) {
                    throw new Exception(__($unexpected_error_message, 'wc-gateway-cpg-payments'));
                }

                WC()->cart->empty_cart();
                $redirect_path = plugin_dir_url(__FILE__) . 'status.php?transaction_id=' . $response_body_json['transaction_id'] . '&order_id=' . $order_id . '&reference='. $response_body_json['reference'] .'&redirect_url=' . urlencode($this->get_return_url($order));
                return array(
                    'result' => 'success',
                    'redirect' => $redirect_path,
                );
            }

            // Redirect flow

            $auth_token = self::AUTH_TOKEN_LIVE;
            $plugin_data = get_plugin_data(__FILE__);
            $plugin_version = $plugin_data['Version'];

            $body = array(
                'token' => $payment_token,
                'order_id' => $order_id,
                'amount' => (float)$order_amount,
                'redirect_url' => $redirect,
                'home_url' => $home_url,
                'device_type' => 'web',
                'customer_data' => json_encode(array(
                    'firstName' => $checkout->get_value('billing_first_name'),
                    'lastName' => $checkout->get_value('billing_last_name'),
                    'address' => $checkout->get_value('billing_address_1'),
                    'city' => $checkout->get_value('billing_city'),
                    'postCode' => $checkout->get_value('billing_postcode'),
                    'country' => $checkout->get_value('billing_country'),
                    'state' => $checkout->get_value('billing_state'),
                    'email' => $checkout->get_value('billing_email'),
                    'phone' => $checkout->get_value('billing_phone'),
                )),
                'plugin_version' => $plugin_version,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            );

            if (empty($public_key) || empty($secret_key)) {
                $request = wp_remote_post("{$api_url}/api/order/session/init", array(
                    'method' => 'POST',
                    'data_format' => 'body',
                    'headers' => array(
                        'authorization' => $auth_token,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($body),
                ));
            } else {
                $request = wp_remote_post("{$api_url}/api/order/session/init/open", array(
                    'method' => 'POST',
                    'data_format' => 'body',
                    'headers' => array(
                        'SecretKey' => $secret_key,
                        'PublicKey' => $public_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($body),
                ));
            }

            $response_body = $request['body'];
            $response_body_json = json_decode($response_body, TRUE);

            if (!$response_body_json['success']) {
                $error_message = $response_body_json['error'];
                error_log($error_message);
                throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
            }

            $form_url = json_decode($request['body'])->form_url;

            if (is_null($form_url)) {
                $error_message = 'Payment form url not found';
                error_log($error_message);
                throw new Exception(__($error_message, 'wc-gateway-cpg-payments'));
            }

            return array('result' => 'success', 'redirect' => $form_url);
        }

    } // end \WC_Gateway_CPGPayments class
}

function wc_cpg_get_disclaimer($api_url, $secret_key, $public_key, $token) {
    $request = wp_remote_post("{$api_url}/api/transaction-process/disclaimer/open", array(
        'method' => 'POST',
        'data_format' => 'body',
        'headers' => array(
            'SecretKey' => $secret_key,
            'PublicKey' => $public_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode(array(
            'token' => $token,
        )),
    ));
    $response_body_disclaimer = $request['body'];
    $response_body_disclaimer_json = json_decode($response_body_disclaimer, TRUE);
    return $response_body_disclaimer_json;
}

function wc_cpg_parse_jwt($token) {
    if (!$token) return false;

    $token_arr = explode('.', $token);
    if (!count($token_arr)) return false;

    $decode_token = json_decode(base64_decode(
        str_replace('_', '/', str_replace('-', '+', $token_arr[1]))
    ));

    if (!isset($decode_token->api_url)) return false;

    return $decode_token;
}

add_action('plugins_loaded', 'wc_cpg_payments_gateway_init', 11);

function wp9838c_timeout_extend($time)
{
    // Default timeout is 5
    return 180;
}

add_filter('http_request_timeout', 'wp9838c_timeout_extend');
