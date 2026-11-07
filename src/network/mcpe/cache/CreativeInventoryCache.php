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

namespace pocketmine\network\mcpe\cache;

use pmmp\encoding\ByteBufferWriter;
use pocketmine\inventory\CreativeCategory;
use pocketmine\inventory\CreativeInventory;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\convert\Legacy113ItemSet;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CreativeContentPacket;
use pocketmine\network\mcpe\protocol\types\inventory\CreativeGroupEntry;
use pocketmine\network\mcpe\protocol\types\inventory\CreativeItemEntry;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\timings\Timings;
use pocketmine\utils\ProtocolSingletonTrait;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use function array_filter;
use function array_values;
use function gzdecode;
use function json_decode;
use function is_array;
use function is_int;
use function is_string;
use function spl_object_id;
use const PHP_INT_MIN;

final class CreativeInventoryCache{
	use ProtocolSingletonTrait;

	/**
	 * @var CreativeInventoryCacheEntry[]
	 * @phpstan-var array<int, CreativeInventoryCacheEntry>
	 */
	private array $caches = [];

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $encodedCaches = [];

	private function getCacheEntry(CreativeInventory $inventory) : CreativeInventoryCacheEntry{
		$id = spl_object_id($inventory);
		if(!isset($this->caches[$id])){
			$inventory->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id], $this->encodedCaches[$id]);
			});
			$inventory->getContentChangedCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id], $this->encodedCaches[$id]);
			});
			$this->caches[$id] = $this->buildCacheEntry($inventory);
		}
		return $this->caches[$id];
	}

	public function getCache(CreativeInventory $inventory, NetworkSession $session) : string{
		$id = spl_object_id($inventory);
		if(!isset($this->encodedCaches[$id])){
			Timings::$creativeContentCacheRebuild->startTiming();
			$packet = $this->buildPacket($inventory, $session);
			$out = new ByteBufferWriter();
			NetworkSession::encodePacketTimed($out, $this->protocolId, $packet);
			Timings::$creativeContentCacheRebuild->stopTiming();
			$this->encodedCaches[$id] = $out->getData();
		}
		return $this->encodedCaches[$id];
	}

	/**
	 * Rebuild the cache for the given inventory.
	 */
	private function buildCacheEntry(CreativeInventory $inventory) : CreativeInventoryCacheEntry{
		$categories = [];
		$groups = [];

		$typeConverter = TypeConverter::getInstance($this->getProtocolId());

		$nextIndex = 0;
		$groupIndexes = [];
		$itemGroupIndexes = [];

		foreach($inventory->getAllEntries() as $k => $entry){
			$group = $entry->getGroup();
			$category = $entry->getCategory();
			if($group === null){
				$groupId = PHP_INT_MIN;
			}else{
				$groupId = spl_object_id($group);
				unset($groupIndexes[$category->name][PHP_INT_MIN]); //start a new anonymous group for this category
			}

			//group object may be reused by multiple categories
			if(!isset($groupIndexes[$category->name][$groupId])){
				$groupIndexes[$category->name][$groupId] = $nextIndex++;
				$categories[] = $category;
				$groups[] = $group;
			}
			$itemGroupIndexes[$k] = $groupIndexes[$category->name][$groupId];
		}

		//creative inventory may have holes if items were unregistered - ensure network IDs used are always consistent
		$items = [];
		foreach($inventory->getAllEntries() as $k => $entry){
			$items[] = new CreativeItemEntry(
				$k,
				$typeConverter->coreItemStackToNet($entry->getItem()),
				$itemGroupIndexes[$k]
			);
		}

		return new CreativeInventoryCacheEntry($categories, $groups, $items);
	}

	public function buildPacket(CreativeInventory $inventory, NetworkSession $session) : CreativeContentPacket{
		$player = $session->getPlayer() ?? throw new \LogicException("Cannot prepare creative data for a session without a player");
		$language = $player->getLanguage();
		$forceLanguage = $player->getServer()->isLanguageForced();
		$typeConverter = $session->getTypeConverter();
		$cachedEntry = $this->getCacheEntry($inventory);
		$translate = function(Translatable|string $translatable) use ($session, $language, $forceLanguage) : string{
			if(is_string($translatable)){
				$message = $translatable;
			}elseif(!$forceLanguage){
				[$message,] = $session->prepareClientTranslatableMessage($translatable);
			}else{
				$message = $language->translate($translatable);
			}
			return $message;
		};

		$groupEntries = [];
		foreach($cachedEntry->categories as $index => $category){
			$group = $cachedEntry->groups[$index];
			$categoryId = match ($category) {
				CreativeCategory::CONSTRUCTION => CreativeContentPacket::CATEGORY_CONSTRUCTION,
				CreativeCategory::NATURE => CreativeContentPacket::CATEGORY_NATURE,
				CreativeCategory::EQUIPMENT => CreativeContentPacket::CATEGORY_EQUIPMENT,
				CreativeCategory::ITEMS => CreativeContentPacket::CATEGORY_ITEMS
			};
			if($group === null){
				$groupEntries[] = new CreativeGroupEntry($categoryId, "", ItemStack::null());
			}else{
				$groupIcon = $group->getIcon();
				//TODO: HACK! In 1.21.60, Workaround glitchy behaviour when an item is used as an icon for a group it
				//doesn't belong to. Without this hack, both instances of the item will show a +, but neither of them
				//will actually expand the group work correctly.
				$groupIcon->getNamedTag()->setInt("___GroupBugWorkaround___", $index);
				$groupName = $group->getName();
				$groupEntries[] = new CreativeGroupEntry(
					$categoryId,
					$translate($groupName),
					$typeConverter->coreItemStackToNet($groupIcon)
				);
			}
		}

		$items = $cachedEntry->items;
		if($this->getProtocolId() === ProtocolInfo::PROTOCOL_1_1_5){
			//1.1.x only knows a subset of the items; anything newer crashes the client's creative menu
			$before = count($items);
			$items = array_values(array_filter($items, function(CreativeItemEntry $entry) : bool{
				$item = $entry->getItem();
				return Legacy113ItemSet::contains($item->getId(), $item->getMeta());
			}));
			try{ \GlobalLogger::get()->info("[Creative113] filtered creative items " . $before . " -> " . count($items)); }catch(\Throwable){}
		}

		return CreativeContentPacket::create($groupEntries, $items);
	}
}
