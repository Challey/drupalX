# 幻尔 miniAuto · AI 视觉豪华版教程

> 本教程面向 AI 视觉豪华版用户，系统讲解 ESP32-S3 视觉模块的原理与编程实践，涵盖颜色识别、颜色追踪、视觉巡线、人脸识别等核心功能。

---

## 一、ESP32-S3 视觉模块介绍

### 1.1 模块概述

miniAuto 的 AI 视觉核心是 **ESP32-S3 视觉模块**，它集成了：

- **ESP32-S3 芯片**：双核 Xtensa LX7 处理器，主频 240MHz
- **OV2640 摄像头**：200 万像素，支持 JPEG 压缩
- **AI 加速**：内置向量指令集，加速图像识别运算

### 1.2 视觉模块与主控的通信

视觉模块通过串口与 Arduino UNO 主控通信：

```
ESP32-S3 (视觉) ←→ UART 串口 ←→ Arduino UNO (运动控制)
```

工作流程：
1. 摄像头采集图像
2. ESP32-S3 运行视觉算法识别目标
3. 通过串口将识别结果（坐标、类别等）发送给 UNO
4. UNO 根据接收到的数据控制电机运动

### 1.3 库文件说明

幻尔提供了封装好的 ESP32-S3 视觉库，主要接口：

| 函数 | 说明 |
|------|------|
| `vision.begin()` | 初始化视觉模块 |
| `vision.getColorData()` | 获取颜色识别数据 |
| `vision.getLineData()` | 获取巡线数据 |
| `vision.getFaceData()` | 获取人脸识别数据 |
| `vision.setMode(mode)` | 设置视觉模式 |

---

## 二、颜色识别与追踪

### 2.1 颜色识别原理

颜色识别基于 **HSV 色彩空间**：

| 分量 | 含义 | 范围 |
|------|------|------|
| H (Hue) | 色调 | 0-180 |
| S (Saturation) | 饱和度 | 0-255 |
| V (Value) | 明度 | 0-255 |

相比 RGB，HSV 更适合颜色识别，因为：
- 色调 H 直接对应颜色种类
- 不受光照变化影响太大（通过调整 S 和 V 的阈值）

**常见颜色的 HSV 范围：**

| 颜色 | H 范围 | S 范围 | V 范围 |
|------|--------|--------|--------|
| 红色 | 0-10, 170-180 | 50-255 | 50-255 |
| 绿色 | 35-85 | 50-255 | 50-255 |
| 蓝色 | 100-130 | 50-255 | 50-255 |
| 黄色 | 15-35 | 50-255 | 50-255 |

### 2.2 颜色识别实验

**目标**：识别摄像头画面中的红色物体，输出其中心坐标

```cpp
#include <ESP32Vision.h>

ESP32Vision vision;

void setup() {
  Serial.begin(115200);
  vision.begin();
  vision.setMode(COLOR_RECOGNITION);
}

void loop() {
  ColorData color = vision.getColorData();
  
  if (color.valid) {
    Serial.print("颜色中心: (");
    Serial.print(color.x);
    Serial.print(", ");
    Serial.print(color.y);
    Serial.print(") 面积: ");
    Serial.println(color.area);
  }
  
  delay(100);
}
```

### 2.3 颜色追踪项目

**目标**：让小车自动追踪红色物体，保持物体在画面中央

**原理**：
1. 视觉模块识别红色区域，输出中心坐标 (x, y)
2. 画面中心为 (160, 120)（分辨率 320×240）
3. 如果红色区域偏左 → 小车左转
4. 如果红色区域偏右 → 小车右转
5. 如果红色区域在中央 → 小车前进

**Arduino 控制代码：**

```cpp
#include <MotorControl.h>

MotorControl motor;

// 画面中心坐标
const int CENTER_X = 160;
const int CENTER_Y = 120;
const int DEAD_ZONE = 20;  // 死区范围

void setup() {
  Serial.begin(115200);
  motor.begin();
}

void loop() {
  if (Serial.available() > 0) {
    // 读取视觉模块发来的数据
    int x = Serial.parseInt();
    int y = Serial.parseInt();
    
    int dx = x - CENTER_X;
    int dy = y - CENTER_Y;
    
    if (abs(dx) < DEAD_ZONE && abs(dy) < DEAD_ZONE) {
      // 目标在中央 → 前进
      motor.forward(150);
    } else if (dx < -DEAD_ZONE) {
      // 目标偏左 → 左转
      motor.turnLeft(120);
    } else if (dx > DEAD_ZONE) {
      // 目标偏右 → 右转
      motor.turnRight(120);
    } else {
      motor.stop();
    }
  }
}
```

---

## 三、视觉巡线

