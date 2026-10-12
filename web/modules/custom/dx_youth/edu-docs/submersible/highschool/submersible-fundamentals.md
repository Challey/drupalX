# 水下潜航器 · 高中原理与实践

> 适合高中生，系统学习水下机器人的流体力学基础、推进系统设计、传感器融合、控制算法与编程实践。

---

## 一、水下环境物理基础

### 1.1 水压与深度关系

```
P = P₀ + ρgh
```

| 深度 | 压力（atm） | 工程挑战 |
|------|-----------|---------|
| 0m | 1 | 水面 |
| 100m | 11 | 普通密封 |
| 1000m | 101 | 耐压舱体、油补偿 |
| 6000m | 601 | 钛合金球舱、浮力材料 |
| 11000m | 1101 | 马里亚纳海沟，特殊设计 |

### 1.2 水的密度与盐度影响

- 淡水密度：1000 kg/m³
- 海水密度：1025 kg/m³（盐度 3.5%）
- ROV 从淡水进入海水，浮力增加约 2.5%，需要重新配平

### 1.3 水下通信

| 通信方式 | 频率 | 水中衰减 | 适用场景 |
|---------|------|---------|---------|
| 无线电（WiFi） | 2.4 GHz | 几厘米 | 不可用 |
| 蓝牙 | 2.4 GHz | 几厘米 | 不可用 |
| 声波通信 | 10-50 kHz | 低 | 远距离（km 级），带宽低 |
| 有线（以太网） | - | 无 | ROV 常用，高带宽 |
| 光通信 | 蓝光 450nm | 中 | 短距离高速（实验阶段）|

---

## 二、推进系统设计

### 2.1 推进器选型

关键参数：
- **推力**（N）：推进器产生的推力大小
- **电压**（V）：工作电压（通常 12V-48V）
- **响应时间**（ms）：从指令到推力的延迟
- **防水等级**：IP68（水下连续工作）

### 2.2 矢量推进布局

**四推进器 X 型布局**：
```
    [TL]         [TR]
      \         /
       \       /
        [舱体]
       /       \
      /         \
    [BL]         [BR]
```

运动混合算法：
```
F_TL = F_forward + F_yaw + F_pitch
F_TR = F_forward - F_yaw + F_pitch
F_BL = F_forward + F_yaw - F_pitch
F_BR = F_forward - F_yaw - F_pitch
```

### 2.3 PID 深度控制

```python
class DepthPID:
    def __init__(self, kp, ki, kd):
        self.kp = kp
        self.ki = ki
        self.kd = kd
        self.integral = 0
        self.prev_error = 0
    
    def compute(self, target_depth, current_depth, dt):
        error = target_depth - current_depth
        self.integral += error * dt
        derivative = (error - self.prev_error) / dt
        self.prev_error = error
        
        output = (self.kp * error + 
                  self.ki * self.integral + 
                  self.kd * derivative)
        return output  # 正值=下潜，负值=上浮
```

---

## 三、传感器系统

### 3.1 深度传感器

**MS5837 水压传感器**：
- 测量范围：0-120 bar（对应 ~1200m 深度）
- 分辨率：0.2 mbar（约 2mm 水深）
- 接口：I2C
- 原理：压阻式，硅膜片变形 → 电阻变化 → 电压变化

### 3.2 IMU 水下导航

水下无法使用 GPS，靠 IMU 进行航位推算（Dead Reckoning）：

```
位置(t+1) = 位置(t) + 速度(t) × Δt + 0.5 × 加速度(t) × Δt²
```

**问题**：IMU 有累积误差，长时间使用会漂移。

**解决方案**：
- 多普勒速度仪（DVL）：测量对地速度，修正漂移
- USBL 水下定位：声学定位系统，精度 0.1% 水深
- 视觉 SLAM：水下特征点匹配（实验性）

### 3.3 声呐系统

