// Optional local USB controller for PuffSqueeze fishing. No Wi-Fi credentials.
// Configure pins for YOUR two sensor voltage-divider circuits before flashing.
// Existing uploader firmware is preserved in the parent hardware folder.
#include <M5StickCPlus.h>

const int LEFT_PIN = 36;
const int RIGHT_PIN = 32;
const unsigned long SAMPLE_MS = 10;
const unsigned long STREAM_MS = 50;
struct Sensor { int pin; float baseline; float smooth; float threshold; bool held; float level; };
Sensor leftGrip = {LEFT_PIN, 0, 0, 45, false, 0};
Sensor rightGrip = {RIGHT_PIN, 0, 0, 45, false, 0};
unsigned long lastSample = 0, lastStream = 0;

void calibrate() {
  M5.Lcd.fillScreen(BLACK); M5.Lcd.setCursor(5, 10);
  M5.Lcd.println("Release both grips...");
  long sums[2] = {0, 0}; int low[2] = {4095, 4095}, high[2] = {0, 0};
  for (int i = 0; i < 150; i++) {
    int readings[2] = {analogRead(LEFT_PIN), analogRead(RIGHT_PIN)};
    for (int j = 0; j < 2; j++) { sums[j] += readings[j]; low[j] = min(low[j], readings[j]); high[j] = max(high[j], readings[j]); }
    delay(7);
  }
  Sensor* sensors[2] = {&leftGrip, &rightGrip};
  for (int j = 0; j < 2; j++) {
    sensors[j]->baseline = sums[j] / 150.0f; sensors[j]->smooth = sensors[j]->baseline;
    sensors[j]->threshold = max(45.0f, (high[j] - low[j]) * 4.0f);
    sensors[j]->held = false; sensors[j]->level = 0;
  }
}
void sample(Sensor &sensor) {
  sensor.smooth += (analogRead(sensor.pin) - sensor.smooth) * 0.28f;
  float delta = abs(sensor.smooth - sensor.baseline);
  // Hysteresis prevents jitter near the release boundary.
  sensor.held = sensor.held ? delta > sensor.threshold * 0.55f : delta > sensor.threshold;
  float normalized = min(1.0f, delta / max(450.0f, sensor.threshold * 8.0f));
  sensor.level = sensor.held ? 0.22f + normalized * 0.78f : 0;
}
void setup() {
  M5.begin(); M5.Lcd.setRotation(1); M5.Lcd.setTextSize(1); M5.Lcd.setTextColor(WHITE, BLACK);
  Serial.begin(115200); pinMode(LEFT_PIN, INPUT); pinMode(RIGHT_PIN, INPUT);
  analogSetAttenuation(ADC_11db); analogSetWidth(12); calibrate();
}
void loop() {
  M5.update(); if (M5.BtnA.wasPressed()) calibrate();
  unsigned long now = millis();
  if (now - lastSample >= SAMPLE_MS) { lastSample = now; sample(leftGrip); sample(rightGrip); }
  if (now - lastStream >= STREAM_MS) {
    lastStream = now;
    Serial.printf("{\"type\":\"puffsqueeze:grip\",\"left\":%.3f,\"right\":%.3f}\n", leftGrip.level, rightGrip.level);
    M5.Lcd.fillRect(0, 0, 240, 135, BLACK); M5.Lcd.setCursor(5, 8);
    M5.Lcd.println("PuffSqueeze / USB fishing");
    M5.Lcd.printf("\nLeft: %.2f  Right: %.2f\n\n", leftGrip.level, rightGrip.level);
    M5.Lcd.println("Hold: lower / Release: reel\nBtn A: recalibrate (release)");
  }
}
