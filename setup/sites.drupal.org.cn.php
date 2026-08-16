<?php

/**
 * @file
 * Multisite directory aliasing for DrupalX.
 */

$sites = [];

// Local / lab
$sites['demo.drupalx.local'] = 'demo';
$sites['localhost.demo'] = 'demo';
$sites['platform-lab.drupalx.local'] = 'default';

// Production: DrupalX product homepage (was x.drupal.org.cn; www cut over from XMT).
$sites['www.drupal.org.cn'] = 'default';
$sites['drupal.org.cn'] = 'default';
$sites['x.drupal.org.cn'] = 'default';
