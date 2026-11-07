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
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;

final class PluginResources{

	/**
	 * @phpstan-param list<RegisteredListener<*>> $handlers
	 * @phpstan-param list<Command> $commands
	 * @phpstan-param list<Permission> $permissions
	 * @phpstan-param list<TaskHandler<covariant Task>> $schedulerTasks
	 */
	public function __construct(
		private array $handlers = [],
		private array $commands = [],
		private array $permissions = [],
		private array $schedulerTasks = []
	){}

	/** @phpstan-return list<RegisteredListener<*>> */
	public function getHandlers() : array{
		return $this->handlers;
	}

	/** @return list<Command> */
	public function getCommands() : array{
		return $this->commands;
	}

	/** @return list<Permission> */
	public function getPermissions() : array{
		return $this->permissions;
	}

	/** @phpstan-return list<TaskHandler<covariant Task>> */
	public function getSchedulerTasks() : array{
		return $this->schedulerTasks;
	}

	/** @phpstan-param list<TaskHandler<covariant Task>> $tasks */
	public function setSchedulerTasks(array $tasks) : void{
		$this->schedulerTasks = $tasks;
	}
}
