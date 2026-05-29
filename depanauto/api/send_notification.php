<?php
// api/send_notification.php
// Trimite notificare push prin Firebase FCM V1 API
// Folosit intern de create_request.php

require_once 'config.php';

function sendPushNotification($fcmToken, $title, $body, $data = []) {
    if (empty($fcmToken)) return false;

    $projectId = 'depanauto-8211a';
    $credentialsFile = __DIR__ . '/firebase-credentials.json';

    // Obtine access token OAuth2 din service account
    $accessToken = getFirebaseAccessToken($credentialsFile);
    if (!$accessToken) return false;

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $message = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => array_map('strval', $data),
            'webpush' => [
                'notification' => [
                    'title'   => $title,
                    'body'    => $body,
                    'icon'    => '/depanauto/icon.png',
                    'vibrate' => [200, 100, 200],
                    'requireInteraction' => true,
                ],
                'fcm_options' => [
                    'link' => '/depanauto/depanator/'
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($message),
        CURLOPT_TIMEOUT    => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

function getFirebaseAccessToken($credentialsFile) {
    if (!file_exists($credentialsFile)) return null;

    $credentials = json_decode(file_get_contents($credentialsFile), true);
    if (!$credentials) return null;

    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signingInput = $header . '.' . $payload;
    $privateKey = openssl_pkey_get_private($credentials['private_key']);
    if (!$privateKey) return null;

    openssl_sign($signingInput, $signature, $privateKey, 'SHA256');
    $jwt = $signingInput . '.' . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $response['access_token'] ?? null;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
?>
