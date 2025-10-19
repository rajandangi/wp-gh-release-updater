<?php

/**
 * Rector configuration for WP GitHub Release Updater
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;

return static function (RectorConfig $rectorConfig): void {
  // Paths to refactor
  $rectorConfig->paths([
    __DIR__ . '/src',
  ]);

  // Skip files/folders
  $rectorConfig->skip([
    __DIR__ . '/vendor',
    __DIR__ . '/src/admin/views', // Skip template files
  ]);

  // Set target PHP version
  $rectorConfig->phpVersion(\Rector\ValueObject\PhpVersion::PHP_83);

  // Apply rule sets
  $rectorConfig->sets([
    LevelSetList::UP_TO_PHP_83,
    SetList::CODE_QUALITY,
    SetList::CODING_STYLE,
    SetList::DEAD_CODE,
    SetList::EARLY_RETURN,
    SetList::TYPE_DECLARATION,
    SetList::STRICT_BOOLEANS,
    SetList::INSTANCEOF,
    SetList::PRIVATIZATION,
  ]);

  // Configure specific rules
  $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);
  $rectorConfig->rule(AddVoidReturnTypeWhereNoReturnRector::class);
  $rectorConfig->rule(RemoveUselessParamTagRector::class);
  $rectorConfig->rule(RemoveUselessReturnTagRector::class);

  // Import names
  $rectorConfig->importNames();
  $rectorConfig->importShortClasses();
};