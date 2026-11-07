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

namespace pocketmine\network\mcpe\protocol;

/**
 * Maps modern string level sound identifiers back to the numeric sound IDs used by 1.1.x clients.
 */
final class Legacy113SoundIds{
	/** @var array<string, int> */
	private const STRING_TO_LEGACY = [
		"add.chest" => 37,
		"ambient" => 9,
		"ambient.baby" => 10,
		"ambient.in.water" => 11,
		"armor" => 36,
		"attach" => 66,
		"attack" => 39,
		"attack.nodamage" => 40,
		"boost" => 19,
		"bow" => 20,
		"bow.hit" => 56,
		"break" => 4,
		"break.block" => 52,
		"breathe" => 12,
		"bucket.empty.lava" => 84,
		"bucket.empty.water" => 83,
		"bucket.fill.lava" => 82,
		"bucket.fill.water" => 81,
		"bullet.hit" => 57,
		"burp" => 80,
		"camera.take_picture" => 105,
		"cast.spell" => 99,
		"charge" => 104,
		"chest.closed" => 61,
		"chest.open" => 60,
		"chorusdeath" => 97,
		"chorusgrow" => 96,
		"death" => 13,
		"death.in.water" => 14,
		"death.to.zombie" => 15,
		"default" => 106,
		"deny" => 68,
		"detach" => 67,
		"drink" => 29,
		"drop.slot" => 71,
		"eat" => 30,
		"elderguardian.curse" => 86,
		"explode" => 45,
		"extinguish.fire" => 58,
		"fall" => 8,
		"fall.big" => 23,
		"fall.small" => 24,
		"fang" => 103,
		"fire" => 46,
		"fizz" => 26,
		"flap" => 27,
		"flop" => 85,
		"fuse" => 48,
		"gallop" => 7,
		"glass" => 98,
		"haggle" => 92,
		"haggle.idle" => 95,
		"haggle.no" => 94,
		"haggle.yes" => 93,
		"heavy.step" => 6,
		"hit" => 1,
		"hurt" => 16,
		"hurt.in.water" => 17,
		"ignite" => 47,
		"item.fizz" => 59,
		"item.use.on" => 0,
		"jump" => 3,
		"land" => 34,
		"lava" => 79,
		"lava.pop" => 78,
		"levelup" => 55,
		"mad" => 18,
		"milk" => 43,
		"mob.warning" => 87,
		"mob.warning.baby" => 88,
		"note" => 72,
		"piston.in" => 74,
		"piston.out" => 75,
		"place" => 5,
		"plop" => 33,
		"pop" => 70,
		"portal" => 76,
		"power.off" => 65,
		"power.on" => 64,
		"prepare.attack" => 100,
		"prepare.summon" => 101,
		"prepare.wololo" => 102,
		"remedy" => 53,
		"saddle" => 35,
		"shake" => 32,
		"shear" => 42,
		"shoot" => 51,
		"shulker.close" => 91,
		"shulker.open" => 90,
		"shulkerbox.closed" => 63,
		"shulkerbox.open" => 62,
		"spawn" => 50,
		"splash" => 25,
		"squish.big" => 21,
		"squish.small" => 22,
		"stare" => 49,
		"step" => 2,
		"swim" => 28,
		"takeoff" => 31,
		"teleport" => 89,
		"thorns" => 73,
		"throw" => 38,
		"thunder" => 44,
		"tripod" => 69,
		"unfect" => 54,
		"warn" => 41,
		"water" => 77,
	];

	private function __construct(){
		//NOOP
	}

	public static function toLegacy(string $stringId) : ?int{
		return self::STRING_TO_LEGACY[$stringId] ?? null;
	}
}
