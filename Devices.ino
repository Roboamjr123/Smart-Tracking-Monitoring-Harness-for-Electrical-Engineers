#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <TinyGPS++.h>

// -------------------- Network / Server --------------------
const char* ssid = "realme";
const char* password = "00000000";
const char* serverName = "http://10.249.1.247/esp_32_api/insert.php";

// -------------------- Pins --------------------
#define DHTPIN 4
#define DHTTYPE DHT11

#define BUTTON_CRASH_PIN 27   // manual push button crash trigger
#define VIBRATION_PIN    14   // SW-420 DO pin
#define BUZZER_PIN       26   // active buzzer HIGH=ON

#define GPS_RX 16
#define GPS_TX 17

// -------------------- Behavior --------------------
const unsigned long SEND_INTERVAL_MS = 1000;   // API send interval
const unsigned long CRASH_HOLD_MS    = 5000;   // keep crash=1 for 5s
const unsigned long WIFI_RETRY_MS    = 5000;
const uint16_t HTTP_TIMEOUT_MS       = 5000;

// Button debounce
const unsigned long BUTTON_DEBOUNCE_MS = 30;

// SW-420 filtering (tune if too sensitive/noisy)
const int VIB_ACTIVE_LEVEL = HIGH;             // most SW-420 modules: HIGH on vibration
const uint8_t VIB_CONFIRM_COUNT = 3;           // consecutive hits required for server
const unsigned long VIB_SAMPLE_MS = 10;

// Buzzer pattern (non-blocking)
const unsigned long BUZZER_ON_MS  = 180;
const unsigned long BUZZER_OFF_MS = 220;

// -------------------- Objects --------------------
DHT dht(DHTPIN, DHTTYPE);
TinyGPSPlus gps;
HardwareSerial gpsSerial(2);

// -------------------- State --------------------
unsigned long lastSendMs = 0;
unsigned long lastWifiRetryMs = 0;
unsigned long crashUntilMs = 0;

// button state
int lastButtonRaw = HIGH;
int buttonStableState = HIGH;
unsigned long lastButtonChangeMs = 0;

// vibration state
unsigned long lastVibSampleMs = 0;
uint8_t vibHitCount = 0;
bool vibBuzzerTriggered = false;  // IMPROVED: immediate buzzer on first hit

// buzzer state
bool buzzerOn = false;
unsigned long buzzerTickMs = 0;

// -------------------- Helpers --------------------
void connectWiFiIfNeeded() {
  if (WiFi.status() == WL_CONNECTED) return;

  unsigned long now = millis();
  if ((now - lastWifiRetryMs) < WIFI_RETRY_MS) return;
  lastWifiRetryMs = now;

  Serial.println("[WiFi] Connecting...");
  WiFi.disconnect(true, true);
  WiFi.begin(ssid, password);
}

void readGpsStream() {
  while (gpsSerial.available() > 0) {
    gps.encode(gpsSerial.read());
  }
}

void triggerCrashHold(const char* source) {
  crashUntilMs = millis() + CRASH_HOLD_MS;
  Serial.print("[CRASH] Triggered by ");
  Serial.println(source);
}

void processManualButton() {
  int raw = digitalRead(BUTTON_CRASH_PIN);
  unsigned long now = millis();

  // detect raw change
  if (raw != lastButtonRaw) {
    lastButtonRaw = raw;
    lastButtonChangeMs = now;
  }

  // debounce stable state
  if ((now - lastButtonChangeMs) >= BUTTON_DEBOUNCE_MS) {
    if (buttonStableState != raw) {
      buttonStableState = raw;

      // active LOW button press event
      if (buttonStableState == LOW) {
        triggerCrashHold("manual button");
      }
    }
  }
}

