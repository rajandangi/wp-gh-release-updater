<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package WPGitHubReleaseUpdater
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load test constants.
require_once __DIR__ . '/constants.php';

// Load namespaced test shims.
require_once __DIR__ . '/wordpress-function-overrides.php';
