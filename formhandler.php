<?php
header('Content-Type: application/json'); // Let the client know we're returning JSON

// === 1. Get Phone Number and Amount from JavaScript POST ===
$phone = $_POST['phone'] ?? '';
$amount = $_POST['amount'] ?? '';

// If missing, return error
if (!$phone || !$amount) {
    echo json_encode(['status' => 'error', 'message' => 'Phone number and amount required.']);
    exit;
}

// === 2. Set Sandbox Credentials (Get these from your Daraja test app) ===
$shortCode = '174379'; // Safaricom test paybill number
$passkey = 'YOUR_SANDBOX_PASSKEY';
$consumerKey = 'YOUR_SANDBOX_CONSUMER_KEY';
$consumerSecret = 'YOUR_SANDBOX_CONSUMER_SECRET';
$callbackUrl = 'https://yourdomain.com/callback.php'; // Must be HTTPS

// === 3. Get OAuth Access Token ===
$credentials = base64_encode("$consumerKey:$consumerSecret");
$tokenUrl = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

// cURL handle: $ch means "cURL handle" — the connection/session
$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $credentials"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$tokenResponse = curl_exec($ch); // Make the request to get token
$access_token = json_decode($tokenResponse)->access_token ?? null;
curl_close($ch); // Always close the handle after use

// If token failed, stop
if (!$access_token) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to get access token.']);
    exit;
}

// === 4. Prepare STK Push Payload ===
$timestamp = date('YmdHis'); // Format: YYYYMMDDHHMMSS
$password = base64_encode($shortCode . $passkey . $timestamp); // Daraja requirement
$accountReference = 'TEST_' . time(); // Optional label for this transaction
$transactionDesc = 'Testing STK Push'; // Description to show to user

// Data to send to Safaricom STK API
$stkPayload = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => $shortCode, // Where money is going (your paybill/till)
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => $accountReference,
    'TransactionDesc' => $transactionDesc
];

// === 5. Send STK Push Request ===
$stkUrl = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
$ch = curl_init($stkUrl); // Create new cURL handle for STK Push request
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "Authorization: Bearer $access_token"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stkPayload)); // Convert payload to JSON

$stkPushResponse = curl_exec($ch); // Perform the API request
curl_close($ch); // Close the connection

// === 6. Decode the Response ===
$response = json_decode($stkPushResponse, true);

// === 7. Store Transaction in DB if Successful ===
if (isset($response['ResponseCode']) && $response['ResponseCode'] === '0') {
    $merchantRequestID = $response['MerchantRequestID'];
    $checkoutRequestID = $response['CheckoutRequestID'];

    // Connect to MySQL database
    $conn = new mysqli("localhost", "root", "admin", "mpesa_transactions");
    if ($conn->connect_error) {
        file_put_contents("logs/db_error.log", $conn->connect_error . PHP_EOL, FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
        exit;
    }

    // Prepare to insert STK push data into stk_transactions
    $stmt = $conn->prepare("INSERT INTO stk_transactions 
        (merchant_request_id, checkout_request_id, phone_number, amount, account_ref, status) 
        VALUES (?, ?, ?, ?, ?, ?)");

    $status = 'Pending'; // Default status until callback updates it
    $stmt->bind_param("ssssss", 
        $merchantRequestID, 
        $checkoutRequestID, 
        $phone, 
        $amount, 
        $accountReference, 
        $status
    );

    // Execute insert and send response
    if (!$stmt->execute()) {
        file_put_contents("logs/db_insert_error.log", $stmt->error . PHP_EOL, FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'DB insert failed']);
    } else {
        echo json_encode([
            'status' => 'pending',
            'message' => 'STK Push sent successfully.',
            'checkoutRequestID' => $checkoutRequestID
        ]);
    }

    $stmt->close();
    $conn->close();
} else {
    // STK Push failed (e.g., invalid phone)
    file_put_contents("logs/stkpush_errors.log", $stkPushResponse . PHP_EOL, FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => 'STK Push request failed',
        'response' => $response
    ]);
}