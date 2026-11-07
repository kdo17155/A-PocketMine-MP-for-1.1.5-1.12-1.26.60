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

use pocketmine\command\Command;
use pocketmine\event\RegisteredListener;
use pocketmine\permission\Permission;
use pocketmine\utils\Utils;
use function count;

final class PluginResourceIndex
{

	/** @phpstan-var array<string, PluginResources> */
	private array $resourceMap = [];

	/** @phpstan-var array<string, list<string>> */
	private array $reverseDependencyMap = [];

	/** @phpstan-var array<string, array<string, int>> */
	private array $mtimeSnapshots = [];

	/**
	 * @phpstan-param list<RegisteredListener<*>> $handlers
	 * @phpstan-param list<Command> $commands
	 * @phpstan-param list<Permission> $permissions
	 */
	public function trackPlugin(string $pluginName, array $handlers, array $commands, array $permissions) : void
	{
		$this->resourceMap[$pluginName] = new PluginResources($handlers, $commands, $permissions);
	}

	public function getResources(string $pluginName) : PluginResources
	{
		return $this->resourceMap[$pluginName] ?? new PluginResources();
	}

	/** @return list<string> */
	public function getDependents(string $pluginName) : array
	{
		return $this->reverseDependencyMap[$pluginName] ?? [];
	}

	/** @return array<string, int> */
	public function getMtimeSnapshot(string $pluginName) : array
	{
		return $this->mtimeSnapshots[$pluginName] ?? [];
	}

	/** @param array<string, int> $snapshot */
	public function updateMtimeSnapshot(string $pluginName, array $snapshot) : void
	{
		$this->mtimeSnapshots[$pluginName] = $snapshot;
	}

	public function removePlugin(string $pluginName) : void
	{
		unset($this->resourceMap[$pluginName]);
		unset($this->mtimeSnapshots[$pluginName]);

		foreach (Utils::stringifyKeys($this->reverseDependencyMap) as $target => $dependents) {
			$filtered = [];
			foreach ($dependents as $dep) {
				if ($dep !== $pluginName) {
					$filtered[] = $dep;
				}
			}
			if (count($filtered) === 0) {
				unset($this->reverseDependencyMap[$target]);
			} else {
				$this->reverseDependencyMap[$target] = $filtered;
			}
		}

		unset($this->reverseDependencyMap[$pluginName]);
	}

	/** @param list<PluginDescription> $pluginDescriptions */
	public function buildReverseDependencyMap(array $pluginDescriptions) : void
	{
		$this->reverseDependencyMap = [];

		foreach ($pluginDescriptions as $description) {
			$pluginName = $description->getName();
			$allDeps = [...$description->getDepend(), ...$description->getSoftDepend()];

			foreach ($allDeps as $depName) {
				if (!isset($this->reverseDependencyMap[$depName])) {
					$this->reverseDependencyMap[$depName] = [];
				}
				$this->reverseDependencyMap[$depName][] = $pluginName;
			}
		}
	}
}
