<?php

/**
 * WordPress constants and functions for PHPStan analysis
 */

// WordPress constants
if (!defined('ABSPATH')) {
  define('ABSPATH', '/var/www/html/');
}

if (!defined('WPINC')) {
  define('WPINC', 'wp-includes');
}

if (!defined('AUTH_KEY')) {
  define('AUTH_KEY', 'test-auth-key');
}

if (!defined('SECURE_AUTH_KEY')) {
  define('SECURE_AUTH_KEY', 'test-secure-auth-key');
}

if (!defined('LOGGED_IN_KEY')) {
  define('LOGGED_IN_KEY', 'test-logged-in-key');
}

if (!defined('NONCE_KEY')) {
  define('NONCE_KEY', 'test-nonce-key');
}

if (!isset($GLOBALS['wp_gh_updater_test_options']) || !is_array($GLOBALS['wp_gh_updater_test_options'])) {
  $GLOBALS['wp_gh_updater_test_options'] = [];
}

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
  $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
}

if (!isset($GLOBALS['wp_gh_updater_test_transients']) || !is_array($GLOBALS['wp_gh_updater_test_transients'])) {
  $GLOBALS['wp_gh_updater_test_transients'] = [];
}

if (!isset($GLOBALS['wp_gh_updater_test_http_response'])) {
  $GLOBALS['wp_gh_updater_test_http_response'] = null;
}

// WordPress functions stubs for PHPStan
if (!function_exists('wp_die')) {
  function wp_die(string $message = ''): void {
  }
}

if (!function_exists('add_action')) {
  function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    return true;
  }
}

if (!function_exists('add_filter')) {
  function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    return true;
  }
}

if (!function_exists('current_user_can')) {
  function current_user_can(string $capability): bool {
    return true;
  }
}

if (!function_exists('get_option')) {
  function get_option(string $option, mixed $default = false): mixed {
    if (array_key_exists($option, $GLOBALS['wp_gh_updater_test_options'])) {
      return $GLOBALS['wp_gh_updater_test_options'][$option];
    }

    return $default;
  }
}

if (!function_exists('update_option')) {
  function update_option(string $option, mixed $value): bool {
    $GLOBALS['wp_gh_updater_test_options'][$option] = $value;
    return true;
  }
}

if (!function_exists('add_option')) {
  function add_option(string $option, mixed $value): bool {
    if (array_key_exists($option, $GLOBALS['wp_gh_updater_test_options'])) {
      return false;
    }

    $GLOBALS['wp_gh_updater_test_options'][$option] = $value;
    return true;
  }
}

if (!function_exists('delete_option')) {
  function delete_option(string $option): bool {
    unset($GLOBALS['wp_gh_updater_test_options'][$option]);
    return true;
  }
}

if (!function_exists('wp_salt')) {
  function wp_salt(string $scheme = 'auth'): string {
    return 'test-salt-' . $scheme;
  }
}

if (!function_exists('sanitize_text_field')) {
  function sanitize_text_field(string $str): string {
    return $str;
  }
}

if (!function_exists('wp_unslash')) {
  function wp_unslash(mixed $value): mixed {
    return $value;
  }
}

if (!function_exists('wp_verify_nonce')) {
  function wp_verify_nonce(string $nonce, string $action): bool {
    return true;
  }
}

if (!function_exists('wp_create_nonce')) {
  function wp_create_nonce(string $action): string {
    return 'nonce';
  }
}

if (!function_exists('admin_url')) {
  function admin_url(string $path = ''): string {
    return 'https://example.com/wp-admin/' . $path;
  }
}

if (!function_exists('plugin_dir_path')) {
  function plugin_dir_path(string $file): string {
    return dirname($file) . '/';
  }
}

if (!function_exists('plugin_dir_url')) {
  function plugin_dir_url(string $file): string {
    return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
  }
}

if (!function_exists('wp_normalize_path')) {
  function wp_normalize_path(string $path): string {
    return str_replace('\\', '/', $path);
  }
}

if (!function_exists('trailingslashit')) {
  function trailingslashit(string $value): string {
    return rtrim($value, '/\\') . '/';
  }
}

if (!function_exists('untrailingslashit')) {
  function untrailingslashit(string $value): string {
    return rtrim($value, '/\\');
  }
}

if (!function_exists('sanitize_title')) {
  function sanitize_title(string $title): string {
    $title = strtolower($title);
    $title = preg_replace('/[^a-z0-9_-]+/', '-', $title);
    return trim((string) $title, '-');
  }
}

if (!function_exists('plugin_basename')) {
  function plugin_basename(string $file): string {
    return basename(dirname($file)) . '/' . basename($file);
  }
}

if (!function_exists('get_plugin_data')) {
  function get_plugin_data(string $plugin_file, bool $markup = true, bool $translate = true): array {
    return [
      'Name' => 'Plugin Name',
      'Version' => '1.0.0',
      'TextDomain' => 'plugin-textdomain'
    ];
  }
}

if (!function_exists('wp_upload_dir')) {
  function wp_upload_dir(): array {
    return [
      'path' => '/var/www/html/wp-content/uploads',
      'url' => 'https://example.com/wp-content/uploads',
      'basedir' => '/var/www/html/wp-content/uploads',
      'baseurl' => 'https://example.com/wp-content/uploads',
    ];
  }
}

