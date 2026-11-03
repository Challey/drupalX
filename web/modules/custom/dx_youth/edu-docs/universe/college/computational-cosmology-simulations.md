# 计算宇宙学与数值模拟

> 宇宙学核心课程 · 大学前沿篇

---

## 第一章：N 体模拟

### 1.1 引力 N 体问题

```python
import numpy as np

class NBodySimulation:
    """
    N 体引力模拟
    
    用于模拟宇宙大尺度结构形成
    """
    
    def __init__(self, n_particles=1000, box_size=100.0):
        self.n_particles = n_particles
        self.box_size = box_size
        
        # 初始化粒子
        self.positions = np.random.uniform(0, box_size, (n_particles, 3))
        self.velocities = np.zeros((n_particles, 3))
        self.masses = np.ones(n_particles) / n_particles
        
        # 物理常数
        self.G = 1.0  # 引力常数（归一化）
        self.softening = 0.1  # 软化长度（避免奇点）
    
    def compute_forces(self):
        """
        计算所有粒子间的引力
        
        复杂度：O(N²)
        优化：Barnes-Hut 算法 O(N log N)
        """
        forces = np.zeros((self.n_particles, 3))
        
        for i in range(self.n_particles):
            for j in range(i + 1, self.n_particles):
                # 位置差
                r_vec = self.positions[j] - self.positions[i]
                r = np.linalg.norm(r_vec) + self.softening
                
                # 引力大小
                F = self.G * self.masses[i] * self.masses[j] / r**3
                
                # 力的方向
                force = F * r_vec
                
                # 牛顿第三定律
                forces[i] += force
                forces[j] -= force
        
        return forces
    
    def leapfrog_step(self, dt):
        """
        Leapfrog 积分（辛积分器）
        
        优点：能量守恒好，适合长时间模拟
        """
        # 半步速度更新
        forces = self.compute_forces()
        accelerations = forces / self.masses[:, np.newaxis]
        self.velocities += 0.5 * dt * accelerations
        
        # 全步位置更新
        self.positions += dt * self.velocities
        
        # 半步速度更新
        forces = self.compute_forces()
        accelerations = forces / self.masses[:, np.newaxis]
        self.velocities += 0.5 * dt * accelerations
    
    def run(self, n_steps, dt=0.01):
        """运行模拟"""
        for step in range(n_steps):
            self.leapfrog_step(dt)
            
            if step % 100 == 0:
                print(f"Step {step}/{n_steps}")
        
        return self.positions, self.velocities
```

### 1.2 Barnes-Hut 算法

```
Barnes-Hut 算法优化：

思想：
├── 将空间划分为八叉树
├── 远处粒子群用质心近似
├── 近处粒子精确计算
└── 复杂度：O(N log N)

树结构：
├── 根节点：包含所有粒子
├── 每个节点最多 8 个子节点
├── 叶节点：包含单个粒子
└── 内部节点：存储质心和总质量

力计算：
├── 对于每个粒子
├── 遍历树
├── 如果节点足够远（s/d < θ）→ 用质心
├── 否则 → 递归进入子节点
└── θ 典型值：0.5~1.0
```

---

## 第二章：宇宙微波背景模拟

### 2.1 CMB 功率谱计算

```python
import numpy as np
from scipy.integrate import quad

class CMBSpectrum:
    """
    CMB 温度功率谱计算
    
    基于 Boltzmann 方程
    """
    
    def __init__(self, cosmology):
        self.cosmo = cosmology
    
    def transfer_function(self, k, ell):
        """
        转移函数
        
        描述初始扰动如何演化为 CMB 各向异性
        """
        # 简化模型
        k_eq = 0.073  # 物质-辐射平衡尺度
        
        # 声波振荡
        sound_horizon = 147.0  # Mpc
        acoustic_scale = ell * sound_horizon / 1000
        
        # 转移函数近似
        T = np.cos(acoustic_scale) * np.exp(-k / k_eq)
        
        return T
    
    def compute_power_spectrum(self, ell):
        """
        计算 C_ell
        
        C_ell = (2/π) ∫ k² P(k) |T_ell(k)|² dk
        """
        # 原初功率谱
        def integrand(k):
            P_k = k ** 0.965  # 标量谱指数
            T = self.transfer_function(k, ell)
            return k**2 * P_k * T**2
        
        result, _ = quad(integrand, 1e-4, 1, limit=100)
        
        return (2 / np.pi) * result
    
    def plot_spectrum(self, ell_range=range(2, 2500)):
        """绘制功率谱"""
        ells = list(ell_range)
        C_ells = [self.compute_power_spectrum(ell) for ell in ells]
        
        return ells, C_ells
```

---

## 第三章：引力波数据分析

### 3.1 匹配滤波

