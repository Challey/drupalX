<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

/**
 * A migrate field-mapping template could not be loaded or failed validation.
 *
 * The message always lists the offending keys so an operator fixing a YAML file
 * gets the whole picture in one pass instead of a silent fallback to `auto`.
 */
final class L2TemplateException extends \RuntimeException {

  /**
   * @param list<array{field: string, issue: string}> $issues
   */
  public function __construct(
    string $message,
    private readonly array $issues = [],
  ) {
    parent::__construct($message);
  }

  /**
   * Machine-readable validation issues ([field => issue] rows).
   *
   * @return list<array{field: string, issue: string}>
   */
  public function issues(): array {
    return $this->issues;
  }

  /**
   * Render the issues as a single readable line (drush / watchdog output).
   */
  public function issuesString(): string {
    return self::formatIssues($this->issues);
  }

  /**
   * Format a standalone issue list (no exception involved).
   *
   * @param list<array{field: string, issue: string}> $issues
   */
  public static function formatIssues(array $issues): string {
    if ($issues === []) {
      return '';
    }
    $bits = [];
    foreach ($issues as $issue) {
      $bits[] = ($issue['field'] ?? '?') . ': ' . ($issue['issue'] ?? '?');
    }
    return implode('; ', $bits);
  }

}