// IMPROVED: Separate logic for immediate buzzer vs. server-confirmed crash
void processVibrationSensor() {
  unsigned long now = millis();
  if ((now - lastVibSampleMs) < VIB_SAMPLE_MS) return;
  lastVibSampleMs = now;

  int raw = digitalRead(VIBRATION_PIN);
  bool hit = (raw == VIB_ACTIVE_LEVEL);

  if (hit) {
    // IMPROVED: Trigger buzzer IMMEDIATELY on first vibration hit
    if (!vibBuzzerTriggered) {
      Serial.println("[VIB] Shake detected! Buzzer triggered immediately.");
      vibBuzzerTriggered = true;
      triggerCrashHold("SW-420 vibration (immediate beep)");
    }

    // Accumulate hits for server confirmation
    if (vibHitCount < 255) vibHitCount++;
    
    // Server confirmation still requires 3 hits
    if (vibHitCount >= VIB_CONFIRM_COUNT) {
      Serial.printf("[VIB] Confirmed after %d hits - sending to server\n", VIB_CONFIRM_COUNT);
      vibHitCount = 0;
      vibBuzzerTriggered = false;
    }
  } else {
    // Reset when vibration stops
    vibHitCount = 0;
    vibBuzzerTriggered = false;
  }
}

int computeCrashState() {
  processManualButton();
  processVibrationSensor();

  // overflow-safe hold check
  return ((long)(millis() - crashUntilMs) < 0) ? 1 : 0;
}

void updateBuzzer(int crash) {
  unsigned long now = millis();

  if (crash == 1) {
    if (buzzerOn) {
      if ((now - buzzerTickMs) >= BUZZER_ON_MS) {
        buzzerOn = false;
        buzzerTickMs = now;
        digitalWrite(BUZZER_PIN, LOW);
      }
    } else {
      if ((now - buzzerTickMs) >= BUZZER_OFF_MS) {
        buzzerOn = true;
        buzzerTickMs = now;
        digitalWrite(BUZZER_PIN, HIGH);
      }
    }
  } else {
    buzzerOn = false;
    buzzerTickMs = now;
    digitalWrite(BUZZER_PIN, LOW);
  }
}

bool postToServer(float temp, float hum, int crash, double lat, double lng) {
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(serverName);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String body;
  body.reserve(150);
  body  = "temperature=" + String(temp, 2);
  body += "&humidity=" + String(hum, 2);
  body += "&crash=" + String(crash);
  body += "&latitude=" + String(lat, 6);
  body += "&longitude=" + String(lng, 6);

  int code = http.POST(body);
  Serial.printf("[HTTP] code=%d crash=%d\n", code, crash);

  if (code > 0) {
    Serial.println("[HTTP] " + http.getString());
    http.end();
    return true;
  } else {
    Serial.println("[HTTP] Error: " + http.errorToString(code));
    http.end();
    return false;
  }
}

// -------------------- Setup / Loop --------------------
void setup() {
  Serial.begin(115200);

  dht.begin();
  gpsSerial.begin(9600, SERIAL_8N1, GPS_RX, GPS_TX);

  // Manual button: INPUT_PULLUP (press -> LOW)
  pinMode(BUTTON_CRASH_PIN, INPUT_PULLUP);

  // SW-420: often stable on INPUT; if floating/noisy, use INPUT_PULLUP and invert VIB_ACTIVE_LEVEL
  pinMode(VIBRATION_PIN, INPUT);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  WiFi.begin(ssid, password);
  Serial.println("[BOOT] Starting...");
}

void loop() {
  readGpsStream();
  connectWiFiIfNeeded();

  // keep alert logic responsive at high rate
  int crash = computeCrashState();
  updateBuzzer(crash);

  unsigned long now = millis();
  if ((now - lastSendMs) < SEND_INTERVAL_MS) return;
  lastSendMs = now;

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] Not connected, skipping send.");
    return;
  }

  float temp = dht.readTemperature();
  float hum = dht.readHumidity();
  if (isnan(temp) || isnan(hum)) {
    Serial.println("[DHT] Read failed, skipping.");
    return;
  }

  double lat = 10.296443;
  double lng = 123.897301;
  if (gps.location.isValid()) {
    lat = gps.location.lat();
    lng = gps.location.lng();
  }

  Serial.printf("[DATA] T=%.2f H=%.2f crash=%d lat=%.6f lng=%.6f\n",
                temp, hum, crash, lat, lng);

  // 1 retry only (short pause)
  if (!postToServer(temp, hum, crash, lat, lng)) {
    delay(200);
    postToServer(temp, hum, crash, lat, lng);
  }
}