if (!function_exists('wp_delete_file')) {
  function wp_delete_file(string $file): bool {
    return true;
  }
}

if (!function_exists('current_time')) {
  function current_time(string $type): string {
    return date($type === 'mysql' ? 'Y-m-d H:i:s' : 'U');
  }
}

if (!function_exists('wp_send_json')) {
  function wp_send_json(mixed $response): void {
    exit;
  }
}

if (!function_exists('is_wp_error')) {
  function is_wp_error(mixed $thing): bool {
    return $thing instanceof WP_Error;
  }
}

if (!function_exists('wp_remote_get')) {
  function wp_remote_get(string $url, array $args = []): array|WP_Error {
    if (isset($GLOBALS['wp_gh_updater_test_http_response'])) {
      $response = $GLOBALS['wp_gh_updater_test_http_response'];
      if (is_callable($response)) {
        return $response($url, $args);
      }
      return $response;
    }
    return [];
  }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
  function wp_remote_retrieve_response_code(array|WP_Error $response): int {
    if (is_array($response) && isset($response['response']['code'])) {
      return (int) $response['response']['code'];
    }
    return 200;
  }
}

if (!function_exists('wp_remote_retrieve_body')) {
  function wp_remote_retrieve_body(array|WP_Error $response): string {
    if (is_array($response) && isset($response['body'])) {
      return $response['body'];
    }
    return '';
  }
}

if (!function_exists('wp_remote_retrieve_header')) {
  function wp_remote_retrieve_header(array|WP_Error $response, string $header): string {
    if (is_array($response) && isset($response['headers'][$header])) {
      return (string) $response['headers'][$header];
    }
    return '';
  }
}

if (!function_exists('get_transient')) {
  function get_transient(string $transient): mixed {
    return $GLOBALS['wp_gh_updater_test_transients'][$transient] ?? false;
  }
}

if (!function_exists('set_transient')) {
  function set_transient(string $transient, mixed $value, int $expiration = 0): bool {
    $GLOBALS['wp_gh_updater_test_transients'][$transient] = $value;
    return true;
  }
}

if (!function_exists('delete_transient')) {
  function delete_transient(string $transient): bool {
    unset($GLOBALS['wp_gh_updater_test_transients'][$transient]);
    return true;
  }
}

if (!function_exists('wp_parse_args')) {
  function wp_parse_args(array|string $args, array $defaults = []): array {
    if (is_string($args)) {
      parse_str($args, $args);
    }
    return array_merge($defaults, $args);
  }
}

if (!function_exists('apply_filters')) {
  function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    return $value;
  }
}

if (!function_exists('set_site_transient')) {
  function set_site_transient(string $transient, mixed $value, int $expiration = 0): bool {
    return true;
  }
}

if (!function_exists('get_site_transient')) {
  function get_site_transient(string $transient): mixed {
    return false;
  }
}

if (!function_exists('delete_site_transient')) {
  function delete_site_transient(string $transient): bool {
    return true;
  }
}

if (!function_exists('wp_enqueue_script')) {
  function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $in_footer = false): void {
  }
}

if (!function_exists('wp_enqueue_style')) {
  function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void {
  }
}

if (!function_exists('wp_localize_script')) {
  function wp_localize_script(string $handle, string $object_name, array $l10n): bool {
    return true;
  }
}

// WordPress classes
if (!class_exists('WP_Error')) {
  class WP_Error {
    private string $code;
    private string $message;
    private mixed $data;

    public function __construct(string $code = '', string $message = '', mixed $data = '') {
      $this->code = $code;
      $this->message = $message;
      $this->data = $data;
    }
    public function get_error_message(): string {
      return $this->message;
    }
    public function get_error_code(): string {
      return $this->code;
    }
  }
}

// WP-CLI test stubs
if (!class_exists('WPCLITestException')) {
  class WPCLITestException extends \RuntimeException {}
}

if (!defined('WP_CLI')) {
  define('WP_CLI', true);
}

if (!class_exists('WP_CLI')) {
  class WP_CLI {
    /** @var array<int, array{method: string, message: string}> */
    public static array $captured = [];

    /** @var object|null */
    public static $runcommand_result = null;

    public static function error(string $message): void {
      self::$captured[] = ['method' => 'error', 'message' => $message];
      throw new WPCLITestException($message);
    }

    public static function success(string $message): void {
      self::$captured[] = ['method' => 'success', 'message' => $message];
    }

    public static function log(string $message): void {
      self::$captured[] = ['method' => 'log', 'message' => $message];
    }

    public static function warning(string $message): void {
      self::$captured[] = ['method' => 'warning', 'message' => $message];
    }

    public static function line(string $message): void {
      self::$captured[] = ['method' => 'line', 'message' => $message];
    }

    public static function add_command(string $name, mixed $callable, array $args = []): bool {
      self::$captured[] = ['method' => 'add_command', 'message' => $name];
      return true;
    }

    public static function runcommand(string $command, array $options = []): mixed {
      self::$captured[] = ['method' => 'runcommand', 'message' => $command];
      return self::$runcommand_result;
    }

    public static function reset(): void {
      self::$captured = [];
      self::$runcommand_result = null;
    }
  }
}
