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

namespace pocketmine\network\mcpe\handler;

use pocketmine\data\bedrock\block\upgrade\LegacyBlockIdToStringIdMap;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\cache\CraftingDataCache;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\convert\LegacyR12BlockStates;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\JigsawStructureDataPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ServerboundLoadingScreenPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\VoxelShapesPacket;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\LevelSettings;
use pocketmine\network\mcpe\protocol\types\NetworkPermissions;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\ServerTelemetryData;
use pocketmine\network\mcpe\protocol\types\SpawnSettings;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\VersionInfo;
use Ramsey\Uuid\Uuid;
use function array_flip;
use function sprintf;

/**
 * Handler used for the pre-spawn phase of the session.
 */
#[SilentDiscard(PlayerAuthInputPacket::class, comment: "Spammed after StartGame even though player has no controls")]
#[SilentDiscard(ServerboundLoadingScreenPacket::class, "Not needed")]
class PreSpawnPacketHandler extends PacketHandler{
	public function __construct(
		private Server $server,
		private Player $player,
		private NetworkSession $session,
		private InventoryManager $inventoryManager
	){}

	public function setUp() : void{
		Timings::$playerNetworkSendPreSpawnGameData->startTiming();
		try{
			$protocolId = $this->session->getProtocolId();
			$location = $this->player->getLocation();
			$world = $location->getWorld();

			$typeConverter = $this->session->getTypeConverter();

			$this->session->getLogger()->debug("Preparing StartGamePacket");
			$levelSettings = new LevelSettings();
			$levelSettings->seed = -1;
			$levelSettings->spawnSettings = new SpawnSettings(SpawnSettings::BIOME_TYPE_DEFAULT, "", DimensionIds::OVERWORLD); //TODO: implement this properly
			$levelSettings->worldGamemode = $typeConverter->coreGameModeToProtocol($this->server->getGamemode());
			$levelSettings->difficulty = $world->getDifficulty();
			$levelSettings->spawnPosition = BlockPosition::fromVector3($world->getSpawnLocation());
			$levelSettings->hasAchievementsDisabled = true;
			$levelSettings->time = $world->getTime();
			$levelSettings->eduEditionOffer = 0;
			$levelSettings->rainLevel = 0; //TODO: implement these properly
			$levelSettings->lightningLevel = 0;
			$levelSettings->commandsEnabled = true;
			$levelSettings->gameRules = [
				"naturalregeneration" => new BoolGameRule(false, false), //Hack for client side regeneration
			];
			if($protocolId >= ProtocolInfo::PROTOCOL_1_21_80){
				$levelSettings->gameRules["locatorbar"] = new BoolGameRule(false, false); //Disable client-side tracking of nearby players
			}
			$levelSettings->vanillaVersion = match($protocolId){
				ProtocolInfo::PROTOCOL_1_13_0 => "1.13.0",
				ProtocolInfo::PROTOCOL_1_14_0 => "1.14.0",
				ProtocolInfo::PROTOCOL_1_14_60 => "1.14.60",
				ProtocolInfo::PROTOCOL_1_16_0 => "1.16.0",
				ProtocolInfo::PROTOCOL_1_16_20 => "1.16.20",
				ProtocolInfo::PROTOCOL_1_16_100 => "1.16.100",
				ProtocolInfo::PROTOCOL_1_16_200 => "1.16.200",
				ProtocolInfo::PROTOCOL_1_16_210 => "1.16.210",
				ProtocolInfo::PROTOCOL_1_16_220 => "1.16.220",
				ProtocolInfo::PROTOCOL_1_17_0 => "1.17.0",
				ProtocolInfo::PROTOCOL_1_17_10 => "1.17.10",
				ProtocolInfo::PROTOCOL_1_17_30 => "1.17.30",
				ProtocolInfo::PROTOCOL_1_17_40 => "1.17.40",
				ProtocolInfo::PROTOCOL_1_18_0 => "1.18.0",
				ProtocolInfo::PROTOCOL_1_18_10 => "1.18.10",
				ProtocolInfo::PROTOCOL_1_18_30 => "1.18.30",
				ProtocolInfo::PROTOCOL_1_19_0 => "1.19.0",
				ProtocolInfo::PROTOCOL_1_19_10 => "1.19.10",
				ProtocolInfo::PROTOCOL_1_19_20 => "1.19.20",
				default => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
			};
			$levelSettings->experiments = new Experiments([], false);

			$blockPalette = [];
			$legacyBlockPalette = [];
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_50){
				//vanilla data-driven blocks have to be declared like custom blocks since 1.26.50,
				//otherwise the client fails with the "Block" (data send/receive) error screen
				$blockPalette = StaticPacketCache::getInstance()->getDataDrivenBlockPalette($protocolId);
			}
			if($protocolId === ProtocolInfo::PROTOCOL_1_12_0){
				$legacyBlockPalette = LegacyR12BlockStates::getInstance()->getBlockPaletteEntries($protocolId);
			}elseif($protocolId < ProtocolInfo::PROTOCOL_1_16_100){
				$legacyBlockIds = array_flip(LegacyBlockIdToStringIdMap::getInstance()->getLegacyToStringMap());
				foreach($typeConverter->getBlockTranslator()->getBlockStateDictionary()->getStates() as $entry){
					$state = $entry->generateStateData();
					$name = $state->getName();
					$blockPalette[] = new BlockPaletteEntry(
						$name,
						new CacheableNbt(CompoundTag::create()
							->setTag("block", $state->toVanillaNbt())
							->setShort("id", $legacyBlockIds[$name] ?? 0))
					);
				}
			}

			$staticPackets = StaticPacketCache::getInstance();
			$jigsawStructures = $staticPackets->getJigsawStructureData($protocolId);
			if($jigsawStructures !== null){
				//1.26.50+ clients require the jigsaw structure registry before StartGamePacket;
				//omitting it fails with "Missing structure data from the server" (error name "Boat"),
				//and an empty CompoundTag crashes it during deserialization because the expected keys
				//are absent. We send the real registry extracted from BDS (empty lists for all keys,
				//same payload Geyser sends - we don't generate vanilla structures anyway).
				$this->session->getLogger()->debug("Sending jigsaw structure data");
				$this->session->sendDataPacket($jigsawStructures);
			}
			$this->session->getLogger()->debug("Sending voxel shapes");
			$this->session->sendDataPacket($staticPackets->getVoxelShapes($protocolId));

			$this->session->sendDataPacket(StartGamePacket::create(
				$this->player->getId(),
				$this->player->getId(),
				$typeConverter->coreGameModeToProtocol($this->player->getGamemode()),
				$this->player->getOffsetPosition($location),
				$location->pitch,
				$location->yaw,
				new CacheableNbt(CompoundTag::create()), //TODO: we don't care about this right now
				$levelSettings,
				"",
				$this->server->getMotd(),
				"",
				false,
				new PlayerMovementSettings(
					ProtocolMovementAuthority::getMovementMode($protocolId),
					0,
					$protocolId > ProtocolInfo::PROTOCOL_1_19_20
				),
				0,
				0,
				"",
				true,
				sprintf("%s %s", VersionInfo::NAME, VersionInfo::VERSION()->getFullVersion(true)),
				Uuid::fromString(Uuid::NIL),
				false,
				false,
				false,
				new NetworkPermissions(disableClientSounds: true),
				true,
				null,
				new ServerTelemetryData("", "", "", ""),
				$blockPalette,
				$legacyBlockPalette,
				0,
				$typeConverter->getItemTypeDictionary()->getEntries(),
			));

			if($this->session->getProtocolId() >= ProtocolInfo::PROTOCOL_1_21_60){
				$this->session->getLogger()->debug("Sending items");
				$this->session->sendDataPacket(ItemRegistryPacket::create($typeConverter->getItemTypeDictionary()->getEntries()));
			}

			$this->session->getLogger()->debug("Sending actor identifiers");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getAvailableActorIdentifiers($this->session->getProtocolId()));

