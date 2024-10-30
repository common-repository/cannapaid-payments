<?php
header('Content-Type: application/json');
$root = dirname(dirname(dirname(dirname(__FILE__))));
if (file_exists($root.'/wp-load.php')) {
    require_once($root.'/wp-load.php');
} else {
    require_once($root.'/wp-config.php');
}

$GATEWAY_NAME = 'cannapaid_gateway';
$payment_methods = WC()->payment_gateways->payment_gateways();

$cpg_gateway = $payment_methods[$GATEWAY_NAME];
$settings = $cpg_gateway->settings;

$payment_token = $settings['payment_token'];
$public_key = $settings['public_key'];
$secret_key = $settings['secret_key'];

$decode_token = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $payment_token)[1]))));
$api_url = $decode_token->api_url;

$transaction_id = $_POST['transaction_id'];
$order_id = $_POST['order_id'];

if (empty($transaction_id)) {
    $request_track_transaction = wp_remote_post("{$api_url}/api/transaction-process/track/{$order_id}/open", array(
        'method' => 'POST',
        'data_format' => 'body',
        'headers' => array(
            'SecretKey' => $secret_key,
            'PublicKey' => $public_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode(array(
            'token' => $payment_token,
        )),
    ));
} else {
    $request_track_transaction = wp_remote_get("{$api_url}/api/transaction-process/track/{$transaction_id}/open", array(
        'method' => 'GET',
        'data_format' => 'body',
        'headers' => array(
            'SecretKey' => $secret_key,
            'PublicKey' => $public_key,
            'Content-Type' => 'application/json',
        ),
        'body' => null,
    ));
}
$response_body = $request_track_transaction['body'];
echo json_encode($response_body);