### 3.1 巡线原理

视觉巡线比红外巡线更强大：
- 红外巡线只能检测 1-2 个点的黑线
- 视觉巡线可以看到整条线的全貌，提前预判弯道

**算法步骤**：
1. 摄像头拍摄前方地面图像
2. 二值化处理：黑色线条 = 1，背景 = 0
3. 计算黑色区域的中心点
4. 中心点偏离画面中心 → 需要转向修正

### 3.2 视觉巡线代码

```cpp
#include <MotorControl.h>

MotorControl motor;

const int CENTER_X = 160;
const float KP = 1.5;  // 比例系数

void setup() {
  Serial.begin(115200);
  motor.begin();
}

void loop() {
  if (Serial.available() > 0) {
    int lineX = Serial.parseInt();
    
    // 计算偏差
    int error = lineX - CENTER_X;
    
    // 比例控制：偏差越大，转向越多
    int turnSpeed = (int)(error * KP);
    
    int leftSpeed = 180 + turnSpeed;
    int rightSpeed = 180 - turnSpeed;
    
    // 限速
    leftSpeed = constrain(leftSpeed, 0, 255);
    rightSpeed = constrain(rightSpeed, 0, 255);
    
    motor.setLeftSpeed(leftSpeed);
    motor.setRightSpeed(rightSpeed);
  }
}
```

---

## 四、超声波避障

### 4.1 超声波测距原理

超声波传感器（HC-SR04）通过发射超声波并接收回波来测量距离：

```
距离 = 声速 × 时间 / 2
```

### 4.2 避障策略

```
开始
  ↓
前进
  ↓
超声波测距
  ↓
距离 < 20cm?
  ├─ 是 → 停车
  │       ↓
  │     左转头测距
  │     右转头测距
  │       ↓
  │     选择距离更大的方向
  │       ↓
  │     转向该方向
  │       ↓
  │     继续前进
  └─ 否 → 继续前进
```

### 4.3 避障代码

```cpp
#include <MotorControl.h>
#include <Servo.h>

MotorControl motor;
Servo headServo;

const int TRIG_PIN = 12;
const int ECHO_PIN = 13;
const int SAFE_DISTANCE = 20;  // 安全距离 (cm)

void setup() {
  motor.begin();
  headServo.attach(11);
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
}

float getDistance() {
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);
  long duration = pulseIn(ECHO_PIN, HIGH);
  return duration * 0.034 / 2;
}

void loop() {
  float distance = getDistance();
  
  if (distance > SAFE_DISTANCE) {
    motor.forward(180);
  } else {
    motor.stop();
    delay(200);
    
    // 扫描左边
    headServo.write(150);
    delay(500);
    float leftDist = getDistance();
    
    // 扫描右边
    headServo.write(30);
    delay(500);
    float rightDist = getDistance();
    
    // 回正
    headServo.write(90);
    delay(300);
    
    // 选择更空旷的方向
    if (leftDist > rightDist) {
      motor.turnLeft(200);
      delay(500);
    } else {
      motor.turnRight(200);
      delay(500);
    }
  }
}
```

---

## 五、人脸识别

### 5.1 人脸识别原理

ESP32-S3 视觉模块内置人脸识别算法：
1. **人脸检测**：在画面中找到人脸区域
2. **特征提取**：提取人脸关键特征点
3. **人脸注册**：将特征数据保存到数据库
4. **人脸识别**：将新检测到的人脸与数据库比对

### 5.2 人脸识别实验

**步骤**：
1. 通过 APP 进入人脸识别模式
2. 对准自己的脸部，点击"注册"
3. 小车会记住你的脸
4. 当小车再次检测到你时，会做出特定动作（如前进到面前）

**应用场景**：
- 人脸识别追踪：小车跟随特定人员移动
- 门禁系统模拟：识别授权人脸后开门（舵机模拟）
- 多人识别：注册多张人脸，对不同人做不同反应

---

## 六、综合项目：智能巡线避障小车

### 6.1 项目目标

结合视觉巡线与超声波避障，让小车能够：
1. 沿黑线自动行驶
2. 遇到障碍物时停车绕行
3. 绕过障碍后重新找到黑线继续行驶

### 6.2 项目思路

```
主循环:
  1. 超声波检测前方是否有障碍
  2. 如果无障碍 → 视觉巡线模式
  3. 如果有障碍 → 避障模式
     a. 停车
     b. 扫描左右方向
     c. 选择空旷方向绕行
     d. 绕行后重新搜索黑线
     e. 找到黑线 → 回到巡线模式
```

### 6.3 拓展思考

- 如何让小车在十字路口做决策？
- 如何记录行驶路径？
- 如何让多辆小车协同工作？
