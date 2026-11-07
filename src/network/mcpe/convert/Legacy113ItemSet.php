<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\utils\Filesystem;
use function gzdecode;
use function is_array;
use function is_int;
use function json_decode;
use const pocketmine\RESOURCE_PATH;

/**
 * Set of items which exist in the 1.1.x creative inventory.
 *
 * Used to filter out items/recipes which the 1.1.x client doesn't know about - sending those makes the client
 * crash while building the creative menu or the recipe book.
 */
final class Legacy113ItemSet{
	/** @var array<string, true>|null */
	private static ?array $set = null;
	/** @var array<int, true>|null */
	private static ?array $ids = null;

	private function __construct(){
		//NOOP
	}

	private static function load() : void{
		$path = RESOURCE_PATH . 'vanilla/creative/113/creative_items.json.gz';
		$raw = @gzdecode(Filesystem::fileGetContents($path));
		$list = $raw !== false ? json_decode($raw, true) : null;

		$set = [];
		$ids = [];
		if(is_array($list)){
			foreach($list as $entry){
				if(is_array($entry) && isset($entry["id"]) && is_int($entry["id"])){
					$id = $entry["id"];
					$meta = is_int($entry["meta"] ?? null) ? $entry["meta"] : 0;
					$set[$id . ":" . $meta] = true;
					$ids[$id] = true;
				}
			}
		}

		self::$set = $set;
		self::$ids = $ids;
	}

	public static function contains(int $id, int $meta) : bool{
		if(self::$set === null){
			self::load();
		}
		if(isset(self::$set[$id . ":" . $meta])){
			return true;
		}
		if($meta === 0x7fff){
			//wildcard metadata: accept any variant of this item
			return isset(self::$ids[$id]);
		}
		return false;
	}
}
