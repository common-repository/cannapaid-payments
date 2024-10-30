<?php
$root = dirname(dirname(dirname(dirname(__FILE__))));
if (file_exists($root.'/wp-load.php')) {
    require_once($root.'/wp-load.php');
} else {
    require_once($root.'/wp-config.php');
}

header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    header("HTTP/1.1 200 Success");
    echo json_encode(array('success' => true));
    return;
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $content = file_get_contents("php://input");
    error_log('Postback received ' . $content);

    $data = json_decode($content);
    if (!is_object($data) || count(get_object_vars($data)) == 0) {
        header("HTTP/1.1 400 Bad Request");
        echo json_encode(array('error' => 'Empty request data'));
        return;
    }

    $GATEWAY_NAME = 'cannapaid_gateway';

    $TRANSACTION_COMPLETE = 'COMPLETE';
    $TRANSACTION_FAILED = 'FAILED';
    $TRANSACTION_REFUNDED= 'REFUNDED';
    $TRANSACTION_CHARGEBACK= 'CHARGEBACK';

    $STATUS_PROCESSING = 'processing';
    $STATUS_FAILED = 'failed';
    $STATUS_REFUNDED = 'refunded';

    $installed_payment_methods = WC()->payment_gateways->payment_gateways();

    foreach ($installed_payment_methods as $method) {
        if ($method->id === $GATEWAY_NAME) {
            $sanitized_first_name = sanitize_text_field($data->first_name);
            $sanitized_last_name = sanitize_text_field($data->last_name);
            $sanitized_email = sanitize_email($data->email);
            $sanitized_phone = wc_sanitize_phone_number($data->phone);
            $sanitized_address_1 = sanitize_text_field($data->address_1);
            $sanitized_city = sanitize_text_field($data->city);
            $sanitized_state = sanitize_text_field($data->state);
            $sanitized_postcode = sanitize_text_field($data->postcode);
            $sanitized_country = sanitize_text_field($data->country);

            $address = array(
                'first_name' => $sanitized_first_name,
                'last_name' => $sanitized_last_name,
                'email' => $sanitized_email,
                'phone' => $sanitized_phone,
                'address_1' => $sanitized_address_1,
                'city' => $sanitized_city,
                'state' => $sanitized_state,
                'postcode' => $sanitized_postcode,
                'country' => $sanitized_country,
            );

            // WooCommerce statuses are: Pending > Processing > Completed
            // pending      = Waiting for payment
            // processing   = Payment was made
            // failed       = Payment has failed
            // completed    = Product was shipped
            // So we are setting the status to 'processing':

            $transaction_id = sanitize_text_field($data->transaction_id);
            $reference = sanitize_text_field($data->reference);

            $order_status = sanitize_text_field($data->status);
            $order_id = wc_sanitize_order_id($data->orderId);
            $order = wc_get_order($order_id);
            $response = array('success' => false, 'message' => 'Unexpected error');

            if ($order_status === $TRANSACTION_COMPLETE) {
                $order -> update_status($STATUS_PROCESSING);
                $order -> set_address($address, 'billing');

                // TODO: Only set shipping address to billing address if shipping address is missing
                // $order->set_address($address, 'shipping');

                update_post_meta($order_id, '_payment_method', $GATEWAY_NAME);
                update_post_meta($order_id, '_payment_method_title', 'Credit Card Payment');
                update_post_meta($order_id, '_transaction_id', $transaction_id);
                update_post_meta($order_id, 'reference', $reference);
                $order->add_order_note( 'Payment ' . $order_status . ' - reference ' . $reference);
                $order->save();

                $response = array('success' => true, 'message' => 'Updated with status ' . $STATUS_PROCESSING);
            } else if ($order_status === $TRANSACTION_FAILED) {
                $order->update_status($STATUS_FAILED);

                // We are setting this because maybe the customer entered a different billing address in our forms
                // This would mean that the billing address the customer had in WP was wrong
                $order->set_address($address, 'billing');

                update_post_meta($order_id, '_payment_method', $GATEWAY_NAME);
                update_post_meta($order_id, '_payment_method_title', 'Credit Card Payment');
                update_post_meta($order_id, '_transaction_id', $transaction_id);
                update_post_meta($order_id, 'reference', $reference);
                $order->add_order_note( 'Payment ' . $order_status . ' - reference ' . $reference . '. Fail reason - ' . sanitize_text_field($data->fail_reason));
                $order->save();
                $response = array('success' => true, 'message' => 'Updated with status ' . $STATUS_FAILED);
            } else if ($order_status === $TRANSACTION_REFUNDED || $order_status === $TRANSACTION_CHARGEBACK) {
                $reference_refund = sanitize_text_field($data->reference_refund);
                $order->update_status($STATUS_REFUNDED);
                update_post_meta($order_id, 'reference_refund', $reference);
                $order->add_order_note( 'Payment ' . $order_status . ' - reference ' . $reference);
                $order->save();

                $response = array('success' => true, 'message' => 'Updated with status ' . $STATUS_REFUNDED);
            } else {
                $response = array('success' => false, 'message' => 'Unexpected transaction status ' . $order_status);
            }

            echo json_encode($response);
            return;
        }
    }
    echo json_encode(array('success' => false, 'message' => 'Payment gateway not found ' . $GATEWAY_NAME));
    return;
} else {
    header("HTTP/1.1 405 Method Not Allowed");
    echo json_encode(array('error' => $_SERVER['REQUEST_METHOD']." is not allowed"));
    return;
}



