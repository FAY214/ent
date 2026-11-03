# ESP32-S3 双铜箔触摸 IO 测试（Arduino）

使用 ESP32-S3 自带的电容触摸功能。默认左侧 GPIO12、右侧 GPIO13，这是测试接线方案，尚未从照片确认原接线。

## 接线

```text
左铜箔 ── 510Ω（建议）── GPIO12
右铜箔 ── 510Ω（建议）── GPIO13
```

两块铜箔分开，每块只连接自己的触摸引脚，不接 3V3、5V 或 GND。引线尽量短，铜箔背面可以绝缘固定。直接用板上标注的 GPIO 编号，不是从排针第一脚开始计数。

ESP32-S3 的 GPIO1–14 支持触摸。修改引脚前，请确认没有与现有 OLED、麦克风、功放等接线冲突；GPIO3 还是启动配置脚，初次测试优先用空闲的 GPIO12、13。照片不能确定你的铜箔接线。

## Arduino IDE

1. 开发板管理器安装 **esp32 by Espressif Systems**。
2. 打开本目录同名 `.ino`，选择实际板型；通用 S3 板可先选 **ESP32S3 Dev Module**，Flash/PSRAM 设置以实际模块规格为准。
3. 按接线修改文件顶部 `LEFT_PIN` 和 `RIGHT_PIN`。
4. 若插的是芯片原生 USB 口，启用 **USB CDC On Boot**；使用板载 USB 转串口口时，按该板的 UART 设置选择，不能仅凭双 USB 接口推断当前是哪一个。
5. 选择对应 COM 端口，上传。无法进入下载模式时：按住 BOOT，按一下 RST，再松开 BOOT，重新选择端口上传。
6. 打开串口监视器，波特率 **115200**。复位后约 2 秒内不要触碰铜箔，让程序采集未触摸基线。

## 输出与操作

```text
L_raw:12500  R_raw:11800  L_base:12400  R_base:11700  L_touch:0  R_touch:0
```

数值仅为格式示例，不是实测。ESP32-S3 触摸后通常是读数增加；`L_touch` / `R_touch` 为 1 表示检测到触摸，0 表示松开。

- 先分别触摸左右铜箔，再同时触摸，最后全部松开，观察各路变化。
- 发送 `c`：松开两块铜箔，重新校准。
- 发送 `j`：输出网页手柄协议 JSON（触摸=1，松开=0）。此 IO 测试默认使用可读日志；若后续接网页，需改成默认 JSON 模式并关闭 Arduino 串口监视器。
- 发送 `r`：返回可读日志。
- 触摸变化明显但状态始终为 0：对照串口打印的触发阈值，适当调低 `PRESS_RISE`（例如 0.10）及 `RELEASE_RISE`（例如 0.05）；更换铜箔大小或接线后重新校准。
- 总是为 1：复位时先松手，固定铜箔与导线后重校准；不要在手握铜箔时进行校准。
- 原始值为 0 或没有明显变化：检查芯片是否确实为 S3、GPIO 是否在 1–14、线是否真正接触铜箔金属、导电胶是否导通以及是否与其他模块共用了引脚。

铜箔电容触摸能识别接近、接触与松开，不能直接测量握力或压力。若项目需要实际压力大小，仍需独立压力传感器。

## 官方参考

- [Arduino-ESP32 Touch API](https://docs.espressif.com/projects/arduino-esp32/en/latest/api/touch.html)
- [ESP32-S3 触摸引脚与硬件设计](https://docs.espressif.com/projects/esp-hardware-design-guidelines/zh_CN/latest/esp32s3/schematic-checklist.html)
- [USB CDC 烧录与串口输出](https://docs.espressif.com/projects/arduino-esp32/en/latest/tutorials/cdc_dfu_flash.html)

本文件尚未编译、烧录或进行硬件实测。
