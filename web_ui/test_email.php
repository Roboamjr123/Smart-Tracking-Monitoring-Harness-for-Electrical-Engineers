<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

echo "<pre>";
echo "=== Email Configuration Test ===\n\n";

// Check 1: Vendor directory
$autoloadPath = __DIR__ . '/vendor/autoload.php';
echo "1. Checking Composer autoload...\n";
echo "   Path: $autoloadPath\n";
echo "   Exists: " . (file_exists($autoloadPath) ? "✓ YES" : "✗ NO") . "\n\n";

if (!file_exists($autoloadPath)) {
    echo "ERROR: Composer vendor directory not found!\n";
    echo "Run: composer require phpmailer/phpmailer\n";
    exit(1);
}

require_once $autoloadPath;

// Check 2: PHPMailer class
echo "2. Checking PHPMailer class...\n";
echo "   Class exists: " . (class_exists(PHPMailer::class) ? "✓ YES" : "✗ NO") . "\n\n";

// Check 3: Config file
$emailConfigPath = __DIR__ . '/email_config.php';
echo "3. Checking email_config.php...\n";
echo "   Path: $emailConfigPath\n";
echo "   Exists: " . (file_exists($emailConfigPath) ? "✓ YES" : "✗ NO") . "\n";

if(file_exists($emailConfigPath)) {
    require_once $emailConfigPath;
    echo "   ALERT_SMTP_HOST: " . (defined('ALERT_SMTP_HOST') ? ALERT_SMTP_HOST : "NOT DEFINED") . "\n";
    echo "   ALERT_SMTP_USERNAME: " . (defined('ALERT_SMTP_USERNAME') ? ALERT_SMTP_USERNAME : "NOT DEFINED") . "\n";
    echo "   ALERT_SMTP_PASSWORD: " . (defined('ALERT_SMTP_PASSWORD') ? "***SET***" : "NOT DEFINED") . "\n";
    echo "   ALERT_RECIPIENTS: " . (defined('ALERT_RECIPIENTS') ? json_encode(ALERT_RECIPIENTS) : "NOT DEFINED") . "\n";
}
echo "\n";

// Check 4: Try sending test email
echo "4. Attempting to send test email...\n\n";

$testPayload = [
    'id' => 999,
    'temperature' => 25.5,
    'humidity' => 60,
    'latitude' => 14.5995,
    'longitude' => 120.9842,
    'created_at' => date('Y-m-d H:i:s')
];

$mailer = new PHPMailer(true);

try {
    echo "   Configuring SMTP...\n";
    $mailer->isSMTP();
    $mailer->Host = ALERT_SMTP_HOST;
    $mailer->SMTPAuth = true;
    $mailer->Username = ALERT_SMTP_USERNAME;
    $mailer->Password = ALERT_SMTP_PASSWORD;
    $mailer->SMTPSecure = ALERT_SMTP_SECURE;
    $mailer->Port = ALERT_SMTP_PORT;
    $mailer->SMTPDebug = 2; // Show detailed debugging
    
    echo "\n   Setting sender...\n";
    $mailer->setFrom(ALERT_FROM_EMAIL, ALERT_FROM_NAME);
    
    echo "   Adding recipients...\n";
    foreach (ALERT_RECIPIENTS as $recipient) {
        $mailer->addAddress($recipient);
        echo "      → $recipient\n";
    }
    
    echo "\n   Composing email...\n";
    $mailer->isHTML(true);
    $mailer->Subject = "[TEST] Email Send Test - Record #{$testPayload['id']}";
    $mailer->Body = "<h2>Test Email</h2><p>If you received this, the email system is working correctly!</p>";
    $mailer->AltBody = "Test email - if received, the system works!";
    
    echo "\n   Sending...\n";
    $mailer->send();
    
    echo "\n✓ SUCCESS: Email sent!\n";
    echo "Check your inbox at " . ALERT_RECIPIENTS[0] . "\n";
    
} catch (Exception $e) {
    echo "\n✗ FAILED: " . $mailer->ErrorInfo . "\n";
    echo "Exception: " . $e->getMessage() . "\n";
} catch (\Throwable $t) {
    echo "\n✗ FAILED: " . $t->getMessage() . "\n";
}

echo "\n</pre>";
?>
