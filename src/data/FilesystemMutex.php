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

namespace pocketmine\data;

use Closure;
use pocketmine\utils\Utils;
use function fclose;
use function flock;
use function fopen;
use const LOCK_EX;
use const LOCK_UN;

/**
 * @internal
 */
final class FilesystemMutex{
	private bool $locked = false;

	public function __construct(
		private string $path,
	){
		//NOOP
	}

	/** @return Closure() : void */
	private function acquire() : Closure{
		if($this->locked){
			return static fn() => null;
		}

		$file = Utils::assumeNotFalse(fopen($this->path, "c+b"));
		Utils::assumeNotFalse(flock($file, LOCK_EX));
		$this->locked = true;

		return function() use ($file) : void{
			flock($file, LOCK_UN);
			fclose($file);
			$this->locked = false;
		};
	}

	/**
	 * @template T
	 * @param Closure() : T $closure
	 * @return T
	 */
	public function do(Closure $closure) : mixed{
		$release = $this->acquire();
		try{
			return $closure();
		}finally{
			$release();
		}
	}
}
