/*
 * ESP32-S3: two copper-foil capacitive touch inputs.
 * Change LEFT_PIN / RIGHT_PIN to match your wiring before uploading.
 * Copper foil -> optional 510 ohm series resistor -> touch GPIO.
 * The foil is an electrode: do not connect it to 3V3, 5V or GND.
 * Serial Monitor: 115200 baud. Commands: c=calibrate, j=JSON, r=readable.
 */
#include <Arduino.h>

#if !defined(CONFIG_IDF_TARGET_ESP32S3)
#error "Select an ESP32-S3 board in Arduino IDE."
#endif

constexpr uint8_t LEFT_PIN = 12;
constexpr uint8_t RIGHT_PIN = 13;
static_assert(LEFT_PIN >= 1 && LEFT_PIN <= 14, "S3 touch needs GPIO1..14");
static_assert(RIGHT_PIN >= 1 && RIGHT_PIN <= 14, "S3 touch needs GPIO1..14");
static_assert(LEFT_PIN != RIGHT_PIN, "Use two different touch GPIOs");

// Starting sensitivity only; tune after observing your actual foil and wiring.
constexpr float PRESS_RISE = 0.20f;  // 20% above untouched baseline.
constexpr float RELEASE_RISE = 0.10f;
constexpr uint32_t SAMPLE_MS = 20;
constexpr uint32_t PRINT_MS = 100;
constexpr uint8_t DEBOUNCE_SAMPLES = 3;

struct TouchPad {
  uint8_t pin;
  uint32_t raw;
  float baseline;
  float filtered;
  float onDelta;
  float offDelta;
  bool touched;
  uint8_t changes;
};

TouchPad leftPad = {LEFT_PIN, 0, 0, 0, 0, 0, false, 0};
TouchPad rightPad = {RIGHT_PIN, 0, 0, 0, 0, 0, false, 0};
bool jsonMode = false;
uint32_t lastSample = 0;
uint32_t lastPrint = 0;

uint32_t readPad(uint8_t pin) {
  uint64_t sum = 0;
  for (int i = 0; i < 4; ++i) sum += touchRead(pin);
  return static_cast<uint32_t>(sum / 4);
}

void calibrate() {
  if (!jsonMode) Serial.println("Release both copper pads. Calibrating...");
  delay(1200);
  // Warm up both touch channels before collecting their baselines.
  for (int i = 0; i < 12; ++i) {
    readPad(LEFT_PIN); readPad(RIGHT_PIN); delay(10);
  }
  TouchPad* pads[] = {&leftPad, &rightPad};
  uint64_t sums[2] = {0, 0};
  uint32_t lows[2] = {UINT32_MAX, UINT32_MAX};
  uint32_t highs[2] = {0, 0};
  constexpr int count = 64;
  for (int i = 0; i < count; ++i) {
    for (int j = 0; j < 2; ++j) {
      uint32_t value = readPad(pads[j]->pin);
      sums[j] += value;
      if (value < lows[j]) lows[j] = value;
      if (value > highs[j]) highs[j] = value;
    }
    delay(10);
  }
  for (int j = 0; j < 2; ++j) {
    TouchPad& pad = *pads[j];
    pad.baseline = static_cast<float>(sums[j]) / count;
    pad.filtered = pad.baseline;
    pad.raw = static_cast<uint32_t>(pad.baseline);
    float noiseMargin = static_cast<float>(highs[j] - lows[j]) * 4.0f;
    pad.onDelta = max(1.0f, max(pad.baseline * PRESS_RISE, noiseMargin));
    pad.offDelta = max(pad.baseline * RELEASE_RISE, pad.onDelta * 0.5f);
    pad.touched = false;
    pad.changes = 0;
  }
  if (!jsonMode) {
    Serial.printf("Baselines: left=%.0f right=%.0f\n", leftPad.baseline, rightPad.baseline);
    Serial.printf("Touch ON thresholds: left=%.0f right=%.0f\n",
                  leftPad.baseline + leftPad.onDelta, rightPad.baseline + rightPad.onDelta);
    Serial.println("Touch each pad, then release. Send c to calibrate again.");
  }
}

void samplePad(TouchPad& pad) {
  pad.raw = readPad(pad.pin);
  pad.filtered += (static_cast<float>(pad.raw) - pad.filtered) * 0.35f;
  // ESP32-S3 counts INCREASE on touch (the original ESP32 behaves differently).
  float threshold = pad.baseline + (pad.touched ? pad.offDelta : pad.onDelta);
  bool candidate = pad.baseline > 0 && pad.filtered > threshold;
  if (candidate == pad.touched) pad.changes = 0;
  else if (++pad.changes >= DEBOUNCE_SAMPLES) {
    pad.touched = candidate;
    pad.changes = 0;
  }
}

void setup() {
  Serial.begin(115200);
  uint32_t started = millis();
  while (!Serial && millis() - started < 2500) delay(10);
  // touchRead initializes the touch peripheral. Do not enable digital pull-ups.
  Serial.printf("ESP32-S3 copper touch: LEFT=GPIO%u RIGHT=GPIO%u\n", LEFT_PIN, RIGHT_PIN);
  calibrate();
}

void loop() {
  while (Serial.available()) {
    char command = Serial.read();
    if (command == 'c' || command == 'C') calibrate();
    else if (command == 'j' || command == 'J') jsonMode = true;
    else if (command == 'r' || command == 'R') jsonMode = false;
  }
  uint32_t now = millis();
  if (now - lastSample >= SAMPLE_MS) {
    lastSample = now;
    samplePad(leftPad); samplePad(rightPad);
  }
  if (now - lastPrint >= PRINT_MS) {
    lastPrint = now;
    if (jsonMode) {
      // Touch/release is binary; this is NOT a measurement of squeeze force.
      Serial.printf("{\"type\":\"puffsqueeze:grip\",\"left\":%d,\"right\":%d}\n",
                    leftPad.touched ? 1 : 0, rightPad.touched ? 1 : 0);
    } else {
      Serial.printf("L_raw:%lu\tR_raw:%lu\tL_base:%.0f\tR_base:%.0f\tL_touch:%d\tR_touch:%d\n",
                    static_cast<unsigned long>(leftPad.raw), static_cast<unsigned long>(rightPad.raw),
                    leftPad.baseline, rightPad.baseline,
                    leftPad.touched ? 1 : 0, rightPad.touched ? 1 : 0);
    }
  }
  delay(1);
}
