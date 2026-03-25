<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Keep SMTP secrets in a separate local file that is not committed.
$emailConfigPath = __DIR__ . '/email_config.php';
if (file_exists($emailConfigPath)) {
    require_once $emailConfigPath;
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "iot_logs";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/**
 * Sends a crash email through Gmail SMTP when configured.
 */
function sendCrashEmail(array $payload): array
{
    if (!class_exists(PHPMailer::class)) {
        error_log('Error: PHPMailer class not found - vendor/autoload.php missing');
        return ['sent' => false, 'reason' => 'PHPMailer dependency missing'];
    }

    if (!defined('ALERT_SMTP_HOST')) {
        error_log('Error: email_config.php constants not defined');
        return ['sent' => false, 'reason' => 'email_config.php is missing'];
    }

    error_log('Starting email send for record: ' . $payload['id']);
    $mailer = new PHPMailer(true);

    try {
        error_log('Configuring SMTP...');
        $mailer->isSMTP();
        $mailer->Host = ALERT_SMTP_HOST;
        $mailer->SMTPAuth = true;
        $mailer->Username = ALERT_SMTP_USERNAME;
        $mailer->Password = ALERT_SMTP_PASSWORD;
        $mailer->SMTPSecure = ALERT_SMTP_SECURE;
        $mailer->Port = ALERT_SMTP_PORT;
        $mailer->SMTPDebug = 0; // Set to 2 for verbose debugging

        $mailer->setFrom(ALERT_FROM_EMAIL, ALERT_FROM_NAME);

        $recipientList = ALERT_RECIPIENTS;
        foreach ($recipientList as $recipient) {
            $mailer->addAddress($recipient);
            error_log("Adding recipient: $recipient");
        }

        $mapsUrl = "https://www.google.com/maps?q={$payload['latitude']},{$payload['longitude']}";

        $mailer->isHTML(true);
        $mailer->Subject = "[ALERT] Worker crash detected";
        $mailer->Body = "
            <h2>Crash Alert</h2>
            <p>A possible worker crash was detected</p>
            <ul>
                <li><strong>Temperature:</strong> {$payload['temperature']} C</li>
                <li><strong>Humidity:</strong> {$payload['humidity']} %</li>
                <li><strong>Latitude:</strong> {$payload['latitude']}</li>
                <li><strong>Longitude:</strong> {$payload['longitude']}</li>
                <li><strong>Time:</strong> {$payload['created_at']}</li>
            </ul>
            <p><a href='{$mapsUrl}' target='_blank' rel='noopener'>Open Location in Google Maps</a></p>
        ";
        $mailer->AltBody = "Crash detected. Temp {$payload['temperature']} C, Humidity {$payload['humidity']} %, Location {$payload['latitude']}, {$payload['longitude']}, Time {$payload['created_at']}, Map: {$mapsUrl}";

        error_log('Sending email...');
        $mailer->send();
        error_log('Email sent successfully for record: ' . $payload['id']);
        return ['sent' => true, 'reason' => 'email_sent'];
    } catch (Exception $e) {
        $errorMsg = $mailer->ErrorInfo ?: $e->getMessage();
        error_log('Email send failed: ' . $errorMsg);
        return ['sent' => false, 'reason' => $errorMsg];
    }
}

/**
 * Creates state storage used for debounced crash/safe transitions.
 */
function ensureAlertStateTable(mysqli $conn): bool
{
    $createSql = "CREATE TABLE IF NOT EXISTS alert_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        is_crash_active TINYINT(1) NOT NULL DEFAULT 0,
        crash_streak INT NOT NULL DEFAULT 0,
        safe_streak INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conn->query($createSql)) {
        error_log('Failed to create alert_state table: ' . $conn->error);
        return false;
    }

    $seedSql = "INSERT IGNORE INTO alert_state (id, is_crash_active, crash_streak, safe_streak) VALUES (1, 0, 0, 0)";
    if (!$conn->query($seedSql)) {
        error_log('Failed to seed alert_state row: ' . $conn->error);
        return false;
    }

    return true;
}

/**
 * Reads current alert state for incident transition logic.
 */
function getAlertState(mysqli $conn): ?array
{
    $result = $conn->query("SELECT is_crash_active, crash_streak, safe_streak FROM alert_state WHERE id = 1 LIMIT 1");

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    $row = $result->fetch_assoc();
    return [
        'is_crash_active' => isset($row['is_crash_active']) ? intval($row['is_crash_active']) : 0,
        'crash_streak' => isset($row['crash_streak']) ? intval($row['crash_streak']) : 0,
        'safe_streak' => isset($row['safe_streak']) ? intval($row['safe_streak']) : 0,
    ];
}

/**
 * Persists updated incident state.
 */
