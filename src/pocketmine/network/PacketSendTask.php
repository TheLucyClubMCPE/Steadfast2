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

namespace pocketmine\network;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use pocketmine\network\CachedEncapsulatedPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\BatchPacket;
use pocketmine\utils\Binary;

class PacketSendTask extends AsyncTask {

	private $data;
	
	public function __construct($packetData) {
		$data = new \stdClass();
		$data->data = $packetData;
		$this->data = $data;
	}

	public function onRun() {
		$result = array();	
		foreach ($this->data->data as $data) {
			if($data->isBatch) {
				$str = "";
				foreach($data->packets as $p){
					if($p instanceof DataPacket){
						if(!$p->isEncoded){					
							$p->encode();
						}
						$str .= Binary::writeInt(strlen($p->buffer)) . $p->buffer;
					}else{
						$str .= Binary::writeInt(strlen($p)) . $p;
					}
				}
				
				$buffer = zlib_encode($str, ZLIB_ENCODING_DEFLATE, $data->networkCompressionLevel);
				$pk = new BatchPacket();
				$pk->payload = $buffer;
				$pk->encode();
				$pk->isEncoded = true;
				foreach($data->targets as $target){
					$result[] = $this->makeBuffer($target[0], $target[1], $pk, false, false);
				}
			} else {
				$result[] = $this->makeBuffer($data->identifier, $data->additionalChar, $data->packet, $data->needACK, $data->identifierACK);;
			}
		}
		$res = new \stdClass();
		$res->result = $result;
		$this->setResult($res);
		unset($this->data);
	}

	public function onCompletion(Server $server) {
		if($this->hasResult()) {
			$res = $this->getResult();
			foreach ($res->result as $result) {	
				$server->sendPacketBuffer($result);
			}
		}
	}

	
	private function makeBuffer($identifier, $additionalChar, $fullPacket, $needACK, $identifierACK) {
		$pk = null;
		if (!$fullPacket->isEncoded) {
			$fullPacket->encode();
		} elseif (!$needACK) {
			if (isset($fullPacket->__encapsulatedPacket)) {
				unset($fullPacket->__encapsulatedPacket);
			}
			$fullPacket->__encapsulatedPacket = new CachedEncapsulatedPacket();
			$fullPacket->__encapsulatedPacket->identifierACK = null;
			$fullPacket->__encapsulatedPacket->buffer = $additionalChar . $fullPacket->buffer;
			$fullPacket->__encapsulatedPacket->reliability = 2;
			$pk = $fullPacket->__encapsulatedPacket;
		}

		if ($pk === null) {
			$pk = new EncapsulatedPacket();
			$pk->buffer = $additionalChar . $fullPacket->buffer;
			$pk->reliability = 2;

			if ($needACK === true && $identifierACK !== false) {
				$pk->identifierACK = $identifierACK;
			}
		}

		$flags = ($needACK === true ? RakLib::FLAG_NEED_ACK : RakLib::PRIORITY_NORMAL) | (RakLib::PRIORITY_NORMAL);

		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($identifier)) . $identifier . chr($flags) . $pk->toBinary(true);				
		
		return $buffer;
	}
}


