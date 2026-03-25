<?php

// Copy this file to email_config.php and update values.

// Gmail SMTP settings.
define('ALERT_SMTP_HOST', 'smtp.gmail.com');
define('ALERT_SMTP_PORT', 587);
define('ALERT_SMTP_SECURE', 'tls');
define('ALERT_SMTP_USERNAME', 'roboamdosdosbrainchild@gmail.com');
// Use a Gmail App Password, not your normal Gmail password.
define('ALERT_SMTP_PASSWORD', 'ifah byhn jgyu felw');

// Sender identity.
define('ALERT_FROM_EMAIL', 'roboamdosdosbrainchild@gmail.com');
define('ALERT_FROM_NAME', 'EMERGENCY');

// Add one or more recipients.
define('ALERT_RECIPIENTS', [
    'roboamdosdosbrainchild@gmail.com'
]);

// This project uses transition-based alerts: send once on 0 -> 1.
define('ALERT_CRASH_CONFIRM_COUNT', 1);
define('ALERT_SAFE_CONFIRM_COUNT', 1);
