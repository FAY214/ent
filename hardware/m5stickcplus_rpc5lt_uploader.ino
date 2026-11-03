#include <M5StickCPlus.h>
#include <WiFi.h>
#include <HTTPClient.h>

// ---------------------------
// Wi-Fi and backend settings
// ---------------------------
const char* WIFI_SSID = "kkkkk";
const char* WIFI_PASS = "12345678";

// IMPORTANT:
// Use your computer LAN IP, not localhost.
// Example: http://192.168.31.20:8080/api/squeeze-event
const char* API_URL = "https://puffsqueeze-ai-m5.onrender.com/api/squeeze-event";
const int USER_ID = 1;
const char* DEVICE_ID = "M5StickCPlus_RP-C5-LT";

// ---------------------------
// Sensor tuning
// ---------------------------
#define SENSOR_PIN       36
#define SAMPLE_INTERVAL  10
#define DEBOUNCE_MS      80
#define NOISE_MULT       3.0f

int baselineValue = 0;
int smoothValue = 0;
int noiseLevel = 0;
int threshold = 0;
int pressureValue = 0;
bool lastPressed = false;
bool currentPressed = false;
unsigned long lastPressTime = 0;
unsigned long totalPresses = 0;
unsigned long lastSampleTime = 0;
unsigned long lastBaselineUpdate = 0;

void ensureWifi() {
  if (WiFi.status() == WL_CONNECTED) return;

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  M5.Lcd.setCursor(6, 20);
  M5.Lcd.setTextColor(YELLOW, BLACK);
  M5.Lcd.print("WiFi connecting...");

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(300);
    M5.Lcd.print(".");
  }

  M5.Lcd.fillRect(0, 20, 240, 14, BLACK);
  M5.Lcd.setCursor(6, 20);
  if (WiFi.status() == WL_CONNECTED) {
    M5.Lcd.setTextColor(GREEN, BLACK);
    M5.Lcd.print("WiFi connected");
  } else {
    M5.Lcd.setTextColor(RED, BLACK);
    M5.Lcd.print("WiFi failed");
  }
}

void calibrateSensor() {
  long sum = 0;
  int minVal = 4095;
  int maxVal = 0;

  for (int i = 0; i < 200; i++) {
    int v = analogRead(SENSOR_PIN);
    sum += v;
    if (v < minVal) minVal = v;
    if (v > maxVal) maxVal = v;
    delay(5);
  }

  baselineValue = sum / 200;
  noiseLevel = maxVal - minVal;
  if (noiseLevel < 5) noiseLevel = 5;
  threshold = (int)(noiseLevel * NOISE_MULT);
  if (threshold < 10) threshold = 10;
  smoothValue = baselineValue;
}

void postSqueezeEvent(int rawValue, int deltaValue) {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(API_URL);
  http.addHeader("Content-Type", "application/json");

  String body = "{";
  body += "\"user_id\":" + String(USER_ID) + ",";
  body += "\"device_id\":\"" + String(DEVICE_ID) + "\",";
  body += "\"strike_value\":" + String(deltaValue) + ",";
  body += "\"raw_data\":{";
  body += "\"raw_adc\":" + String(rawValue) + ",";
  body += "\"delta\":" + String(deltaValue) + ",";
  body += "\"threshold\":" + String(threshold) + ",";
  body += "\"count_on_device\":" + String(totalPresses);
  body += "}}";

  int code = http.POST(body);
  String resp = http.getString();
  http.end();

  Serial.printf("POST /api/squeeze-event -> %d\n", code);
  if (code < 200 || code >= 300) {
    Serial.println(resp);
  }
}

void drawStatus(int raw) {
  M5.Lcd.fillRect(0, 40, 240, 95, BLACK);
  M5.Lcd.setTextSize(1);
  M5.Lcd.setTextColor(WHITE, BLACK);

  M5.Lcd.setCursor(6, 42);
  M5.Lcd.printf("Raw:%4d  Delta:%4d  Thr:%4d", raw, pressureValue, threshold);

  M5.Lcd.setCursor(6, 58);
  M5.Lcd.printf("Total Presses: %lu", totalPresses);

  M5.Lcd.setCursor(6, 74);
  M5.Lcd.printf("WiFi: %s", WiFi.status() == WL_CONNECTED ? "OK" : "DISCONNECTED");

  M5.Lcd.setCursor(6, 90);
  M5.Lcd.printf("IP: %s", WiFi.status() == WL_CONNECTED ? WiFi.localIP().toString().c_str() : "-");

  int barW = map(pressureValue, 0, max(200, threshold * 5), 0, 220);
  barW = constrain(barW, 0, 220);
  M5.Lcd.drawRect(6, 108, 220, 10, WHITE);
  M5.Lcd.fillRect(7, 109, barW, 8, currentPressed ? RED : GREEN);
}

void setup() {
  M5.begin();
  M5.Axp.ScreenBreath(9);
  M5.Lcd.setRotation(1);
  M5.Lcd.fillScreen(BLACK);
  M5.Lcd.setTextColor(CYAN, BLACK);
  M5.Lcd.setTextSize(1);
  M5.Lcd.setCursor(6, 4);
  M5.Lcd.println("PuffSqueeze Hardware Uploader");

  Serial.begin(115200);
  pinMode(SENSOR_PIN, INPUT);
  analogSetAttenuation(ADC_11db);
  analogSetWidth(12);

  ensureWifi();
  calibrateSensor();
}

void loop() {
  M5.update();
  unsigned long now = millis();

  if (now - lastSampleTime < SAMPLE_INTERVAL) return;
  lastSampleTime = now;

  if (now - lastBaselineUpdate > 5000 && !currentPressed) {
    baselineValue = (baselineValue * 19 + smoothValue) / 20;
    lastBaselineUpdate = now;
  }

  ensureWifi();

  int raw = analogRead(SENSOR_PIN);
  smoothValue = (smoothValue * 2 + raw) / 3;
  pressureValue = abs(smoothValue - baselineValue);

  currentPressed = (pressureValue > threshold);
  if (currentPressed && !lastPressed && (now - lastPressTime > DEBOUNCE_MS)) {
    totalPresses++;
    lastPressTime = now;
    M5.Beep.tone(2100, 20);
    postSqueezeEvent(raw, pressureValue);
  }
  lastPressed = currentPressed;

  drawStatus(raw);

  if (M5.BtnA.wasPressed()) {
    calibrateSensor();
  }
}

