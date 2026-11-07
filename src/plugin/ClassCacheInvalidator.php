<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

/** [BETTERPMMP-PATCH] */

namespace pocketmine\plugin;

use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\utils\Utils;
use function array_key_exists;
use function array_keys;
use function count;
use function explode;
use function file_get_contents;
use function filemtime;
use function function_exists;
use function implode;
use function is_dir;
use function is_string;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

class ClassCacheInvalidator
{

	private static int $reloadVersion = 0;

	/**
	 * @param array<string, int> $previousMtimes
	 * @return array{mtimes: array<string, int>, changed: bool, version: int}
	 */
	public static function invalidateChanged(string $pluginPath, array $previousMtimes, ThreadSafeClassLoader $autoloader, string $rootNamespace = '') : array
	{
		$currentMtimes = self::scanMtimes($pluginPath);
		$changedFiles = self::detectChangedFiles($currentMtimes, $previousMtimes);

		if (count($changedFiles) === 0) {
			return ['mtimes' => $currentMtimes, 'changed' => false, 'version' => self::$reloadVersion];
		}

		++self::$reloadVersion;
		$version = self::$reloadVersion;

		$opcacheInvalidate = function_exists('opcache_invalidate') ? 'opcache_invalidate' : null;
		$allFiles = array_keys($currentMtimes);
		foreach ($changedFiles as $filePath) {
			if ($opcacheInvalidate !== null) {
				$opcacheInvalidate($filePath, true);
			}
		}

		if ($rootNamespace === '') {
			$rootNamespace = self::detectRootNamespace($pluginPath);
		}

		if ($rootNamespace !== '') {
			foreach ($allFiles as $filePath) {
				self::evalWithVersionedNamespace($filePath, $version, $rootNamespace);
			}
		}

		return ['mtimes' => $currentMtimes, 'changed' => true, 'version' => $version];
	}

	public static function getVersionedClassName(string $originalClass, string $rootNamespace, int $version) : string
	{
		if ($rootNamespace === '' || $version === 0) {
			return $originalClass;
		}
		$prefix = $rootNamespace . '\\';
		if (str_starts_with($originalClass, $prefix)) {
			return $rootNamespace . '\\v' . $version . '\\' . substr($originalClass, strlen($prefix));
		}
		if ($originalClass === $rootNamespace) {
			return $rootNamespace . '\\v' . $version;
		}
		return $originalClass;
	}

	public static function detectRootNamespace(string $pluginPath) : string
	{
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;
		if (!is_dir($scanTarget)) {
			return '';
		}

		$namespaces = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);
		foreach ($iterator as $filePath) {
			if (!is_string($filePath)) {
				continue;
			}
			if (!str_ends_with($filePath, '.php')) {
				continue;
			}
			$source = @file_get_contents($filePath);
			if ($source === false) {
				continue;
			}
			if (preg_match('/^\s*namespace\s+([^\s;{]+)/m', $source, $m) === 1) {
				$namespaces[] = $m[1];
			}
		}

		if (count($namespaces) === 0) {
			return '';
		}
		if (count($namespaces) === 1) {
			return $namespaces[0];
		}

		$firstParts = explode('\\', $namespaces[0], PHP_INT_MAX);
		$commonParts = $firstParts;
		foreach ($namespaces as $ns) {
			$parts = explode('\\', $ns, PHP_INT_MAX);
			$newCommon = [];
			for ($i = 0; $i < min(count($commonParts), count($parts)); $i++) {
				if ($commonParts[$i] === $parts[$i]) {
					$newCommon[] = $commonParts[$i];
				} else {
					break;
				}
			}
			$commonParts = $newCommon;
			if (count($commonParts) === 0) {
				break;
			}
		}

		return implode('\\', $commonParts);
	}

	/**
	 * @return array<string, int>
	 */
	private static function scanMtimes(string $pluginPath) : array
	{
		$mtimes = [];
		$srcPath = $pluginPath . DIRECTORY_SEPARATOR . 'src';
		$scanTarget = is_dir($srcPath) ? $srcPath : $pluginPath;

		if (!is_dir($scanTarget)) {
			return $mtimes;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($scanTarget, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
		);

		foreach ($iterator as $filePath) {
			if (!is_string($filePath)) {
				continue;
			}
			if (!str_ends_with($filePath, '.php')) {
				continue;
			}
			$mtime = filemtime($filePath);
			if ($mtime !== false) {
				$mtimes[$filePath] = $mtime;
			}
		}

		return $mtimes;
	}

	/**
	 * @param array<string, int> $currentMtimes
	 * @param array<string, int> $previousMtimes
	 * @return list<string>
	 */
	private static function detectChangedFiles(array $currentMtimes, array $previousMtimes) : array
	{
		$changed = [];
		foreach (Utils::stringifyKeys($currentMtimes) as $filePath => $mtime) {
			if (!array_key_exists($filePath, $previousMtimes)) {
				$changed[] = $filePath;
				continue;
			}
			if ($previousMtimes[$filePath] !== $mtime) {
				$changed[] = $filePath;
			}
		}
		return $changed;
	}

	private static function evalWithVersionedNamespace(string $filePath, int $version, string $rootNamespace) : void
	{
		$source = file_get_contents($filePath);
		if ($source === false) {
			return;
		}

		$source = preg_replace('/^<\?php\s*/i', '', $source);
		if ($source === null) {
			return;
		}
		$source = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m', '', $source, 1);
		if ($source === null) {
			return;
		}

		$escaped = preg_quote($rootNamespace, '/');

		// Version namespace declarations: namespace Root; -> namespace Root\v1;
		// Also handles: namespace Root\Sub; -> namespace Root\v1\Sub;
		$source = preg_replace_callback(
			'/^(\s*namespace\s+)(' . $escaped . ')([\s;{\\\\])/m',
			static function (array $matches) use ($version) : string {
				return $matches[1] . $matches[2] . '\\v' . $version . $matches[3];
			},
			$source
		);
		if ($source === null) {
			return;
		}

		// Version use statements referencing the plugin's own namespace
		$source = preg_replace_callback(
			'/^(\s*use\s+(?:function\s+|const\s+)?)(' . $escaped . ')(\\\\)/m',
			static function (array $matches) use ($version) : string {
				return $matches[1] . $matches[2] . '\\v' . $version . $matches[3];
			},
			$source
		);
		if ($source === null) {
			return;
		}

		try {
			eval($source);
		} catch (\Throwable $evalError) {
			\pocketmine\Server::getInstance()->getLogger()->warning(
				"[BetterPMMP] eval failed for {$filePath}: " . $evalError->getMessage()
			);
		}
	}

	public static function getCurrentVersion() : int
	{
		return self::$reloadVersion;
	}
}
