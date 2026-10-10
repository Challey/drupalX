<?php

declare(strict_types=1);

namespace Drupal\dx_youth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_assets\Service\AssetService;
use Drupal\dx_youth\Service\MemberService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Education zone landing page and tier home pages.
 */
class EduLandingController extends ControllerBase {

  public function __construct(
    protected MemberService $member,
    protected AssetService $assets,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_youth.member'),
      $container->get('dx_assets.service'),
    );
  }

  /**
   * Loads the OSS file manifest and returns OSS URLs for a tier.
   *
   * @param string $tier
   *   Tier key: k12, highschool, bci, autocar, drone.
   *
   * @return array
   *   Category => [label, files => [{name, url}]].
   */
  protected function loadTierManifest(string $tier): array {
    $manifest_file = \Drupal::root() . '/web/modules/custom/dx_youth/config/edu-oss-manifest.json';
    $manifest = [];
    if (is_file($manifest_file)) {
      $manifest = json_decode((string) file_get_contents($manifest_file), TRUE) ?: [];
    }
    $tier_data = $manifest[$tier] ?? [];
    $labels = $this->categoryLabels($tier);
    $result = [];
    foreach ($tier_data as $cat_key => $files) {
      $result[$cat_key] = [
        'label' => $labels[$cat_key] ?? $cat_key,
        'files' => array_map(function (string $path): array {
          $name = basename($path);
          return [
            'name' => $name,
            'url' => $this->assets->previewUrl('edu/' . $path),
          ];
        }, $files),
      ];
    }
    return $result;
  }

  /**
   * Human-readable labels for manifest categories.
   */
  protected function categoryLabels(string $tier): array {
    return match ($tier) {
      'k12' => [
        'scratch' => 'Scratch 图形化编程',
        'coding' => 'Coding 编程项目',
        'python' => 'Python 学习',
        'courses' => '编程课件',
        'computer-basics' => '计算机基础',
        'ai-teaching' => 'AI 教学内容',
        'exams' => '考试题',
        'competitions' => '竞赛资料',
        'exam-prep' => '中考突击培训',
        'student-works' => '学生作品',
      ],
      'bci' => [
        'projects' => '脑电波项目',
        'courses' => '课程课件',
        'exams' => '考试题',
        'ai-brain-teaching' => '数学基础',
        'devices' => '设备与工具',
      ],
      'autocar' => [
        'fundamentals' => '基础理论',
        'perception' => '感知技术',
        'planning' => '决策与规划',
        'hardware' => '硬件平台',
        'projects' => '项目实战',
        'competitions' => '竞赛资料',
        'courses' => '课程课件',
      ],
      'miniauto' => [
        'overview' => '产品概览',
        'quickstart' => '快速上手',
        'ai-vision' => 'AI 视觉教程',
        'ai-llm' => 'AI 大模型',
        'arduino' => 'Arduino 编程',
        'projects' => '项目实战',
      ],
      'drone' => [
        'fundamentals' => '基础理论',
        'flight-control' => '开源飞控',
        'perception' => '感知与避障',
        'programming' => '编程开发',
        'hardware' => '硬件平台',
        'projects' => '项目实战',
        'competitions' => '竞赛资料',
        'courses' => '课程课件',
      ],
      'universe' => [
        'fundamentals' => '宇宙基础理论',
        'observation' => '观测实践',
        'cosmology' => '宇宙学与暗物质暗能量',
        'simulation' => '仿真工具',
        'projects' => '项目实战',
        'competitions' => '竞赛资料',
        'courses' => '课程课件',
        'tianquan' => '天权 · 子午线计划',
      ],
      'highschool' => [
        'ai-express' => 'AI Express 课程',
        'rocket' => '科创项目',
      ],
      default => [],
    };
  }

  /**
   * Main education zone landing with two category groups.
   */
  public function landing(): array {
    return [
      '#theme' => 'dx_youth_edu_landing',
      '#logged_in' => $this->currentUser()->isAuthenticated(),
      '#student_tiers' => [
        'k12' => [
          'label' => '中小学',
          'description' => 'AI 启蒙与基础编程，图形化编程、Python 入门、AI 体验课。',
          'url' => Url::fromRoute('dx_youth.edu_k12')->toString(),
          'icon' => '📚',
        ],
        'highschool' => [
          'label' => '高中',
          'description' => '大学先修课程、创新课题研究与信息学竞赛训练。',
          'url' => Url::fromRoute('dx_youth.edu_highschool')->toString(),
          'icon' => '🎯',
        ],
        'college' => [
          'label' => '大学',
          'description' => '面向就业的实战项目：模型搭建、全栈开发、系统设计。',
          'url' => Url::fromRoute('dx_youth.edu_college')->toString(),
          'icon' => '🎓',
        ],
      ],
      '#frontier_tiers' => [
        'bci' => [
          'label' => '脑电波',
          'description' => 'AI 脑机接口：脑电波原理、BrainLink 项目、BCI 应用开发。',
          'url' => Url::fromRoute('dx_youth.edu_bci')->toString(),
          'icon' => '🧠',
        ],
        'autocar' => [
          'label' => '智能无人车',
          'description' => '自动驾驶：感知、决策、规划、控制，从入门到项目实战。',
          'url' => Url::fromRoute('dx_youth.edu_autocar')->toString(),
          'icon' => '🚘',
        ],
        'drone' => [
          'label' => '无人机',
          'description' => '无人机开发：PX4/ArduPilot 开源飞控、ROS 仿真、视觉避障、编队飞行。',
          'url' => Url::fromRoute('dx_youth.edu_drone')->toString(),
          'icon' => '🛩️',
        ],
        'universe' => [
          'label' => '宇宙学',
          'description' => '宇宙理论与实践：大爆炸、广义相对论、暗物质暗能量、天文观测与仿真。',
          'url' => Url::fromRoute('dx_youth.edu_universe')->toString(),
          'icon' => '🪐',
        ],
      ],
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 600, 'contexts' => ['user']],
    ];
  }

  /**
   * K12 tier home — redirects to existing youth zone.
   */
  public function k12Home(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('dx_youth.home')->toString(), 302);
  }

  /**
   * High school tier home placeholder.
   */
  public function highschoolHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_highschool',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * College tier home placeholder.
   */
  public function collegeHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_college',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * BCI (Brain-Computer Interface) tier home page.
   */
  public function bciHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_bci',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * Autonomous vehicle tier home page.
   */
  public function autocarHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_autocar',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * Hiwonder miniAuto smart car sub-section page.
   */
  public function autocarMiniautoHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_autocar_miniauto',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * Drone (UAV) tier home page.
   */
  public function droneHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_drone',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * Universe theory & practice section.
   */
  public function universeHome(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid > 0 && !$this->member->hasEduProfile($uid)) {
      return new RedirectResponse(Url::fromRoute('dx_youth.edu_profile_setup')->toString());
    }
    return [
      '#theme' => 'dx_youth_edu_universe',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300, 'contexts' => ['user']],
    ];
  }

  /**
   * K12 course resources page.
   */
  public function k12Resources(): array {
    return [
      '#theme' => 'dx_youth_edu_k12_resources',
      '#attached' => ['library' => ['dx_youth/classroom']],
      '#cache' => ['max-age' => 300],
    ];
  }

  /**
   * Redirect legacy /youth-home to /edu (the new main entry).
   */
  public function legacyRedirect(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('dx_youth.edu_landing')->toString(), 301);
  }

  /**
   * Serves a local education document as rendered markdown.
   *
   * @param string $section
   *   Section: autocar, drone, shared.
   * @param string $file
   *   Filename without path separators (e.g. apollo-platform-guide.md).
   */
  public function localDoc(string $section, string $subdir, string $file): array|Response {
    // Whitelist allowed sections to prevent directory traversal.
    $allowed = ['autocar', 'drone', 'shared', 'universe'];
    if (!in_array($section, $allowed, TRUE) || !str_ends_with($file, '.md') || str_contains($file, '/') || str_contains($file, '..') || str_contains($subdir, '/') || str_contains($subdir, '..')) {
      return new Response('Not found', 404);
    }
    $path = \Drupal::root() . '/modules/custom/dx_youth/edu-docs/' . $section . '/' . $subdir . '/' . $file;
    if (!is_file($path)) {
      return new Response('Not found', 404);
    }
    $content = (string) file_get_contents($path);
    // Simple markdown to HTML: headings, bold, links, lists, tables, blockquotes.
    $html = _dx_youth_markdown_to_html($content);
    return [
      '#markup' => $html,
      '#cache' => ['max-age' => 3600, 'tags' => ['edu_docs:' . $section . ':' . $file]],
    ];
  }

}
