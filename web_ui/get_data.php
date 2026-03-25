<?php

header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "iot_logs";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => "Connection failed: " . $conn->connect_error]));
}

$response = [];

// Get latest sensor data including fall and GPS fields.
$latest_sql = "SELECT id, temperature, humidity, crash, latitude, longitude FROM sensor_logs ORDER BY id DESC LIMIT 1";
$latest_result = $conn->query($latest_sql);

if ($latest_result === false) {
    http_response_code(500);
    die(json_encode(['error' => "Query failed: " . $conn->error]));
}

if ($latest_result && $latest_result->num_rows > 0) {
    $response['latest'] = $latest_result->fetch_assoc();
}

// Get recent records for table.
$all_sql = "SELECT id, temperature, humidity, crash, latitude, longitude FROM sensor_logs ORDER BY id DESC LIMIT 100";
$all_result = $conn->query($all_sql);

if ($all_result === false) {
    http_response_code(500);
    die(json_encode(['error' => "Query failed: " . $conn->error]));
}

$records = [];
while($row = $all_result->fetch_assoc()) {
    $row['crash'] = intval($row['crash']);
    $records[] = $row;
}
$response['records'] = array_reverse($records);

// Get statistics focused on worker safety and environment data.
$stats_sql = "SELECT
    COUNT(*) as total,
    AVG(temperature) as avg_temp,
    MAX(temperature) as max_temp,
    MIN(temperature) as min_temp,
    SUM(CASE WHEN crash = 1 THEN 1 ELSE 0 END) as crash_count
FROM sensor_logs";
$stats_result = $conn->query($stats_sql);

if ($stats_result) {
    $stats = $stats_result->fetch_assoc();
    $response['stats'] = [
        'total' => $stats['total'],
        'avg_temp' => $stats['avg_temp'] !== null ? round($stats['avg_temp'], 2) : 0,
        'max_temp' => $stats['max_temp'] !== null ? round($stats['max_temp'], 2) : 0,
        'min_temp' => $stats['min_temp'] !== null ? round($stats['min_temp'], 2) : 0,
        'crash_count' => intval($stats['crash_count'])
    ];
}

$response['active_alert'] = isset($response['latest']) && intval($response['latest']['crash']) === 1;

$conn->close();

echo json_encode($response);

?>