```python
import numpy as np
from scipy import signal

class GravitationalWaveAnalysis:
    """
    引力波数据分析
    
    应用：LIGO/Virgo 数据
    """
    
    def __init__(self, sample_rate=4096):
        self.fs = sample_rate
    
    def generate_waveform(self, m1, m2, distance, duration=2.0):
        """
        生成双黑洞并合波形模板
        
        参数：
            m1, m2: 两个黑洞质量（太阳质量）
            distance: 距离（Mpc）
        """
        t = np.linspace(0, duration, int(self.fs * duration))
        
        # 啁啾质量
        M_chirp = (m1 * m2)**(3/5) / (m1 + m2)**(1/5)
        
        # 频率演化（后牛顿近似）
        f_gw = (1 / (8 * np.pi * M_chirp)) * (5 * M_chirp / (t + 1))**(3/8)
        
        # 相位
        phase = 2 * np.pi * np.cumsum(f_gw) / self.fs
        
        # 振幅
        amplitude = (M_chirp / distance) * f_gw**(2/3)
        
        # 波形
        h = amplitude * np.cos(phase)
        
        return t, h
    
    def matched_filter(self, data, template):
        """
        匹配滤波
        
        从噪声中提取已知波形的信号
        """
        # 傅里叶变换
        data_fft = np.fft.fft(data)
        template_fft = np.fft.fft(template)
        
        # 互相关
        cross_corr = np.fft.ifft(data_fft * np.conj(template_fft))
        
        # 信噪比
        snr = np.abs(cross_corr) / np.std(cross_corr)
        
        return snr
    
    def detect_event(self, data, threshold=8.0):
        """
        检测引力波事件
        
        参数：
            threshold: 检测阈值（信噪比）
        """
        # 生成模板库
        templates = []
        for m1 in np.linspace(10, 50, 10):
            for m2 in np.linspace(10, 50, 10):
                _, h = self.generate_waveform(m1, m2, distance=100)
                templates.append(h)
        
        # 对每个模板计算信噪比
        max_snr = 0
        for template in templates:
            snr = self.matched_filter(data, template)
            max_snr = max(max_snr, np.max(snr))
        
        # 判断是否检测到
        detected = max_snr > threshold
        
        return detected, max_snr
```

---

## 第四章：机器学习用于宇宙学

### 4.1 用 CNN 估计宇宙学参数

```python
import torch
import torch.nn as nn

class CosmoParameterEstimator(nn.Module):
    """
    用 CNN 从 CMB 图估计宇宙学参数
    
    输入：CMB 温度图
    输出：Ω_m, Ω_Λ, H₀, n_s, σ₈
    """
    
    def __init__(self, n_params=5):
        super().__init__()
        
        # CNN 特征提取
        self.features = nn.Sequential(
            nn.Conv2d(1, 32, 3, padding=1),
            nn.ReLU(),
            nn.MaxPool2d(2),
            
            nn.Conv2d(32, 64, 3, padding=1),
            nn.ReLU(),
            nn.MaxPool2d(2),
            
            nn.Conv2d(64, 128, 3, padding=1),
            nn.ReLU(),
            nn.AdaptiveAvgPool2d(4),
        )
        
        # 回归头
        self.regressor = nn.Sequential(
            nn.Linear(128 * 4 * 4, 256),
            nn.ReLU(),
            nn.Dropout(0.3),
            nn.Linear(256, n_params)
        )
    
    def forward(self, x):
        """
        x: (batch, 1, height, width) CMB 图
        """
        features = self.features(x)
        features = features.view(features.size(0), -1)
        params = self.regressor(features)
        
        return params
    
    def training_step(self, batch, optimizer):
        """训练步骤"""
        cmb_maps, true_params = batch
        
        # 预测
        pred_params = self(cmb_maps)
        
        # 损失（MSE）
        loss = torch.nn.functional.mse_loss(pred_params, true_params)
        
        optimizer.zero_grad()
        loss.backward()
        optimizer.step()
        
        return loss.item()
```

---

## 课后项目

### 项目 1：N 体模拟

```python
# 运行简单的 N 体模拟
sim = NBodySimulation(n_particles=100)
positions, velocities = sim.run(n_steps=1000, dt=0.01)

# 可视化
import matplotlib.pyplot as plt
plt.scatter(positions[:, 0], positions[:, 1], s=1)
plt.show()
```

### 项目 2：引力波信号检测

```python
# 生成模拟数据（噪声 + 信号）
t, signal = gw.generate_waveform(m1=30, m2=30, distance=100)
noise = np.random.normal(0, 1e-21, len(signal))
data = signal + noise

# 检测
detected, snr = gw.detect_event(data)
print(f"Detected: {detected}, SNR: {snr:.2f}")
```

---

## 前沿论文推荐

1. **Springel (2005)** - "The Cosmological Simulation Code GADGET-2"
2. **Planck Collaboration (2020)** - "Planck 2018 results"
3. **Abbott et al. (2016)** - "Observation of Gravitational Waves from a Binary Black Hole Merger"
4. **He et al. (2023)** - "Deep Learning for Cosmological Parameter Estimation"

---

> 参考资源：
> - [GADGET N-body code](https://wwwmpa.mpa-garching.mpg.de/gadget/)
> - [CAMB Boltzmann code](https://camb.info/)
> - [LIGO Open Science Center](https://losc.ligo.org/)
> - [CosmoML](https://cosmology.ml/)
