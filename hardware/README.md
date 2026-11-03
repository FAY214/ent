# M5StickC Plus Pressure Sensor -> PuffSqueeze Web

This folder contains the firmware for uploading RP-C5-LT press events from M5StickC Plus to:

- `POST /api/squeeze-event`

## File

- `m5stickcplus_rpc5lt_uploader.ino`

## Before Upload

1. Open `m5stickcplus_rpc5lt_uploader.ino`.
2. Set:
   - `WIFI_SSID`
   - `WIFI_PASS`
   - `API_URL` (must be `http://<your-computer-lan-ip>:8080/api/squeeze-event`, not localhost)
   - `USER_ID` (must exist in `puffsqueeze_users`, or backend auto-creates)

## Run Order

1. Start backend on your computer (`:8080`).
2. Start frontend (`:3000`).
3. Flash this `.ino` to M5StickC Plus.
4. Keep M5 and computer on the same Wi-Fi.
5. Press sensor:
   - Backend stores event in `puffsqueeze_device_events`.
   - Home page polls dashboard every 1 second and updates:
     - total squeeze count
     - bird reaction animation

## Notes

- Press M5 `BtnA` to recalibrate baseline/threshold on-device.
- If no updates appear on web, first check:
  - backend URL/IP in `API_URL`
  - backend is running
  - Windows firewall allows port `8080`
