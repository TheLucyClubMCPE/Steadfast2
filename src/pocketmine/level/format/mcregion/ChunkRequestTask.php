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

namespace pocketmine\level\format\mcregion;

use pocketmine\level\Level;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\Binary;
use pocketmine\network\protocol\FullChunkDataPacket;

class ChunkRequestTask extends AsyncTask {
	
	private $data;
	private $levelId;
	
	public function __construct($levelId, $chunkData) {
		$this->levelId = $levelId;
		$data = new \stdClass();
		$data->data = $chunkData;
		$this->data = $data;
	}

	public function onRun() {
		$result = array();	
		foreach ($this->data->data as $data) {	
			$offset = 8;
			$blockIdArray = substr($data->chunk, $offset, 32768);
			$offset += 32768;
			$blockDataArray = substr($data->chunk, $offset, 16384);
			$offset += 16384;
			$skyLightArray = substr($data->chunk, $offset, 16384);
			$offset += 16384;
			$blockLightArray = substr($data->chunk, $offset, 16384);
			$offset += 16384;
			$heightMapArray = array_values(unpack("C*", substr($data->chunk, $offset, 256)));
			$offset += 256;
			$biomeColorArray = array_values(unpack("N*", substr($data->chunk, $offset, 1024)));

			$ordered = $blockIdArray .
				$blockDataArray .
				$skyLightArray .
				$blockLightArray .
				pack("C*", ...$heightMapArray) .
				pack("N*", ...$biomeColorArray) .
				Binary::writeLInt(0) .
				$data->tiles;

			$pk = new FullChunkDataPacket();
			$pk->chunkX = $data->chunkX;
			$pk->chunkZ = $data->chunkZ;
			$pk->order = FullChunkDataPacket::ORDER_COLUMNS;
			$pk->data = $ordered;
			$pk->encode();
			$str = Binary::writeInt(strlen($pk->buffer)) . $pk->buffer;
			$ordered = zlib_encode($str, ZLIB_ENCODING_DEFLATE, 7);			
			$result[] = array(
				'chunkX' => $data->chunkX,
				'chunkZ' => $data->chunkZ,
				'result' => $ordered

			);		
		}
		$res = new \stdClass();
		$res->result = $result;
		$this->setResult($res);	
		unset($this->data);
	}
	
	public function onCompletion(Server $server) {
		$level = $server->getLevel($this->levelId);
		if ($level instanceof Level && $this->hasResult()) {	
			$res = $this->getResult();
			foreach ($res->result as $result) {	
				$level->chunkRequestCallback($result['chunkX'], $result['chunkZ'], $result['result']);
			}
		}
	}
}
