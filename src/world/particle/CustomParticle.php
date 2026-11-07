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

namespace pocketmine\world\particle;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;

/**
 * Sends a particle defined in the client resource pack (e.g. particles/foo.json) via SpawnParticleEffectPacket.
 * Unlike the legacy LevelEvent-based particles, this can trigger custom particles shipped in resource packs.
 */
class CustomParticle implements Particle{

	/**
	 * @param string $particleName Fully-qualified particle identifier as declared in the resource pack
	 *                             (e.g. "minecraft:custom_snow_particle").
	 * @param int    $dimensionId  Dimension the particle is spawned in. Defaults to overworld.
	 * @phpstan-param DimensionIds::* $dimensionId
	 * @param string|null $molangVariablesJson Optional molang variables JSON forwarded to the client particle emitter.
	 * @param int         $actorUniqueId       Actor unique id the particle is attached to, or -1 for none.
	 */
	public function __construct(
		private string $particleName,
		private int $dimensionId = DimensionIds::OVERWORLD,
		private ?string $molangVariablesJson = null,
		private int $actorUniqueId = -1
	){}

	public function getParticleName() : string{ return $this->particleName; }

	/** @phpstan-return DimensionIds::* */
	public function getDimensionId() : int{ return $this->dimensionId; }

	public function getMolangVariablesJson() : ?string{ return $this->molangVariablesJson; }

	public function getActorUniqueId() : int{ return $this->actorUniqueId; }

	public function encode(Vector3 $pos) : array{
		return [SpawnParticleEffectPacket::create(
			$this->dimensionId,
			$this->actorUniqueId,
			$pos,
			$this->particleName,
			$this->molangVariablesJson
		)];
	}
}
