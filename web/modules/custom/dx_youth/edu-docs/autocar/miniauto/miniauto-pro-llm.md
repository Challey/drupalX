# 幻尔 miniAuto Pro · AI 大模型旗舰版教程

> 本教程面向 AI 大模型旗舰版（miniAuto Pro）用户，讲解如何利用大模型模块实现语音交互、AI 对话、智能决策等进阶功能，并结合 Scratch 图形化编程与 Python 编程进行 AI 应用开发。

---

## 一、旗舰版硬件架构

### 1.1 系统架构

旗舰版在豪华版基础上增加了 AI 大模型模块，形成三层架构：

```
┌─────────────────────────────────┐
│         AI 大模型层              │
│  ┌───────────┐  ┌────────────┐  │
│  │ 语音识别   │  │ 大模型推理  │  │
│  │ (ASR)     │  │ (LLM API)  │  │
│  └───────────┘  └────────────┘  │
│  ┌───────────┐  ┌────────────┐  │
│  │ 语音合成   │  │ 意图理解    │  │
│  │ (TTS)     │  │ (NLU)      │  │
│  └───────────┘  └────────────┘  │
├─────────────────────────────────┤
│         视觉感知层              │
│  ┌───────────┐  ┌────────────┐  │
│  │ ESP32-S3  │  │ 图像处理    │  │
│  │ 摄像头     │  │ 算法       │  │
│  └───────────┘  └────────────┘  │
├─────────────────────────────────┤
│         运动控制层              │
│  ┌───────────┐  ┌────────────┐  │
│  │ Arduino   │  │ 电机驱动    │  │
│  │ UNO       │  │ 麦轮底盘    │  │
│  └───────────┘  └────────────┘  │
└─────────────────────────────────┘
```

### 1.2 AI 大模型模块参数

| 参数 | 值 |
|------|------|
| 处理器 | ESP32-S3 (双核 240MHz) |
| 连接方式 | WiFi / 蓝牙 |
| 语音输入 | 板载麦克风 |
| 语音输出 | 板载扬声器 |
| AI 接口 | HTTP API (大模型服务端) |
| 支持模型 | OpenAI 兼容接口 / 国产大模型 API |
| 响应延迟 | 约 1-3 秒（取决于网络） |

---

## 二、Scratch 图形化编程

### 2.1 Scratch 环境搭建

旗舰版支持 Scratch 图形化编程，适合零基础入门：

1. 下载并安装 **DaDaBlock** 或 **Mind+**（幻尔推荐的 Scratch 修改版）
2. 通过 USB 或 WiFi 连接小车
3. 在编程界面添加小车扩展模块

### 2.2 Scratch 编程示例：语音控制小车

**项目目标**：对小车说"前进"，小车前进；说"左转"，小车左转

**Scratch 积木逻辑**：
```
当程序启动
  重复执行
    如果 <收到语音指令 = "前进"> 那么
      前进 (速度 200)
    否则如果 <收到语音指令 = "后退"> 那么
      后退 (速度 200)
    否则如果 <收到语音指令 = "左转"> 那么
      左转 (速度 150)
    否则如果 <收到语音指令 = "右转"> 那么
      右转 (速度 150)
    否则如果 <收到语音指令 = "停"> 那么
      停止运动
```

### 2.3 Scratch 编程示例：AI 问答小车

**项目目标**：对小车提问，小车通过大模型回答

**Scratch 积木逻辑**：
```
当程序启动
  重复执行
    如果 <检测到语音输入> 那么
      设置 [问题] 为 (语音识别结果)
      发送 [问题] 到 AI 大模型
      等待 AI 回复
      播放 (AI回复) 语音
      在屏幕上显示 (AI回复)
```

---

## 三、Python 编程

### 3.1 环境准备

旗舰版支持 Python 编程，推荐使用 Thonny IDE 或 VS Code：

1. 安装 Python 3.8+
2. 安装依赖库：

```bash
pip install pyserial requests pillow opencv-python
```

### 3.2 串口通信控制小车

