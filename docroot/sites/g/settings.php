<?php

/**
 * @file
 * Drupal site-specific configuration file.
 */

// Include custom settings.php code from factory-hooks/pre-settings-php.
if (function_exists('acsf_hooks_includes')) {
  foreach (acsf_hooks_includes('pre-settings-php') as $pre_hook) {
    include $pre_hook;
  }
}

/**
 * PHP settings:
 *
 * To see what PHP settings are possible, including whether they can be set at
 * runtime (by using ini_set()), read the PHP documentation:
 * http://php.net/manual/ini.list.php
 * See \Drupal\Core\DrupalKernel::bootEnvironment() for required runtime
 * settings and the .htaccess file for non-runtime settings.
 * Settings defined there should not be duplicated here so as to avoid conflict
 * issues.
 */

/**
 * If you encounter a situation where users post a large amount of text, and
 * the result is stripped out upon viewing but can still be edited, Drupal's
 * output filter may not have sufficient memory to process it.  If you
 * experience this issue, you may wish to uncomment the following two lines
 * and increase the limits of these variables.  For more information, see
 * http://php.net/manual/pcre.configuration.php.
 */
# ini_set('pcre.backtrack_limit', 200000);
# ini_set('pcre.recursion_limit', 200000);

/**
 * Fast 404 pages:
 *
 * Drupal can generate fully themed 404 pages. However, some of these responses
 * are for images or other resource files that are not displayed to the user.
 * This can waste bandwidth, and also generate server load.
 *
 * The options below return a simple, fast 404 page for URLs matching a
 * specific pattern:
 * - $config['system.performance']['fast_404']['exclude_paths']: A regular
 *   expression to match paths to exclude, such as images generated by image
 *   styles, or dynamically-resized images. The default pattern provided below
 *   also excludes the private file system. If you need to add more paths, you
 *   can add '|path' to the expression.
 * - $config['system.performance']['fast_404']['paths']: A regular expression to
 *   match paths that should return a simple 404 page, rather than the fully
 *   themed 404 page. If you don't have any aliases ending in htm or html you
 *   can add '|s?html?' to the expression.
 * - $config['system.performance']['fast_404']['html']: The html to return for
 *   simple 404 pages.
 *
 * Remove the leading hash signs if you would like to alter this functionality.
 */
# $config['system.performance']['fast_404']['exclude_paths'] = '/\/(?:styles)|(?:system\/files)\//';
# $config['system.performance']['fast_404']['paths'] = '/\.(?:txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';
# $config['system.performance']['fast_404']['html'] = '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL "@path" was not found on this server.</p></body></html>';

/**
 * Load services definition file.
 */
$settings['container_yamls'][] = __DIR__ . '/services.yml';

/**
 * Acquia Cloud Site Factory specific settings.
 */
