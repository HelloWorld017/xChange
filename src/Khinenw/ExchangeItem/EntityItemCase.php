<?php

namespace Khinenw\ExchangeItem;

use pocketmine\entity\Entity;
use pocketmine\entity\Item as ItemEntity;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\Network;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class EntityItemCase{

	/** @var Position */
	public $pos;

	/** @var AddEntityPacket[] */
	public $packets;

	public function __construct(Position $pos, $fromData, $toData){
		$this->pos = $pos;
		$this->packets = [
			$this->getTextPacket($pos->add(0.5, 1.25, 0.5), TextFormat::GOLD.$fromData["name"]."\n".ExchangeItem::getTranslation("TO")."\n".TextFormat::AQUA.$toData["name"])
		];

		$this->spawnToAll();
	}

	public function getLevel(){
		return $this->pos->getLevel();
	}

	public function getTextPacket(Vector3 $pos, $text){
		$eid = ++Entity::$entityCount;

		return "$eid;".$pos->getX().";".$pos->getY().";".$pos->getZ().";$text";
	}

	public function getRemovePacket($eid){
		$pk = new RemoveEntityPacket();
		$pk->eid = $eid;
		return $pk->setChannel(Network::CHANNEL_ENTITY_SPAWNING);
	}

	public function spawnTo(Player $player){
		foreach($this->packets as $packet){
			$explodedPacket = explode(";", $packet);

			$textPk = new AddEntityPacket();
			$textPk->eid = $explodedPacket[0];
			$textPk->type = ItemEntity::NETWORK_ID;
			$textPk->x = $explodedPacket[1];
			$textPk->y = $explodedPacket[2];
			$textPk->z = $explodedPacket[3];
			$textPk->speedX = 0;
			$textPk->speedY = 0;
			$textPk->speedZ = 0;
			$textPk->yaw = 0;
			$textPk->pitch = 0;
			$textPk->item = 0;
			$textPk->meta = 0;
			$textPk->metadata = [
				Entity::DATA_FLAGS => [Entity::DATA_TYPE_BYTE, 1 << Entity::DATA_FLAG_INVISIBLE],
				Entity::DATA_NAMETAG => [Entity::DATA_TYPE_STRING, $explodedPacket[4]],
				Entity::DATA_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, 1],
				Entity::DATA_NO_AI => [Entity::DATA_TYPE_BYTE, 1]
			];

			$player->dataPacket($textPk->setChannel(Network::CHANNEL_ENTITY_SPAWNING));
		}
	}

	public function spawnToAll(){
		foreach($this->packets as $packet){
			foreach(Server::getInstance()->getOnlinePlayers() as $player){
				$player->dataPacket($packet);
			}
		}
	}

	public function despawn(){
		foreach($this->packets as $packet){
			Server::broadcastPacket(Server::getInstance()->getOnlinePlayers(), $this->getRemovePacket($packet->eid));
		}
	}
}