| 声呐类型 | 用途 | 原理 |
|---------|------|------|
| 单波束测深 | 正下方水深 | 单点测量 |
| 多波束声呐 | 海底地形 | 扇形扫描，生成 3D 地图 |
| 前视声呐 | 避障 | 类似超声波，但频率更高 |
| 侧扫声呐 | 海底成像 | 左右扫描，生成灰度图 |

---

## 四、水下编程实践

### 4.1 Arduino ROV 控制板编程

```cpp
#include <Servo.h>

Servo leftThruster;
Servo rightThruster;
Servo verticalThruster;

void setup() {
  Serial.begin(115200);
  leftThruster.attach(9);
  rightThruster.attach(10);
  verticalThruster.attach(11);
  
  // ESC 初始化
  leftThruster.writeMicroseconds(1500);
  rightThruster.writeMicroseconds(1500);
  verticalThruster.writeMicroseconds(1500);
  delay(2000);
}

void loop() {
  if (Serial.available() > 0) {
    char cmd = Serial.read();
    int value = Serial.parseInt();
    
    switch(cmd) {
      case 'L':  // 左推进器
        leftThruster.writeMicroseconds(1500 + value);
        break;
      case 'R':  // 右推进器
        rightThruster.writeMicroseconds(1500 + value);
        break;
      case 'V':  // 垂直推进器
        verticalThruster.writeMicroseconds(1500 + value);
        break;
      case 'X':  // 停止
        leftThruster.writeMicroseconds(1500);
        rightThruster.writeMicroseconds(1500);
        verticalThruster.writeMicroseconds(1500);
        break;
    }
  }
}
```

### 4.2 Python 上位机控制

```python
import serial
import time

class ROVController:
    def __init__(self, port='/dev/ttyUSB0', baud=115200):
        self.ser = serial.Serial(port, baud, timeout=1)
        time.sleep(2)
    
    def send(self, cmd, value=0):
        self.ser.write(f"{cmd}{value}\n".encode())
    
    def forward(self, speed=200):
        self.send('L', speed)
        self.send('R', speed)
    
    def turn(self, speed=150):
        self.send('L', speed)
        self.send('R', -speed)
    
    def dive(self, speed=100):
        self.send('V', speed)
    
    def surface(self, speed=100):
        self.send('V', -speed)
    
    def stop(self):
        self.send('X')
    
    def close(self):
        self.stop()
        self.ser.close()

# 使用示例
rov = ROVController()
rov.forward(200)
time.sleep(3)
rov.dive(100)
time.sleep(2)
rov.stop()
rov.close()
```

---

## 五、综合项目：水下搜索与救援模拟

### 5.1 项目目标

设计一台 ROV，在模拟水池中完成：
1. 从起点下潜到指定深度
2. 沿预定路线前进
3. 识别水底目标物（颜色/形状）
4. 用机械臂抓取目标
5. 带回起点并上浮

### 5.2 系统架构

```
摄像头 → 目标识别 → 位置决策
    ↓
深度传感器 → PID 深度控制 → 垂直推进器
    ↓
IMU → 航向保持 → 水平推进器
    ↓
控制板 ← 串口指令 ← 上位机/脑电接口
```

### 5.3 评分标准

| 项目 | 分值 |
|------|------|
| 下潜到指定深度（±10cm） | 20 分 |
| 沿路线前进不偏离 | 20 分 |
| 识别目标物 | 20 分 |
| 抓取目标 | 25 分 |
| 安全返回 | 15 分 |

---

## 推荐资源

- **BlueROV2 技术文档**：[docs.bluerobotics.com](https://docs.bluerobotics.com/)
- **MATE ROV 竞赛**：[www.marinerobotics.org](https://www.marinerobotics.org/)
- **MIT 海洋机器人课程**：[ocw.mit.edu](https://ocw.mit.edu/)
- **全国水下机器人大赛**：[www.urccn.net](http://www.urccn.net/)
