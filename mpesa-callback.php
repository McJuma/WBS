<?php
/*
fields from callback
1. $MerchantRequestID
2. $CheckoutRequestId
3. $ResultCode
4. $ResultDesc
5. $CallbackMetadata
6. $MpesaReceiptNumber
7. $Amount
8. $PhoneNumber
9. $TransactionDate
*/
// 6, 7, 8 and 9 will not be included in callBack data if transaction fails or transaction is cancelled

$callbackData = file_get_contents('php://input');
// log callback data to a file
file_put_contents('logs/callback_raw.log', $callbackData . PHP_EOL, FILE_APPEND);

// decode the JSON data
$data = json_decode($callbackData, true);
if (!$data || !isset($data['Body']['stkCallback'])) {
    file_put_contents('logs/callback_error.log', 'Invalid callback structure: $callbackData' . PHP_EOL, FILE_APPEND);
    exit;
}

$stkCallback = $data['Body']['stkCallback'];

// Extract required fields from the callback data
$MerchantRequestID = $stkCallback['MerchantRequestID'] ?? null;
$CheckoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
$ResultCode = $stkCallback['ResultCode'] ?? null;
$ResultDesc = $stkCallback['ResultDesc'] ?? null;
$CallbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
$MpesaReceiptNumber = null;
$Amount = null;
$PhoneNumber = null;
$TransactionDate = null;

foreach($CallbackMetadata as $Item) {
    switch ($Item['Name']) {
        case 'MpesaReceiptNumber':
            $MpesaReceiptNumber = $Item['Value'];
            break;
        case 'Amount':
            $Amount = $Item['Value'];
            break;
        case 'PhoneNumber':
            $PhoneNumber = $Item['Value'];
            break;
        case 'TransactionDate':
            $rawDate = $Item['Value'];
            $TransactionDate = DateTime::createFromFormat('YmdHis', $rawDate)->format('Y-m-d H:i:s');
            break;
        default:
        // Handle other metadata items if needed
            break;
    }
}

// Get Status from $ResultCode
$status = ($ResultCode == 0) ? 'Success' : 'Failed';

// connect to database
$conn = mysqli_connect('db_name', 'db_username', 'db_password', 'db_name');
if ($conn->connect_error) {
    file_put_contents('logs/db_connect_error.log', 'Failed to connect to database: ' . $conn->connect_error . PHP_EOL, FILE_APPEND);
    exit;
}

$stmt = $conn->prepare("UPDATE stk_transactions SET
    result_code = ?,
    result_desc = ?,
    mpesa_receipt_number = ?,
    /*
    phone_number = ?,
    amount = ?,
    */
    transaction_date = ?,
    status = ?
    WHERE checkout_request_id = ?");
// ssssssss
$stmt->bind_param("isssss",
    $ResultCode,
    $ResultDesc,
    $MpesaReceiptNumber,
    // $PhoneNumber,
    // $Amount,
    $TransactionDate,
    $status,
    $CheckoutRequestId);

if ($stmt->execute()) {
    file_put_contents('logs/callback_success.log', "Transaction with ID {$CheckoutRequestId} updated successfully" . PHP_EOL, FILE_APPEND);
} else {
    file_put_contents('logs/db_update_error.log', "Failed to update transaction with ID {$CheckoutRequestId}: " . $stmt->error . PHP_EOL, FILE_APPEND);
}

// close database connection
$stmt->close();
$conn->close();