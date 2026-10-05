<?php
/**
 * SHEDDINGTIN — Inquiry form handler
 * Receives the contact form POST and adds the person to the Brevo mailing list,
 * including their interest and message as contact attributes.
 *
 * All emailing (internal notification to hello@sheddingtin.com, welcome email,
 * and the Day 2 / Day 5 follow-ups) is now handled entirely by a Brevo
 * Automation workflow, triggered by "contact added to list". This file's only
 * job is getting the contact + their details into Brevo correctly.
 *
 * IMPORTANT: This file must live on the server (cPanel) and never be exposed
 * client-side. The API key below only works because this script runs on the
 * server — it is never sent to the visitor's browser.
 */

header('Content-Type: application/json');

// ---- CONFIG ----
// The real key lives in config.php next to this file on the server.
// config.php is gitignored and never committed (see config.example.php).
$config = require __DIR__ . '/config.php';
$brevoApiKey = $config['brevo_api_key'];
$brevoListId = 4; // "Sheddingtin" list in Brevo

// ---- READ + VALIDATE INPUT ----
$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$interest = trim($_POST['interest'] ?? 'General Enquiry');
$message  = trim($_POST['message'] ?? '');
$consent  = isset($_POST['consent']) && $_POST['consent'] === 'on';

if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide a valid name and email.']);
    exit;
}

if (!$consent) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please agree to be contacted before submitting.']);
    exit;
}

// ---- Add contact to Brevo, with INTEREST and MESSAGE as attributes ----
// NOTE: INTEREST and MESSAGE must exist as custom attributes in your Brevo
// account first (Contacts → Settings → Attributes → add as Text type) or
// Brevo will silently ignore them.
$contactPayload = json_encode([
    'email' => $email,
    'attributes' => [
        'FIRSTNAME' => $name,
        'INTEREST'  => $interest,
        'MESSAGE'   => $message,
    ],
    'listIds' => [$brevoListId],
    'updateEnabled' => true, // if they already exist, just update instead of erroring
]);

$ch = curl_init('https://api.brevo.com/v3/contacts');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $contactPayload,
    CURLOPT_HTTPHEADER => [
        'accept: application/json',
        'content-type: application/json',
        'api-key: ' . $brevoApiKey,
    ],
    CURLOPT_TIMEOUT => 10,
]);
$contactResponse = curl_exec($ch);
$contactHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$contactOk = in_array($contactHttpCode, [200, 201, 204]);

error_log('SHEDDINGTIN inquiry debug: ' . json_encode([
    'contact_http_code' => $contactHttpCode,
    'contact_response' => json_decode($contactResponse),
]));

// ---- RESPOND ----
if ($contactOk) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => 'Could not reach Brevo. Check that the API key is active.',
        'debug' => [
            'contact_http_code' => $contactHttpCode,
            'contact_response' => json_decode($contactResponse),
        ],
    ]);
}