```python
import serial
import time

# 连接小车串口
ser = serial.Serial('/dev/ttyUSB0', 115200, timeout=1)
time.sleep(2)

def send_command(cmd):
    """发送控制指令"""
    ser.write(f"{cmd}\n".encode())
    time.sleep(0.1)

# 基础运动控制
send_command("forward:200")    # 前进，速度 200
time.sleep(2)
send_command("backward:200")   # 后退
time.sleep(2)
send_command("strafeLeft:150") # 左横移
time.sleep(2)
send_command("strafeRight:150")# 右横移
time.sleep(2)
send_command("rotateCW:150")  # 顺时针旋转
time.sleep(2)
send_command("stop")           # 停车

ser.close()
```

### 3.3 AI 大模型语音对话

```python
import requests
import json

# 大模型 API 配置（以 OpenAI 兼容接口为例）
API_URL = "https://api.example.com/v1/chat/completions"
API_KEY = "your-api-key-here"

def ask_ai(question):
    """向 AI 大模型提问"""
    headers = {
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json"
    }
    
    data = {
        "model": "gpt-3.5-turbo",
        "messages": [
            {"role": "system", "content": "你是一个友好的AI助手，回答要简洁有趣。"},
            {"role": "user", "content": question}
        ],
        "max_tokens": 200
    }
    
    response = requests.post(API_URL, headers=headers, json=data)
    result = response.json()
    return result["choices"][0]["message"]["content"]

# 测试
while True:
    question = input("问我：")
    if question.lower() == "quit":
        break
    answer = ask_ai(question)
    print(f"AI 回答：{answer}")
    # 可通过串口将回答发送给小车播放语音
```

### 3.4 视觉 + 大模型综合项目

```python
import serial
import cv2
import requests

ser = serial.Serial('/dev/ttyUSB0', 115200, timeout=1)
cap = cv2.VideoCapture(0)  # 打开摄像头

def detect_and_report():
    """检测到物体时，用大模型描述"""
    ret, frame = cap.read()
    if not ret:
        return
    
    # 保存当前帧
    cv2.imwrite("current.jpg", frame)
    
    # 发送图片给大模型（多模态 API）
    # ... (调用视觉大模型 API)
    
    # 根据识别结果控制小车
    # 如果检测到红色物体 → 追踪
    # 如果检测到障碍物 → 避障

while True:
    detect_and_report()
    if cv2.waitKey(1) & 0xFF == ord('q'):
        break

cap.release()
ser.close()
```

---

## 四、ROS 通信（进阶）

### 4.1 ROS 概述

旗舰版支持与 ROS（Robot Operating System）通信，适合大学阶段学习：

- 通过串口或 WiFi 与 ROS 节点通信
- 可接入 ROS 的导航栈（navigation stack）
- 支持与 RViz 可视化、Gazebo 仿真集成

### 4.2 串口 ROS 节点示例

```python
#!/usr/bin/env python3
import rospy
from geometry_msgs.msg import Twist
import serial

ser = serial.Serial('/dev/ttyUSB0', 115200, timeout=1)

def cmd_callback(msg):
    """接收 ROS 速度指令，转发给小车"""
    linear = msg.linear.x
    angular = msg.angular.z
    
    if linear > 0:
        ser.write(b"forward:200\n")
    elif linear < 0:
        ser.write(b"backward:200\n")
    elif angular > 0:
        ser.write(b"rotateCCW:150\n")
    elif angular < 0:
        ser.write(b"rotateCW:150\n")
    else:
        ser.write(b"stop\n")

rospy.init_node('miniauto_bridge')
rospy.Subscriber('/cmd_vel', Twist, cmd_callback)
rospy.spin()
```

---

## 五、综合项目：AI 智能助手小车

### 5.1 项目目标

打造一台能够：
1. 语音对话问答（大模型）
2. 人脸识别并打招呼
3. 语音指令控制运动
4. 自动巡逻并报告所见内容

### 5.2 系统架构

```
麦克风 → 语音识别 → 大模型理解 → 意图分类
                                      ↓
                    ┌─────────────────┼──────────────┐
                    ↓                 ↓              ↓
              问答意图           控制意图        巡逻意图
                    ↓                 ↓              ↓
              大模型生成回答     执行运动指令    启动自动巡逻
                    ↓                 ↓              ↓
              语音播放回答       电机执行        视觉记录+报告
```

### 5.3 拓展方向

- **智能家居控制**：小车作为移动语音助手，控制 IoT 设备
- **安防巡逻**：定时巡逻，检测到异常人脸时报警
- **教育陪伴**：根据学生水平出题、讲解知识点
- **多车协同**：多台小车通过 WiFi 协同完成搜索任务
