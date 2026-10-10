# 幻尔 miniAuto · 快速上手指南

> 本教程帮助你从零开始完成 miniAuto 智能小车的组装、环境搭建与第一个程序运行。适用于 AI 视觉豪华版与 AI 大模型旗舰版。

---

## 一、组装小车

### 1.1 安装步骤

1. **安装电机**：将 4 个减速直流电机分别固定在底盘四角，注意电机轴朝外
2. **安装麦克纳姆轮**：将 4 个麦轮分别安装到电机轴上，注意轮子方向（A/B 轮交替安装）
3. **安装主控板**：将 Arduino UNO 扩展板固定在底盘上方，连接 4 个电机的杜邦线
4. **安装视觉模块**：将 ESP32-S3 摄像头模块安装到车头位置（朝前）
5. **安装传感器**：
   - 超声波传感器安装到车头（朝前）
   - 巡线传感器安装到底盘底部（朝下）
6. **安装电池**：将 18650 锂电池插入电池仓

### 1.2 麦轮方向说明

麦克纳姆轮有 A/B 两种安装方向，必须按以下规则安装才能实现全向移动：

```
左前轮(A) ←→ 右前轮(B)
左后轮(B)  ←→ 右后轮(A)
```

> 方向装反会导致小车无法正常横移或斜行，请务必检查。

### 1.3 接线图

| 电机 | 引脚 | 说明 |
|------|------|------|
| 左前电机 | M1A/M1B | 引脚 5, 6 |
| 右前电机 | M2A/M2B | 引脚 9, 10 |
| 左后电机 | M3A/M3B | 引脚 A0, A1 |
| 右后电机 | M4A/M4B | 引脚 A2, A3 |
| 超声波 | Trig/Echo | 引脚 12, 13 |
| 巡线 | OUT | 引脚 A4 |

---

## 二、搭建 Arduino 开发环境

### 2.1 安装 Arduino IDE

1. 访问 [Arduino 官网](https://www.arduino.cc/en/software) 下载最新版 Arduino IDE
2. 安装完成后打开 Arduino IDE
3. 在 **工具 → 开发板** 中选择 **Arduino UNO**
4. 在 **工具 → 端口** 中选择对应的串口（Windows 为 COMx，macOS/Linux 为 /dev/ttyUSBx 或 /dev/ttyACMx）

### 2.2 安装驱动

- **CH340 驱动**（如果扩展板使用 CH340 串口芯片）：
  - Windows：下载 [CH340 驱动](http://www.wch.cn/downloads/CH341SER_EXE.html) 并安装
  - macOS：`brew install --cask wch-ch34x-usb-serial-driver`
  - Linux：通常内核自带，无需额外安装

### 2.3 安装依赖库

在 Arduino IDE 中通过 **工具 → 管理库** 安装以下库：

- `Servo`（舵机控制，机械臂用）
- `Wire`（I2C 通信）
- 幻尔提供的运动控制库（随小车资料包附带）

---

## 三、下载默认程序

### 3.1 烧录固件

1. 将小车通过 USB 线连接到电脑
2. 打开 Arduino IDE，选择正确的开发板和端口
3. 打开幻尔提供的默认程序文件 `miniAuto_default.ino`
4. 点击 **上传** 按钮（→ 箭头图标）
5. 等待编译和上传完成

### 3.2 验证程序

上传完成后，小车应该有如下表现：
- RGB 灯亮起彩色灯效
- 小车前进 → 后退 → 左转 → 右转 循环演示
- 蜂鸣器播放音乐

---

## 四、APP 连接与控制

### 4.1 安装 Wonderbot APP

- **Android**：在应用商店搜索 "Wonderbot" 或扫描资料包中的二维码下载
- **iOS**：在 App Store 搜索 "Wonderbot"

### 4.2 蓝牙连接

1. 打开小车电源开关
2. 打开手机蓝牙
3. 在 Wonderbot APP 中选择 **miniAuto**
4. 点击蓝牙图标搜索并连接小车
5. 连接成功后图标变为实心

### 4.3 APP 功能

APP 提供以下控制功能：
- **遥控模式**：虚拟摇杆控制小车运动
- **视觉模式**：实时查看摄像头画面
- **颜色追踪**：选择目标颜色，小车自动追踪
- **巡线模式**：小车沿黑线自动行驶
- **避障模式**：小车自动避障行驶

---

## 五、第一个自定义程序

### 5.1 让小车画圆

```cpp
#include <MotorControl.h>

MotorControl motor;

void setup() {
  motor.begin();
}

void loop() {
  // 左轮慢、右轮快 → 小车向左画圆
  motor.setLeftSpeed(100);   // 左轮速度 100
  motor.setRightSpeed(200);  // 右轮速度 200
  delay(3000);               // 运行 3 秒
  
  motor.stop();
  delay(1000);
}
```

### 5.2 麦轮全向移动示例

```cpp
#include <MotorControl.h>

MotorControl motor;

void setup() {
  motor.begin();
  
  // 前进 2 秒
  motor.forward(200);
  delay(2000);
  
  // 后退 2 秒
  motor.backward(200);
  delay(2000);
  
  // 左横移 2 秒（麦轮特色！）
  motor.strafeLeft(200);
  delay(2000);
  
  // 右横移 2 秒
  motor.strafeRight(200);
  delay(2000);
  
  // 原地顺时针旋转 2 秒
  motor.rotateCW(150);
  delay(2000);
  
  // 定圆漂移
  motor.drift(200, 150);
  delay(3000);
  
  motor.stop();
}

void loop() {
  // 程序只执行一次
}
```

---

## 六、充电与维护

### 6.1 充电说明

- 使用 Type-C 充电线连接 5V/2A 充电器
- 充电指示灯：红色 = 充电中，绿色 = 充满
- 充满时间约 2-3 小时
- 续航时间约 60 分钟

### 6.2 注意事项

- 不要在电量低于 20% 时继续使用，以免电池过放
- 长期不使用时，保持 50% 左右电量存放
- 避免在潮湿或多尘环境中使用
- 麦轮轴承处定期清理缠绕的毛发和灰尘