if (file_exists('/var/www/site-php')) {
  // The DB role will be the same as the gardens site directory name.
  $request = \Drupal::hasRequest() ? \Drupal::request() : \Symfony\Component\HttpFoundation\Request::createFromGlobals();
  $role = basename(\Drupal\Core\DrupalKernel::findSitePath($request));
  // This global is set in sites.php. It's used to reference the
  // live environment DB setting even when running on the update env.
  $site_settings = !empty($GLOBALS['gardens_site_settings']) ? $GLOBALS['gardens_site_settings'] : array('site' => '', 'env' => '');
  $site = $site_settings['site'];
  $env = $site_settings['env'];

  $settings_inc = "/var/www/site-php/{$site}.{$env}/D8-{$env}-{$role}-settings.inc";
  if (file_exists($settings_inc)) {
    include $settings_inc;
    // Overwrite trusted_host_patterns setting, remove unnecessary hosts.
    // Allowed hosts for D8: https://www.drupal.org/node/2410395.
    // The overwrite doesn't cause any security problem because the valid hosts
    // were checked before in our sites.json registry.
    $str = "^" . str_replace('.', '\.', $_SERVER['HTTP_HOST']);
    $trusted_host = str_replace('*', '.+', $str) . "\$";
    $settings['trusted_host_patterns'] = array($trusted_host);
  }
  elseif (!isset($_SERVER['SERVER_SOFTWARE']) && (PHP_SAPI === 'cli' || (is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0))) {
    throw new Exception('No database connection file was found for DB {$role}.');
  }
  else {
    syslog(LOG_ERR, 'GardensError: AN-22471 - No database connection file was found for DB {$role}.');
    header($_SERVER['SERVER_PROTOCOL'] . ' 503 Service unavailable');
    print 'The website encountered an unexpected error. Please try again later.';
    exit;
  }
  // todo: this part needs to be rewritten, we might consider removing it
  // entirely for the time being.
  if (!class_exists('DrupalFakeCache')) {
    $config['cache_backends'][] = 'includes/cache-install.inc';
  }
  // Rely on the external Varnish cache for page caching.
  $config['cache_class_cache_page'] = 'DrupalFakeCache';
  $config['cache'] = 1;
  $config['page_cache_maximum_age'] = 300;
  // We can't use an external cache if we are trying to invoke these hooks.
  $config['page_cache_invoke_hooks'] = FALSE;

  if (!empty($site_settings['flags']['memcache_enabled']) && !empty($site_settings['memcache_inc'])) {
    $config['cache_backends'][] = $site_settings['memcache_inc'];
    $config['cache_default_class'] = 'MemCacheDrupal';
    $config['cache_class_cache_form'] = 'DrupalDatabaseCache';
    // The oembed cache in many cases should not evict data (given that data
    // is obtained from costly API calls and is not expected to change when
    // refreshed), so is more suited to the database than to memcache.
    $config['cache_class_cache_oembed'] = 'DrupalDatabaseCache';
  }

  // Until the site installation finishes, noone should be able to visit the
  // site, unless the site is being installed via install.php and the user has
  // the correct token to access it.
  if (PHP_SAPI !== 'cli' && !empty($site_settings['flags']['access_restricted']['enabled'])) {
    $token_match = !empty($site_settings['flags']['access_restricted']['token']) && !empty($_GET['site_install_token']) && $_GET['site_install_token'] == $site_settings['flags']['access_restricted']['token'];
    $path_match = $_SERVER['SCRIPT_NAME'] == $GLOBALS['base_path'] . 'install.php';
    if (!$token_match || !$path_match) {
      header($_SERVER['SERVER_PROTOCOL'] . ' 503 Service unavailable');
      if (!empty($site_settings['flags']['access_restricted']['reason'])) {
        print $site_settings['flags']['access_restricted']['reason'];
      }
      exit;
    }
  }
  if (isset($_ENV['AH_SITE_ENVIRONMENT'])) {
    // DG-10819: Enable Migrate background operations by default on all Acquia
    // hosting environments. See https://drupal.org/node/1958170. The path here
    // should be valid on all Acquia hosting servers, and will not take effect
    // on non-Acquia environments since AH_SITE_ENVIRONMENT won't be set in that
    // case.
    $config['migrate_drush_path'] = '/usr/local/bin/drush';
  }

  // Do not override the private path if the customer has defined its value
  // in a pre-settings-php hook.
  if (empty($settings['file_private_path']) && !empty($site_settings['file_private_path'])) {
    $settings['file_private_path'] = $site_settings['file_private_path'];
  }

  if (!empty($site_settings['conf'])) {
    foreach ((array) $site_settings['conf'] as $key => $value) {
      $config[$key] = $value;
    }
  }
}

/**
 * Location of the site configuration files.
 *
 * The $config_directories array specifies the location of file system
 * directories used for configuration data. On install, "active" and "sync"
 * directories are created for configuration. The sync directory is used for
 * configuration imports; the active directory is not used by default, since the
 * default storage for active configuration is the database rather than the file
 * system (this can be changed; see "Active configuration settings" below).
 *
 * The default location for the active and sync directories is inside a
 * randomly-named directory in the public files path; this setting allows you to
 * override these locations. If you use files for the active configuration, you
 * can enhance security by putting the active configuration outside your
 * document root.
 *
 * Example:
 * @code
 *   $config_directories = array(
 *     CONFIG_SYNC_DIRECTORY => '/another/directory/outside/webroot',
 *   );
 * @endcode
 */
if (isset($config_directories['vcs'])) {
  // The hosting settings include file adds a VCS config directory, but this can
  // only work with livedeev enabled.  Livedev is not supported on ACSF, and the
  // addition of this directory breaks site installs, so the VCS config
  // directory is removed for now.
  // @see https://backlog.acquia.com/browse/CL-11815
  // @see https://github.com/drush-ops/drush/pull/1711
  if (function_exists('drush_get_command')) {
    $command = drush_get_command();
    if (!empty($command['command']) && $command['command'] === 'site-install') {
      unset($config_directories['vcs']);
    }
  }
}

// Include custom settings.php code from factory-hooks/post-settings-php.
if (function_exists('acsf_hooks_includes')) {
  foreach (acsf_hooks_includes('post-settings-php') as $post_hook) {
    include $post_hook;
  }
}
require DRUPAL_ROOT . "/../vendor/acquia/blt/settings/blt.settings.php";
