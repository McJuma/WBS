<?php
header('Content-Type: application/json');

// === 1. Get Phone Number and Amount from JavaScript POST ===
$phone = $_POST['phone'] ?? '';
$amount = $_POST['amount'] ?? '';

if (!$phone || !$amount) {
    respond(['status' => 'error', 'message' => 'Phone number and amount required.']);
}

// === 2. Set Sandbox Credentials ===
$shortCode = 'YOUR_SHORTCODE'; // Till number or PayBill
$passkey = 'YOUR_PASSKEY';
$consumerKey = 'CONSUMER_KEY';
$consumerSecret = 'CONSUMER_SECRET';
$callbackUrl = 'https://YOUR_DOMAIN/mpesa-callback.php'; // Must be https

// === 3. Get OAuth Access Token ===
$access_token = getAccessToken($consumerKey, $consumerSecret);
if (!$access_token) {
    respond(['status' => 'error', 'message' => 'Failed to get access token.']);
}

// === 4. Prepare STK Push Payload ===
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);
$accountReference = "Receiver's account number";
$transactionDesc = 'Testing STK Push';

$stkPayload = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone, // Sender's Phone Number
    'PartyB' => $shortCode, // Receiver's short code or Paybill Number
    'PhoneNumber' => $phone, // Sender's Phone Number
    'CallBackURL' => $callbackUrl,
    'AccountReference' => $accountReference,
    'TransactionDesc' => $transactionDesc
];

// === 5. Send STK Push Request ===
$response = sendStkPush($stkPayload, $access_token);

// === 6. Handle Response ===
if (isset($response['ResponseCode']) && $response['ResponseCode'] === '0') {
    $merchantRequestID = $response['MerchantRequestID'];
    $checkoutRequestID = $response['CheckoutRequestID'];
    $dbResult = storeTransaction($merchantRequestID, $checkoutRequestID, $phone, $amount, $accountReference);
    if ($dbResult !== true) {
        respond(['status' => 'error', 'message' => 'DB insert failed']);
    }
    respond([
        'status' => 'pending',
        'message' => 'STK Push sent successfully.',
        'checkoutRequestID' => $checkoutRequestID
    ]);
} else {
    logError("logs/stkpush_errors.log", json_encode($response));
    respond([
        'status' => 'error',
        'message' => 'STK Push request failed',
        'response' => $response
    ]);
}

// === Helper Functions ===

function respond($data) {
    echo json_encode($data);
    exit;
}

function getAccessToken($consumerKey, $consumerSecret) {
    $credentials = base64_encode("$consumerKey:$consumerSecret");
    $tokenUrl = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'; // for sandbox
    // $tokenUrl = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'; // for production
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $credentials"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tokenResponse = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($tokenResponse);
    return $tokenData->access_token ?? null;
}

function sendStkPush($payload, $access_token) {
    $stkUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $ch = curl_init($stkUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $access_token"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $stkPushResponse = curl_exec($ch);
    curl_close($ch);
    return json_decode($stkPushResponse, true);
}

function storeTransaction($merchantRequestID, $checkoutRequestID, $phone, $amount, $accountReference) {
    $conn = new mysqli('db_name', 'db_username', 'db_password', 'db_name');
    if ($conn->connect_error) {
        logError("logs/db_error.log", $conn->connect_error);
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO stk_transactions 
        (merchant_request_id, checkout_request_id, phone_number, amount, account_reference, status) 
        VALUES (?, ?, ?, ?, ?, ?)");
    $status = 'Pending';
    $stmt->bind_param("ssssss", $merchantRequestID, $checkoutRequestID, $phone, $amount, $accountReference, $status);
    $result = $stmt->execute();
    if (!$result) {
        logError("logs/db_insert_error.log", $stmt->error);
    }
    $stmt->close();
    $conn->close();
    return $result ? true : false;
}

function logError($file, $message) {
    file_put_contents($file, $message . PHP_EOL, FILE_APPEND);
}
