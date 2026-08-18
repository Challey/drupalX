<?php

declare(strict_types=1);

namespace Drupal\dx_payment\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Same client scenes as topstar_app_pay (App / WeChat / H5 / desktop).
 */
final class ClientDetector {

  public const SCENE_WECHAT_MP = 'wechat_mp';
  public const SCENE_ANDROID_APP = 'android_app';
  public const SCENE_IOS_APP = 'ios_app';
  public const SCENE_MOBILE = 'mobile';
  public const SCENE_DESKTOP = 'desktop';

  public function detect(?Request $request = NULL): string {
    return $this->scene($request);
  }

  public function scene(?Request $request = NULL): string {
    $request = $request ?: \Drupal::request();
    $ua = (string) $request->headers->get('User-Agent', '');
    if ($ua === '') {
      return self::SCENE_DESKTOP;
    }
    if (preg_match('/MicroMessenger/i', $ua) && preg_match('/Mobile/i', $ua)) {
      return self::SCENE_WECHAT_MP;
    }
    if (preg_match('/PaocheAssistant|AndroidApp|TpstPi|TPST-PI|TpstTiming|TPSTTiming/i', $ua)) {
      if (preg_match('/iPhone|iPad|iPod|iOS/i', $ua)) {
        return self::SCENE_IOS_APP;
      }
      return self::SCENE_ANDROID_APP;
    }
    if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
      return self::SCENE_MOBILE;
    }
    return self::SCENE_DESKTOP;
  }

  public function isNativeApp(?Request $request = NULL): bool {
    $scene = $this->scene($request);
    return in_array($scene, [self::SCENE_ANDROID_APP, self::SCENE_IOS_APP], TRUE);
  }

  public function wechatChannel(?Request $request = NULL): string {
    return match ($this->scene($request)) {
      self::SCENE_WECHAT_MP => 'jsapi',
      self::SCENE_ANDROID_APP, self::SCENE_IOS_APP, self::SCENE_MOBILE => 'h5',
      default => 'native',
    };
  }

  public function h5SceneType(?Request $request = NULL): string {
    return match ($this->scene($request)) {
      self::SCENE_ANDROID_APP => 'Android',
      self::SCENE_IOS_APP => 'iOS',
      default => 'Wap',
    };
  }

}
