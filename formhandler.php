<?php
// Configuration
define('CONSUMER_KEY', 'G8TAWATIG5154hOQhNigPbwiUMeGahpWzfFW0auOh4e0krUM');
define('CONSUMER_SECRET', '98HUqbQlEoYffmKPNJn2T3nNDbJco1ASgWGXbq98VfNYKYqV9eZeWMiA7Y162sqC');
define('PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');
define('BUSINESS_SHORTCODE', '174379');
define('CALLBACK_URL', 'https://bf525480c0ed.ngrok-free.app/WBS/mpesa-callback.php');
define('ACCOUNT_REF', 'TSF');
define('TRANSACTION_DESC', 'Donation');

date_default_timezone_set('Africa/Nairobi');

// Helper functions
function validate_phone($phone) {
    return preg_match('/^(2547|2541)\d{8}$/', $phone);
}

function validate_amount($amount) {
    return is_numeric($amount) && $amount > 0;
}

function get_access_token() {
    $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode(CONSUMER_KEY . ':' . CONSUMER_SECRET);

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($status != 200) {
        return false;
    }

    $result = json_decode($response);
    return $result->access_token ?? false;
}

function initiate_stk_push($access_token, $phone, $amount) {
    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $timestamp = date('YmdHis');
    $password = base64_encode(BUSINESS_SHORTCODE . PASSKEY . $timestamp);

    $payload = [
        'BusinessShortCode' => BUSINESS_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => strval($amount),
        'PartyA' => strval($phone),
        'PartyB' => BUSINESS_SHORTCODE,
        'PhoneNumber' => strval($phone),
        'CallBackURL' => CALLBACK_URL,
        'AccountReference' => ACCOUNT_REF,
        'TransactionDesc' => TRANSACTION_DESC
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $status == 200 ? json_decode($response, true) : false;
}

// Main logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'], $_POST['amount'])) {
    $phone = $_POST['phone'];
    $amount = $_POST['amount'];

    if (!validate_phone($phone)) {
        echo "Invalid phone number format.";
        exit();
    }

    if (!validate_amount($amount)) {
        echo "Invalid amount.";
        exit();
    }

    $access_token = get_access_token();
    if (!$access_token) {
        echo "Failed to get access token.";
        exit();
    }

    $response = initiate_stk_push($access_token, $phone, $amount);
    if ($response && isset($response['ResponseCode']) && $response['ResponseCode'] === '0') {
        $merchantRequestID = $response['MerchantRequestID'];
        $checkoutRequestID = $response['CheckoutRequestID'];

        // connect to the database
        $db_conn = mysqli_connect("localhost", "root", "admin", "mpesa_transactions");
        if ($db_conn->connect_error) {
            file_put_contents("logs/db_error.log", $db_conn->connect_error . PHP_EOL, FILE_APPEND);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to connect to the database'
            ]);
            exit();
        }

        // Prepare the SQL query
        $sql = $db_conn->prepare("INSERT INTO stk_transactions
        (merchant_request_id, checkout_request_id, phone_number, amount, account_ref, status)
        VALUES (?, ?, ?, ?, ?, ?)");
        $status = 'Pending'; // will be updated later by callback

        $sql->bind_param("ssssss",
            $merchantRequestID,
            $checkoutRequestID,
            strval($phone),
            strval($amount),
            ACCOUNT_REF,
            $status
        );

        if (!$sql->execute()) {
            file_put_contents("logs/db_insert_error.log", $sql->error . PHP_EOL, FILE_APPEND);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to insert transaction details into the database'
            ]);
        } else {
            echo json_encode([
                'status' => 'Pending',
                'message' => 'STK Push initiated successfully! Check your phone for the payment request.',
                'checkout_request_id' => $checkoutRequestID
            ]);
            exit();
        }
        // close connection
        $sql->close();
        $db_conn->close();
    } else {
        echo "Failed to initiate STK Push.";
    }
} else {
    header("Location: http://localhost:8080/WBS/wbs.html");
    exit();
}