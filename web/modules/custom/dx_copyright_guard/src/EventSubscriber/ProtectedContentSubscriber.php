<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\dx_copyright_guard\Service\AcceptanceStorage;
use Drupal\dx_copyright_guard\Service\InvestorAccess;
use Drupal\dx_copyright_guard\Service\ProtectedPathMatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gates protected paths behind login + agreement and serves the files.
 *
 * Static files under the configured source directory are never served by the
 * web server directly; requests are intercepted here, checked, and streamed
 * with no-store headers and browser-side deterrence injected into HTML.
 */
final class ProtectedContentSubscriber implements EventSubscriberInterface {

  /**
   * Remembers a validated invite so report assets/subpages load without it.
   */
  private const QUICK_COOKIE = 'dxcg_quick';

  /**
   * Extensions served by quick read. Documents that cannot display the
   * agreement inline (PDF, archives, office files) keep the strict gate.
   */
  private const QUICK_EXTENSIONS = [
    'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg',
    'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly ProtectedPathMatcher $matcher,
    private readonly AcceptanceStorage $acceptance,
    private readonly InvestorAccess $investorAccess,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Run after authentication (priority 300) so current_user is populated.
      KernelEvents::REQUEST => ['onRequest', 50],
    ];
  }

  /**
   * Enforces login/acceptance and streams protected files.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $config = $this->configFactory->get('dx_copyright_guard.settings');
    if (!$config->get('enabled')) {
      return;
    }
    $request = $event->getRequest();
    $path = trim($request->getPathInfo(), '/');
    if ($this->matcher->isExempt($path) || !$this->matcher->isProtected($path)) {
      return;
    }

    // Quick read: a valid invite code opens the HTML report with the agreement
    // rendered at the top and a per-invite watermark. Login and signature are
    // optional gates controlled from the settings form; both default to off.
    // Direct (non-invite) access and the native app readers always go through
    // the strict gates below.
    $quick = $this->quickReadInvite($request, $config);
    if ($quick !== NULL) {
      $requireSignature = $config->get('quick_read_require_signature') === TRUE;
      // A signature is recorded against an account, so asking for one implies
      // asking for login even when the login switch alone is off.
      $requireLogin = $requireSignature || $config->get('quick_read_require_login') === TRUE;
      $uid = (int) $this->currentUser->id();

      if ($requireLogin && $uid <= 0) {
        $event->setResponse(new RedirectResponse(Url::fromRoute('user.login', [], [
          'query' => ['destination' => $request->getRequestUri()],
        ])->toString()));
        return;
      }

      if ($requireSignature && !$this->acceptance->hasAccepted($uid)) {
        $event->setResponse(new RedirectResponse(Url::fromRoute('dx_copyright_guard.agreement', [], [
          'query' => [
            'destination' => $request->getRequestUri(),
            'invite' => (string) ($quick->code ?? ''),
          ],
        ])->toString()));
        return;
      }

      $response = $this->serve($path, $config, $request, $quick);
      if ($response === NULL) {
        $this->logger->warning('Protected content not found (quick read): @path', ['@path' => $path]);
        $event->setResponse(new Response('Not Found', 404, $this->securityHeaders()));
        return;
      }
      $this->rememberQuickRead($response, $request, $quick, $config);
      $event->setResponse($response);
      return;
    }

    if (($config->get('login_required') || $config->get('acceptance_required')) && $this->currentUser->isAnonymous()) {
      $event->setResponse(new RedirectResponse(Url::fromRoute('user.login', [], [
        'query' => ['destination' => $request->getRequestUri()],
      ])->toString()));
      return;
    }

    $uid = (int) $this->currentUser->id();

    // Role gate: signed-in users must hold investor access. A valid invite in
    // the URL (or handed out as `...?invite=CODE`) skips review and only has
    // to sign the agreement; everyone else applies for review.
    if ($uid > 0 && !$this->investorAccess->hasAccess($this->currentUser)) {
      $invite = trim((string) $request->query->get('invite', ''));
      $valid = $invite !== '' && $this->investorAccess->findValidInvite($invite) !== NULL;
      $route = $valid ? 'dx_copyright_guard.agreement' : 'dx_copyright_guard.access';
      $query = ['destination' => $request->getRequestUri()];
      if ($invite !== '') {
        $query['invite'] = $invite;
      }
      $event->setResponse(new RedirectResponse(Url::fromRoute($route, [], ['query' => $query])->toString()));
      return;
    }

    if ($config->get('acceptance_required') && $uid > 0 && !$this->acceptance->hasAccepted($uid)) {
      $event->setResponse(new RedirectResponse(Url::fromRoute('dx_copyright_guard.agreement', [], [
        'query' => ['destination' => $request->getRequestUri()],
      ])->toString()));
      return;
    }

    $response = $this->serve($path, $config, $request);
    if ($response === NULL) {
      $this->logger->warning('Protected content not found: @path', ['@path' => $path]);
      $event->setResponse(new Response('Not Found', 404, $this->securityHeaders()));
      return;
    }
    $event->setResponse($response);
  }

  /**
   * Serves a file from the protected source directory.
   */
  private function serve(string $path, ImmutableConfig $config, Request $request, ?object $quick = NULL): ?Response {
    $directory = trim((string) ($config->get('source_directory') ?? ''));
    if ($directory === '') {
      return NULL;
    }
    $root = $this->fileSystem->realpath($directory);
    if ($root === FALSE || !is_dir($root)) {
      return NULL;
    }
    $root = rtrim($root, DIRECTORY_SEPARATOR);

    $relative = $path !== '' ? $path : 'index.html';
    // A directory request without a trailing slash breaks relative links.
    if (!str_ends_with($request->getPathInfo(), '/')) {
      $probe = realpath($root . DIRECTORY_SEPARATOR . $relative);
      if ($probe !== FALSE && is_dir($probe) && str_starts_with($probe, $root . DIRECTORY_SEPARATOR)) {
        $target = rtrim($request->getBaseUrl(), '/') . '/' . $path . '/';
        if ($request->getQueryString()) {
          $target .= '?' . $request->getQueryString();
        }
        return new RedirectResponse($target, 301);
      }
    }

    $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
    if ($extension === '' || str_ends_with($relative, '/')) {
      $relative = rtrim($relative, '/') . '/index.html';
      $extension = 'html';
    }

    $candidate = $root . DIRECTORY_SEPARATOR . $relative;
    $real = realpath($candidate);
    if ($real === FALSE || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
      return NULL;
    }
    if (is_dir($real)) {
      $real = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.html';
    }
    if (!is_file($real)) {
      return NULL;
    }

    $headers = $this->securityHeaders();
    if (in_array($extension, ['html', 'htm'], TRUE)) {
      $html = (string) file_get_contents($real);
      $html = $this->injectGuard($html, $config, $path, $request, $quick);
      return new Response($html, 200, $headers + [
        'Content-Type' => 'text/html; charset=UTF-8',
      ]);
    }

    $response = new BinaryFileResponse($real);
    $response->headers->set('Content-Type', $extension === 'pdf'
      ? 'application/pdf'
      : $this->mimeType($extension));
    if ($extension === 'pdf') {
      $response->setContentDisposition('inline', basename($real));
    }
    foreach ($headers as $name => $value) {
      $response->headers->set($name, $value);
    }
    return $response;
  }

  /**
   * Injects the agreement panel, watermark and copy-guard CSS/JS.
   */
  private function injectGuard(string $html, ImmutableConfig $config, string $path, Request $request, ?object $quick = NULL): string {
    $moduleRoot = dirname(__DIR__, 2);
    $cssFile = $moduleRoot . '/css/dx_copyright_guard.css';
    $jsFile = $moduleRoot . '/js/dx_copyright_guard.js';
    $css = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
    $js = is_file($jsFile) ? (string) file_get_contents($jsFile) : '';

    $account = $this->currentUser;
    $quickMode = $quick !== NULL;
    $owner = $quickMode ? $this->quickOwner($quick) : '';
    $template = $quickMode
      ? (string) ($config->get('quick_watermark_template') ?: '{invite} · {date}')
      : (string) ($config->get('watermark_template') ?: '{name} · UID {uid} · {date}');
    $watermarkText = strtr($template, [
      '{name}' => $account->getDisplayName(),
      '{uid}' => (string) $account->id(),
      '{invite}' => $quickMode ? (string) ($quick->code ?? '') : '',
      '{owner}' => $owner,
      '{date}' => date('Y-m-d H:i'),
      '{ip}' => (string) ($request->getClientIp() ?? ''),
      '{path}' => $path,
      '{site}' => (string) ($this->configFactory->get('system.site')->get('name') ?? ''),
    ]);

    // Put the agreement at the very top of the document so quick readers see
    // it before any report content.
    if ($quickMode) {
      // The main report can carry an in-flow card. Secondary HTML pages such
      // as the full-screen deck use an overlay so their layout is untouched.
      $panel = $this->quickAgreementHtml($config, $quick, !$this->isPrimaryDocument($path));
      if (preg_match('~<body\b[^>]*>~i', $html) === 1) {
        $html = (string) preg_replace_callback(
          '~<body\b[^>]*>~i',
          static fn(array $matches): string => $matches[0] . $panel,
          $html,
          1,
        );
      }
      else {
        $html = $panel . $html;
      }
    }

    $guardConfig = [
      'watermark' => (bool) $config->get('watermark_enabled'),
      'watermarkText' => $watermarkText,
      'blockCopy' => (bool) $config->get('block_copy'),
      'blockKeys' => (bool) $config->get('block_keys'),
      'blurOnFocusLoss' => (bool) $config->get('blur_on_focus_loss'),
      'scrollGuard' => (bool) $config->get('scroll_guard'),
      'disablePrint' => (bool) $config->get('disable_print'),
    ];

    $snippet = '<style id="dxcg-style">' . $css . '</style>' . "\n"
      . '<script>window.__dxCopyrightGuard = ' . Json::encode($guardConfig) . ';</script>' . "\n"
      . '<script>' . $js . '</script>' . "\n";

    // Readers get a share entry on the full page. Embedded views (app / mini
    // program WebView) hand sharing to the native UI instead.
    if (!$request->query->has('embed') && (int) $account->id() > 0 && $this->investorAccess->canShare($account)) {
      $shareUrl = Url::fromRoute('dx_copyright_guard.access', [], [
        'query' => ['destination' => $request->getRequestUri()],
      ])->toString();
      $snippet .= '<a class="dxcg-share-pill" href="' . htmlspecialchars($shareUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
        . '分享阅读权限</a>' . "\n";
    }

    if (stripos($html, '</body>') !== FALSE) {
      // Use a callback so JSON/CSS/JS payloads are never parsed as backreferences.
      return (string) preg_replace_callback(
        '~</body>~i',
        static fn() => $snippet . '</body>',
        $html,
        1,
      );
    }
    return $html . $snippet;
  }

  /**
   * Builds the always-visible agreement card used by quick-read links.
   */
  private function quickAgreementHtml(ImmutableConfig $config, object $invite, bool $overlay = FALSE): string {
    $title = trim((string) ($config->get('agreement_title') ?? ''));
    if ($title === '') {
      $title = '版权与商业技术机密协议';
    }
    $intro = trim((string) ($config->get('quick_read_intro') ?? ''));
    if ($intro === '') {
      $intro = '本文件仅向受邀的投资人与合作伙伴开放。您通过邀请链接直接阅读，页面已展示完整协议并叠加专属水印；继续阅读即表示您已知悉并同意以下全部条款。';
    }
    $terms = (string) ($config->get('agreement_terms') ?? '');
    $version = (string) ($config->get('agreement_version') ?? '1.0');
    $code = (string) ($invite->code ?? '');
    $owner = $this->quickOwner($invite);
    $meta = ['邀请码 ' . $code];
    if ($owner !== '') {
      $meta[] = '分享人 ' . $owner;
    }
    $meta[] = '访问时间 ' . date('Y-m-d H:i');
    $meta[] = '协议版本 v' . $version;

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<section class="dxcg-quick' . ($overlay ? ' dxcg-quick--overlay' : '') . '" id="dxcg-quick" aria-label="' . $escape($title) . '">'
      . '<div class="dxcg-quick-head">'
      . '<span class="dxcg-quick-badge">QUICK READ · 快速阅读</span>'
      . '<button type="button" class="dxcg-quick-toggle" data-dxcg-quick-toggle aria-expanded="true">收起协议</button>'
      . '</div>'
      . '<div class="dxcg-quick-body">'
      . '<h2 class="dxcg-quick-title">' . $escape($title) . '</h2>'
      . '<p class="dxcg-quick-intro">' . nl2br($escape($intro)) . '</p>'
      . '<div class="dxcg-quick-terms" tabindex="0">' . nl2br($escape($terms)) . '</div>'
      . '<p class="dxcg-quick-meta">' . $escape(implode(' · ', $meta)) . '</p>'
      . '<p class="dxcg-quick-note">继续向下阅读即表示您已知悉并同意上述条款。本页已叠加专属水印，复制、截图与转发的记录均可追溯。</p>'
      . '</div>'
      . '</section>';
  }

  /**
   * Whether the HTML path is the report entry (index) rather than a subpage.
   */
  private function isPrimaryDocument(string $path): bool {
    $path = trim($path, '/');
    if ($path === '') {
      return TRUE;
    }
    $basename = basename($path);
    return $basename === 'index.html'
      || $basename === 'index.htm'
      || !str_contains($basename, '.');
  }

  /**
   * Display name behind an invite code, or TPST for staff-issued codes.
   */
  private function quickOwner(object $invite): string {
    $owner = $this->investorAccess->shareOwnerName((int) ($invite->owner_uid ?? 0));
    return $owner !== '' ? $owner : 'TPST';
  }

  /**
   * Resolves a valid quick-read invite from the URL or the invite cookie.
   */
  private function quickReadInvite(Request $request, ImmutableConfig $config): ?object {
    if ($config->get('quick_read_enabled') === FALSE) {
      return NULL;
    }
    if (!$this->isQuickReadablePath((string) $request->getPathInfo())) {
      return NULL;
    }
    $candidates = [
      trim((string) $request->query->get('invite', '')),
      trim((string) $request->cookies->get(self::QUICK_COOKIE, '')),
    ];
    foreach ($candidates as $code) {
      if ($code === '') {
        continue;
      }
      $invite = $this->investorAccess->findValidInvite($code);
      if ($invite !== NULL) {
        return $invite;
      }
    }
    return NULL;
  }

  /**
   * Only pages and their assets can be quick-read; documents keep the gate.
   */
  private function isQuickReadablePath(string $path): bool {
    $extension = strtolower((string) pathinfo(trim($path, '/'), PATHINFO_EXTENSION));
    if ($extension === '') {
      return TRUE;
    }
    return in_array($extension, self::QUICK_EXTENSIONS, TRUE);
  }

  /**
   * Remembers the invite for report assets and subpages.
   */
  private function rememberQuickRead(Response $response, Request $request, object $invite, ImmutableConfig $config): void {
    if (!$request->query->has('invite')) {
      return;
    }
    $days = max(1, min(90, (int) ($config->get('quick_read_days') ?? 7)));
    $response->headers->setCookie(Cookie::create(
      self::QUICK_COOKIE,
      (string) $invite->code,
      $this->time->getRequestTime() + $days * 86400,
      '/',
      NULL,
      $request->isSecure(),
      TRUE,
      FALSE,
      Cookie::SAMESITE_LAX,
    ));
  }

  /**
   * Headers applied to every protected response.
   *
   * @return array<string, string>
   *   HTTP headers.
   */
  private function securityHeaders(): array {
    return [
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma' => 'no-cache',
      'Expires' => '0',
      'X-Frame-Options' => 'SAMEORIGIN',
      'X-Content-Type-Options' => 'nosniff',
      'Referrer-Policy' => 'no-referrer',
      'X-Robots-Tag' => 'noindex, nofollow, noarchive',
      'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];
  }

  /**
   * Minimal MIME map for common report assets.
   */
  private function mimeType(string $extension): string {
    return match ($extension) {
      'css' => 'text/css; charset=UTF-8',
      'js', 'mjs' => 'application/javascript; charset=UTF-8',
      'json' => 'application/json; charset=UTF-8',
      'svg' => 'image/svg+xml',
      'png' => 'image/png',
      'jpg', 'jpeg' => 'image/jpeg',
      'webp' => 'image/webp',
      'gif' => 'image/gif',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
      'ttf' => 'font/ttf',
      'txt' => 'text/plain; charset=UTF-8',
      default => 'application/octet-stream',
    };
  }

}