			$this->session->getLogger()->debug("Sending biome definitions");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getBiomeDefs($this->session->getProtocolId()));

			$this->session->getLogger()->debug("Sending attributes");
			$this->session->getEntityEventBroadcaster()->syncAttributes([$this->session], $this->player, $this->player->getAttributeMap()->getAll());

			$this->session->getLogger()->debug("Sending available commands");
			$this->session->syncAvailableCommands();

			$this->session->getLogger()->debug("Sending abilities");
			$this->session->syncAbilities($this->player);
			$this->session->syncAdventureSettings();

			$this->session->getLogger()->debug("Sending effects");
			foreach($this->player->getEffects()->all() as $effect){
				$this->session->getEntityEventBroadcaster()->onEntityEffectAdded([$this->session], $this->player, $effect, false);
			}

			$this->session->getLogger()->debug("Sending actor metadata");
			$this->player->sendData([$this->player]);

			$this->session->getLogger()->debug("Sending inventory");
			$this->inventoryManager->syncAll();
			$this->inventoryManager->syncSelectedHotbarSlot();

			$this->session->getLogger()->debug("Sending creative inventory data");
			$this->inventoryManager->syncCreative();

			$this->session->getLogger()->debug("Sending crafting data");
			$this->session->addToSendBuffer(CraftingDataCache::getInstance($protocolId)->getCache($this->server->getCraftingManager()));

			$this->session->getLogger()->debug("Sending player list");
			$this->session->syncPlayerList($this->server->getOnlinePlayers());

			if($protocolId === ProtocolInfo::PROTOCOL_1_1_5){
				//1.1.x clients wait for the chunk radius confirmation before asking for terrain
				$this->session->sendDataPacket(ChunkRadiusUpdatedPacket::create($this->player->getViewDistance()));
			}
			$this->session->getLogger()->info("[LoginTrace] StartGame spawn sequence fully sent");
		}finally{
			Timings::$playerNetworkSendPreSpawnGameData->stopTiming();
		}
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->radius);

		return true;
	}
}
