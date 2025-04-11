<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = $_POST['phone'];

    if (empty($phone)) {
        echo "<div class='alert alert-danger'>Please enter a phone number.</div>";
        header('Location: /WBS/wbs.html');
        exit;
    }

    // Convert to M-Pesa format
    $phone = '254' . substr($phone, 1);
    $formatted_phone = (string)$phone;

    // echo "<div class='alert alert-success'>Phone number converted to M-Pesa format: $formatted_phone</div>";

    date_default_timezone_set('Africa/Nairobi');

    $consumer_key = 'G8TAWATIG5154hOQhNigPbwiUMeGahpWzfFW0auOh4e0krUM';
    $consumer_secret = '98HUqbQlEoYffmKPNJn2T3nNDbJco1ASgWGXbq98VfNYKYqV9eZeWMiA7Y162sqC';

    // Get access token
    $access_token_url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode($consumer_key . ':' . $consumer_secret);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $access_token_url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($status != 200) {
        echo "<div class='alert alert-danger'>Failed to get access token. Status code: $status</div>";
        exit;
    }

    $result = json_decode($result);
    $access_token = $result->access_token;

    // STK Push request
    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    $callback_url = 'https://ba9e-169-150-218-130.ngrok-free.app/Mpesa-Daraja-Api/callback.php';
    $passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
    $buz_shortcode = '174379';
    $time_stamp = date('YmdHis');
    $password = base64_encode($buz_shortcode . $passkey . $time_stamp);
    $amount = '1';
    $partyA = $formatted_phone;
    $partyB = $buz_shortcode;
    $account_ref = 'TSF';
    $transaction_desc = 'Donation';

    $stkpush_header = ['Content-Type: application/json', 'Authorization: Bearer ' . $access_token];
    $curl_post_data = [
        'BusinessShortCode' => $buz_shortcode,
        'Password' => $password,
        'Timestamp' => $time_stamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $partyA,
        'PartyB' => $partyB,
        'PhoneNumber' => $partyA,
        'CallBackURL' => $callback_url,
        'AccountReference' => $account_ref,
        'TransactionDesc' => $transaction_desc
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $stkpush_header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($curl_post_data));
    $curl_response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($status == 200) {
        echo "<div class='alert alert-success'>STK Push initiated successfully. Response: $curl_response</div>";
    } else {
        echo "<div class='alert alert-danger'>Failed to initiate STK Push. Status code: $status. Response: $curl_response</div>";
    }
}