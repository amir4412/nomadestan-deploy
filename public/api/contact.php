<?php
/**
 * Nomadestan Contact Form Handler
 * Receives form submissions via POST and sends formatted emails
 * Deployed on Hostinger — uses PHP mail() which works with Hostinger's built-in SMTP
 */

// CORS headers for the frontend
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://nomadestan.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Rate limiting via session (simple, no database needed)
session_start();
$now = time();
$cooldown = 60; // 1 minute between submissions
if (isset($_SESSION['last_submission']) && ($now - $_SESSION['last_submission']) < $cooldown) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Please wait a moment before submitting again.']);
    exit();
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit();
}

// Validate required fields
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$message = trim($input['message'] ?? '');
$inquiryType = trim($input['inquiryType'] ?? 'general');

if (empty($name) || empty($email) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Name, email, and message are required.']);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit();
}

// Honeypot check (anti-spam)
if (!empty($input['website'] ?? '')) {
    // Bot detected — silently succeed to not tip off the bot
    echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);
    exit();
}

// Optional fields
$phone = trim($input['phone'] ?? '');
$groupSize = trim($input['groupSize'] ?? '');
$preferredDates = trim($input['preferredDates'] ?? '');
$experienceLevel = trim($input['experienceLevel'] ?? '');
$destination = trim($input['destination'] ?? '');
$budget = trim($input['budget'] ?? '');

// Map inquiry type to readable label
$inquiryLabels = [
    'upcoming' => 'Upcoming Trip Inquiry',
    'custom' => 'Custom Expedition Request',
    'backpacking' => 'Backpacking Trip Inquiry',
    'general' => 'General Inquiry',
];
$inquiryLabel = $inquiryLabels[$inquiryType] ?? 'General Inquiry';

// Build email subject
$subject = "[Nomadestan] {$inquiryLabel} from {$name}";

// Build email body (plain text, well-formatted)
$body = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$body .= "  NEW CONTACT FORM SUBMISSION\n";
$body .= "  {$inquiryLabel}\n";
$body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$body .= "FROM:\n";
$body .= "  Name:  {$name}\n";
$body .= "  Email: {$email}\n";
if ($phone) $body .= "  Phone: {$phone}\n";
$body .= "\n";

if ($groupSize || $preferredDates || $experienceLevel || $destination || $budget) {
    $body .= "TRIP DETAILS:\n";
    if ($groupSize) $body .= "  Group Size:       {$groupSize}\n";
    if ($preferredDates) $body .= "  Preferred Dates:  {$preferredDates}\n";
    if ($experienceLevel) $body .= "  Experience Level: {$experienceLevel}\n";
    if ($destination) $body .= "  Destination:      {$destination}\n";
    if ($budget) $body .= "  Budget Range:     {$budget}\n";
    $body .= "\n";
}

$body .= "MESSAGE:\n";
$body .= "──────────────────────────────────────────\n";
$body .= wordwrap($message, 70, "\n") . "\n";
$body .= "──────────────────────────────────────────\n\n";

$body .= "Submitted: " . date('F j, Y \a\t g:i A T') . "\n";
$body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

// Email headers
$to = 'hello@nomadestan.com';
$headers = "From: Nomadestan Contact Form <noreply@nomadestan.com>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "X-Mailer: Nomadestan Contact Form\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

// Send the email
$sent = mail($to, $subject, $body, $headers);

if ($sent) {
    // Update rate limit
    $_SESSION['last_submission'] = $now;
    
    // Send auto-reply to the user
    $autoReplySubject = "We received your message — Nomadestan";
    $autoReplyBody = "Hi {$name},\n\n";
    $autoReplyBody .= "Thanks for reaching out! We've received your {$inquiryLabel} and will get back to you within 24–48 hours.\n\n";
    $autoReplyBody .= "In the meantime, feel free to explore our upcoming trips at https://nomadestan.com/trips/upcoming\n\n";
    $autoReplyBody .= "— Amir\n";
    $autoReplyBody .= "Nomadestan | Persian-Rooted. Adventure-Driven.\n";
    $autoReplyBody .= "https://nomadestan.com\n";
    
    $autoReplyHeaders = "From: Amir @ Nomadestan <hello@nomadestan.com>\r\n";
    $autoReplyHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    mail($email, $autoReplySubject, $autoReplyBody, $autoReplyHeaders);
    
    echo json_encode(['success' => true, 'message' => 'Message sent successfully. Check your email for a confirmation.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to send message. Please try emailing us directly at hello@nomadestan.com']);
}
