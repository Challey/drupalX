<?php

declare(strict_types=1);

namespace Drupal\dx_portal\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for seeding portal demo content across industries.
 */
class PortalCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Seed industry-specific demo content (manufacturing, retail, services).
   *
   * @command dx:portal-seed
   * @param string $industry
   *   Industry type: manufacturing, retail, or services.
   * @usage drush dx:portal-seed manufacturing
   */
  public function portalSeed(string $industry = 'manufacturing'): void {
    $industry = strtolower(trim($industry));
    $valid = ['manufacturing', 'retail', 'services'];
    if (!in_array($industry, $valid, TRUE)) {
      $this->logger()->error("Invalid industry. Choose from: " . implode(', ', $valid));
      return;
    }

    $tenantConfig = $this->configFactory->getEditable('dx_tenant.settings');

    $datasets = [
      'manufacturing' => [
        'company' => '精工重工自动化科技有限公司',
        'industry' => '智能装备制造',
        'knowledge' => "【企业概况】精工重工成立于2012年，专注于工业数控机床、高精度协作机械臂和智慧工厂交钥匙工程。\n【售后服务】提供全国24小时内到场响应，核心部件质保3年。\n【发货流程】标准机型7天内发货，定制生产线交付周期为30-45天。",
        'products' => [
          [
            'title' => 'CNC-5000 高速精密五轴加工中心',
            'sku' => 'CNC-5000-PRO',
            'price' => '480000.00',
            'body' => '采用高刚性矿物铸件床身，主轴转速24000rpm，适用于航空航天、汽车精密模具复杂曲面加工。',
          ],
          [
            'title' => 'ROBO-X6 工业六轴协作机器人',
            'sku' => 'RB-X6-10KG',
            'price' => '85000.00',
            'body' => '额定负载10kg，工作半径1300mm，重复定位精度±0.02mm，配备一体化力控碰撞检测传感器。',
          ],
          [
            'title' => 'AGV-300 激光SLAM全向搬运叉车',
            'sku' => 'AGV-SLAM-300',
            'price' => '62000.00',
            'body' => '无反光板自主导航，支持多机协同与WMS仓储系统智能对接，最大负载3000kg。',
          ],
        ],
        'media' => [
          [
            'title' => '精工重工荣获2026年度智能装备创新金奖',
            'body' => '在第十五届中国工业装备博览会上，精工重工凭借新一代自适应CNC数控系统斩获技术创新金奖。',
          ],
          [
            'title' => '工业4.0智慧车间改造白皮书发布',
            'body' => '本白皮书系统梳理了中小型离散制造企业在产线数字化升级过程中的关键路径与投资回报模型。',
          ],
        ],
      ],
      'retail' => [
        'company' => '绿源优选新零售品牌',
        'industry' => '快消品与新零售',
        'knowledge' => "【品牌介绍】绿源优选倡导有机健康生活，严选全球核心产区天然食材与个护好物。\n【会员权益】注册即享首单88折，积分可全额抵扣现金，全场满99元顺丰包邮。\n【退换无忧】支持7天无理由退换货，生鲜破损极速秒赔。",
        'products' => [
          [
            'title' => '高山特级有机初榨冷萃茶油 (500ml*2礼盒装)',
            'sku' => 'LY-OIL-500',
            'price' => '198.00',
            'body' => '精选海拔800米以上高山油茶籽，物理低温冷榨，单不饱和脂肪酸高达80%以上。',
          ],
          [
            'title' => '天然植物草本净澈洗护套装 (洗发水+护发素)',
            'sku' => 'LY-CARE-01',
            'price' => '128.00',
            'body' => '无硅油配方，富含迷迭香与何首乌植萃精华，深层滋养头皮，温和修护发丝。',
          ],
          [
            'title' => '全麦高纤低卡黑麦代餐饼干 (30包独立装)',
            'sku' => 'LY-FOOD-30',
            'price' => '49.90',
            'body' => '0添加蔗糖，饱腹感持久，适合健身减脂及日常健康控糖人群。',
          ],
        ],
        'media' => [
          [
            'title' => '绿源优选全国第100家智慧生活馆盛大开业',
            'body' => '融合线上小程序即时配送与线下体验店的全新零售业态，开业首日营业额突破百万元。',
          ],
          [
            'title' => '2026绿色可持续消费发展报告',
            'body' => '年轻一代消费者对于环保可降解包装与零碳生产食品的偏好度同比提升45%。',
          ],
        ],
      ],
      'services' => [
        'company' => '恒信智慧企业管理咨询集团',
        'industry' => '专业服务与企业咨询',
        'knowledge' => "【关于恒信】恒信咨询是中国领先的企业战略与数字化转型顾问机构，服务超过500家行业龙头。\n【专家团队】核心合伙人均拥有麦肯锡、埃森哲等全球一线咨询公司10年以上实战背景。\n【预约咨询】首期30分钟企业诊断会议免费提供，可在官网直接提交预约。",
        'products' => [
          [
            'title' => '企业AI落地与数字化转型战略规划方案',
            'sku' => 'HX-CONS-AI',
            'price' => '150000.00',
            'body' => '覆盖企业全价值链AI场景扫描、技术选型路线图、数据治理与组织变革方案设计。',
          ],
          [
            'title' => '中小企业财税合规与股权架构顶层设计',
            'sku' => 'HX-FIN-01',
            'price' => '68000.00',
            'body' => '帮助拟上市与高成长企业梳理合规风险、优化股东持股平台及设计员工期权池。',
          ],
          [
            'title' => '跨境出海与合规出境数据合规包',
            'sku' => 'HX-GLOBAL-02',
            'price' => '98000.00',
            'body' => '提供GDPR/CCPA全球隐私合规审计、跨境供应链搭建及海外知识产权布局护航。',
          ],
        ],
        'media' => [
          [
            'title' => '恒信发布《2026中国企业大模型落地应用实战调研》',
            'body' => '深度剖析制造业、金融、零售等五大核心行业在引入生成式AI过程中的投资回报率与痛点。',
          ],
          [
            'title' => '恒信咨询助力3家客户顺利完成港股与科创板挂牌上市',
            'body' => '恒信项目组在内控合规、商业故事梳理和上市前尽调审计中提供全流程深度辅导。',
          ],
        ],
      ],
    ];

    $data = $datasets[$industry];

    $tenantConfig
      ->set('company_name', $data['company'])
      ->set('industry', $data['industry'])
      ->set('ai_knowledge_intro', $data['knowledge'])
      ->save();

    $this->logger()->success("Updated tenant settings for {$industry}: {$data['company']}");

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Create Products
    foreach ($data['products'] as $p) {
      $existing = $nodeStorage->loadByProperties(['type' => 'dx_product', 'title' => $p['title']]);
      if (!$existing) {
        $node = Node::create([
          'type' => 'dx_product',
          'title' => $p['title'],
          'field_dx_sku' => $p['sku'],
          'field_dx_price' => $p['price'],
          'body' => [
            'value' => $p['body'],
            'format' => 'plain_text',
          ],
          'status' => 1,
        ]);
        $node->save();
        $this->logger()->info("Created product: {$p['title']}");
      }
    }

    // Create Media items
    foreach ($data['media'] as $m) {
      $existing = $nodeStorage->loadByProperties(['type' => 'dx_media', 'title' => $m['title']]);
      if (!$existing) {
        $node = Node::create([
          'type' => 'dx_media',
          'title' => $m['title'],
          'body' => [
            'value' => $m['body'],
            'summary' => mb_substr($m['body'], 0, 80) . '...',
            'format' => 'plain_text',
          ],
          'status' => 1,
        ]);
        $node->save();
        $this->logger()->info("Created media item: {$m['title']}");
      }
    }

    $this->logger()->success("Successfully seeded {$industry} demo content!");
  }

}