function saveAlertState(mysqli $conn, array $state): bool
{
    $stmt = $conn->prepare("UPDATE alert_state SET is_crash_active = ?, crash_streak = ?, safe_streak = ? WHERE id = 1");
    if (!$stmt) {
        return false;
    }

    $isCrashActive = isset($state['is_crash_active']) ? intval($state['is_crash_active']) : 0;
    $crashStreak = isset($state['crash_streak']) ? intval($state['crash_streak']) : 0;
    $safeStreak = isset($state['safe_streak']) ? intval($state['safe_streak']) : 0;

    $stmt->bind_param("iii", $isCrashActive, $crashStreak, $safeStreak);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Computes next effective crash state and whether a new alert email should be sent.
 */
function resolveIncidentTransition(
    int $incomingCrash,
    array $currentState,
    int $crashConfirmCount,
    int $safeConfirmCount
): array {
    $incoming = $incomingCrash === 1 ? 1 : 0;
    $isActive = isset($currentState['is_crash_active']) ? intval($currentState['is_crash_active']) : 0;
    $crashStreak = isset($currentState['crash_streak']) ? intval($currentState['crash_streak']) : 0;
    $safeStreak = isset($currentState['safe_streak']) ? intval($currentState['safe_streak']) : 0;

    if ($incoming === 1) {
        $crashStreak++;
        $safeStreak = 0;
    } else {
        $safeStreak++;
        $crashStreak = 0;
    }

    $shouldSendEmail = false;

    if ($isActive === 0 && $crashStreak >= $crashConfirmCount) {
        $isActive = 1;
        $shouldSendEmail = true;
        $crashStreak = 0;
        $safeStreak = 0;
    } elseif ($isActive === 1 && $safeStreak >= $safeConfirmCount) {
        $isActive = 0;
        $crashStreak = 0;
        $safeStreak = 0;
    }

    return [
        'effective_crash' => $isActive,
        'should_send_email' => $shouldSendEmail,
        'next_state' => [
            'is_crash_active' => $isActive,
            'crash_streak' => $crashStreak,
            'safe_streak' => $safeStreak,
        ],
    ];
}

if (
    isset($_POST['temperature']) &&
    isset($_POST['humidity']) &&
    isset($_POST['crash'])
) {
    $temp = floatval($_POST['temperature']);
    $hum = floatval($_POST['humidity']);
    $incomingCrash = intval($_POST['crash']) === 1 ? 1 : 0;

    $crashConfirmCount = defined('ALERT_CRASH_CONFIRM_COUNT') ? max(1, intval(ALERT_CRASH_CONFIRM_COUNT)) : 2;
    $safeConfirmCount = defined('ALERT_SAFE_CONFIRM_COUNT') ? max(1, intval(ALERT_SAFE_CONFIRM_COUNT)) : 2;

    $crash = $incomingCrash;
    $shouldSendEmail = false;
    $nextState = null;

    if (ensureAlertStateTable($conn)) {
        $currentState = getAlertState($conn);
        if ($currentState !== null) {
            $transition = resolveIncidentTransition($incomingCrash, $currentState, $crashConfirmCount, $safeConfirmCount);
            $crash = intval($transition['effective_crash']);
            $shouldSendEmail = !empty($transition['should_send_email']);
            $nextState = $transition['next_state'];

            error_log("Incident transition: incoming={$incomingCrash}, effective={$crash}, send_email=" . ($shouldSendEmail ? '1' : '0'));
        }
    }

    // GPS can be optional depending on ESP32 firmware payload.
    $lat = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0.0;
    $lng = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0.0;

    $stmt = $conn->prepare("INSERT INTO sensor_logs (temperature, humidity, crash, latitude, longitude) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ddidd", $temp, $hum, $crash, $lat, $lng);

    if ($stmt->execute()) {
        $insertedId = $conn->insert_id;

        if (is_array($nextState) && !saveAlertState($conn, $nextState)) {
            error_log('Failed to persist alert_state for record ' . $insertedId);
        }

        if ($shouldSendEmail && $crash === 1) {
            error_log("CRASH DETECTED - Record ID: $insertedId");

            $latestStmt = $conn->prepare("SELECT id, temperature, humidity, latitude, longitude, created_at FROM sensor_logs WHERE id = ? LIMIT 1");

            if ($latestStmt) {
                $latestStmt->bind_param("i", $insertedId);
                $latestStmt->execute();
                $latestResult = $latestStmt->get_result();

                if ($latestResult && $latestResult->num_rows > 0) {
                    $payload = $latestResult->fetch_assoc();
                    $emailResult = sendCrashEmail($payload);

                    if ($emailResult['sent']) {
                        error_log("Crash email sent successfully for record $insertedId");
                    } else {
                        error_log('Crash email not sent: ' . $emailResult['reason']);
                    }
                }

                $latestStmt->close();
            } else {
                error_log('Failed to prepare query for crash record');
            }
        }

        echo "Data inserted successfully";
    } else {
        echo "Insert failed: " . $stmt->error;
    }

    $stmt->close();
} else {
    echo "Missing required fields: temperature, humidity, crash.";
}

$conn->close();

?>