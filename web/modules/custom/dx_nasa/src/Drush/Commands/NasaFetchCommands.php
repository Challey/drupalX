<?php

/**
 * @file
 * Drush commands for the dx_nasa mirror.
 */

declare(strict_types=1);

namespace Drupal\dx_nasa\Drush\Commands;

use Drupal\dx_nasa\Service\NasaFetcher;
use Drush\Commands\DrushCommands;

/**
 * Mirror management commands.
 */
class NasaFetchCommands extends DrushCommands {

  public function __construct(
    protected NasaFetcher $fetcher,
  ) {
    parent::__construct();
  }

  /**
   * Fetches and translates the NASA mirror content.
   *
   * @param string $type
   *   What to fetch: all, seed or apod.
   * @option force
   *   Re-fetch and re-translate even when content is unchanged.
   * @option pending
   *   Only retry pages whose translation is still pending.
   * @usage dx-nasa:fetch
   *   Incremental update of everything (seed + APOD).
   * @usage dx-nasa:fetch apod
   *   Refresh the astronomy picture of the day only.
   * @usage dx-nasa:fetch all --force
   *   Full forced rebuild of EN + ZH content.
   *
   * @command dx-nasa:fetch
   * @aliases dxnf
   */
  public function fetch(string $type = 'all', array $options = ['force' => FALSE, 'pending' => FALSE]): void {
    $force = (bool) $options['force'];
    if ($options['pending']) {
      $stats = $this->fetcher->translatePending();
      $this->logger()->success(dt('Pending translations: @ok ok, @failed failed.', [
        '@ok' => $stats['ok'],
        '@failed' => $stats['failed'],
      ]));
      return;
    }

    if (in_array($type, ['all', 'seed'], TRUE)) {
      $stats = $this->fetcher->fetchSeed($force);
      $this->logger()->success(dt('Seed fetch: @ok ok, @skipped skipped, @failed failed.', [
        '@ok' => $stats['ok'],
        '@skipped' => $stats['skipped'],
        '@failed' => $stats['failed'],
      ]));
      foreach ($stats['errors'] as $error) {
        $this->logger()->warning($error);
      }
    }
    if (in_array($type, ['all', 'apod'], TRUE)) {
      $ok = $this->fetcher->fetchApod($force);
      $this->logger()->success($ok ? dt('APOD updated.') : dt('APOD unchanged.'));
    }
  }

}
