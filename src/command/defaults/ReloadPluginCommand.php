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

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function count;
use function implode;
use function strtolower;

/**
 * [BETTERPMMP-PATCH]
 */
class ReloadPluginCommand extends VanillaCommand{

	private static string $lastPluginName = '';

	public function __construct(){
		parent::__construct(
			"reload",
			"Reload a plugin or all plugins",
			"/reload <all|pluginName>"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_RELOAD);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if($sender instanceof Player && !$sender->getServer()->isOp($sender->getName())){
			$sender->sendMessage('§cYou don\'t have permission to reload.');
			return true;
		}

		$pluginName = count($args) > 0 ? implode(" ", $args) : self::$lastPluginName;

		if($pluginName === ''){
			$sender->sendMessage('§cUsage: /reload <all|pluginName>');
			return true;
		}

		if(strtolower($pluginName) === 'all'){
			$pluginManager = $sender->getServer()->getPluginManager();
			$total = count($pluginManager->getPlugins());

			$sender->sendMessage("§eReloading all §f{$total}§e plugins (two-phase)...");

			try{
				$results = $pluginManager->reloadAll();
			}catch(\Throwable $e){
				$sender->getServer()->getLogger()->logException($e);
				$sender->sendMessage("§cCritical error during reload all.");
				return true;
			}

			$success = 0;
			$failed = [];
			foreach(Utils::stringifyKeys($results) as $name => $ok){
				if($ok){
					$success++;
				}else{
					$failed[] = $name;
				}
			}

			if(count($failed) > 0){
				$sender->sendMessage("§cFailed/skipped: §f" . implode("§c, §f", $failed));
			}
			$sender->sendMessage("§aReloaded §f{$success}§a/§f{$total}§a plugins.");
			return true;
		}

		$plugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);

		if($plugin === null){
			$sender->sendMessage('§cCan\'t find plugin.');
			return true;
		}

		self::$lastPluginName = $pluginName;

		try{
			$result = $sender->getServer()->getPluginManager()->reloadPlugin($plugin);
		}catch(\Throwable $e){
			$sender->getServer()->getLogger()->logException($e);
			$result = false;
		}

		if($result){
			$sender->sendMessage('§aPlugin reloaded successfully.');
		}else{
			$sender->sendMessage('§cFailed to reload plugin.');
		}

		return true;
	}

}
