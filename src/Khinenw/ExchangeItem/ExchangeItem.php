<?php

namespace Khinenw\ExchangeItem;

use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class ExchangeItem extends PluginBase implements Listener{

	public $shops, $config, $cases, $doubleTap, $placeQueue;

	public static $instance, $translation;

	const FROM_NOT_FOUND = 0;
	const TO_NOT_FOUND = 1;

	public function onEnable(){
		@mkdir($this->getDataFolder());

		self::$instance = $this;

		$this->pushFile("config.yml");
		$this->pushFile("translation_ko.yml");
		$this->pushFile("translation_en.yml");

		$this->config = (new Config($this->getDataFolder()."config.yml", Config::YAML))->getAll();

		$lang = "en";

		if(isset($this->config["language"]) && is_file($this->getDataFolder().$this->config["language"].".yml")){
			$lang = $this->config["language"];
		}

		self::$translation = (new Config($this->getDataFolder()."translation_$lang.yml", Config::YAML))->getAll();
		$this->shops = (new Config($this->getDataFolder()."shops.yml", Config::YAML))->getAll();

		foreach($this->shops as $loc => $data){
			$pos = $this->getPositionByLocation($loc);
			$this->shops[$loc]["case"] = new EntityItemCase($pos, $data["from"],$data["to"]);
		}

		$this->doubleTap = [];
		$this->placeQueue = [];

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function pushFile($fileName){
		if(!is_file($this->getDataFolder().$fileName)){
			$res = $this->getResource($fileName);
			file_put_contents($this->getDataFolder().$fileName, stream_get_contents($res));
			fclose($res);
			return true;
		}
		return false;
	}

	public function saveShops(){
		$shops = $this->shops;

		foreach($shops as $loc => $data){
			$shops[$loc]["case"] = "";
		}

		$conf = new Config($this->getDataFolder()."shops.yml", Config::YAML);
		$conf->setAll($shops);
		$conf->save();
	}

	public function addShop(Block $sign, Player $owner, $from, $to, $replace){
		$loc = $this->getLocationByPosition($sign);

		$wholeShop = explode(";", $from.$to.$replace);

		if(count($wholeShop) < 3) return self::FROM_NOT_FOUND;

		$from = explode(":", $wholeShop[0]);
		$to = explode(":", $wholeShop[1]);
		$replace = explode(":", $wholeShop[2]);

		if(count($from) < 1) return self::FROM_NOT_FOUND;

		$fromId  = $from[0];
		$fromDamage = isset($from[1]) ? $from[1] : 0;
		$fromCount = isset($from[2]) ? $from[2] : 1;

		if(count($to) < 1) return self::TO_NOT_FOUND;

		$toId = $to[0];
		$toDamage = isset($to[1]) ? $to[1] : 0;
		$toCount = isset($to[2]) ? $to[2] : 1;

		$fromItem = Item::get($fromId, $fromDamage, $fromCount);
		$toItem = Item::get($toId, $toDamage, $toCount);

		$isFromId = true;
		$isToId = true;

		if($fromItem === null || $fromItem->getId() === 0){
			$fromItem = Item::fromString($fromId);
			if($fromItem === null) return self::FROM_NOT_FOUND;
			$fromId = $fromItem->getId();
			$fromDamage = $fromItem->getDamage();
			$isFromId = false;
		}

		if($toItem === null || $toItem->getId() === 0){
			$toItem = Item::fromString($toId);
			if($toItem === null) return self::TO_NOT_FOUND;
			$toId = $toItem->getId();
			$toDamage = $toItem->getDamage();
			$isToId = false;
		}

		$fromName = $fromItem->getName();

		if(isset($replace[0]) && $replace[0] !== ""){
			$fromName = $replace[0];
		}else{
			if($fromName === "Unknown" && !$isFromId){
				$fromName = ucwords(str_replace("_", " ", $from[0]));
			}
		}

		$toName = $toItem->getName();

		if(isset($replace[1]) && $replace[1] !== ""){
			$toName = $replace[1];
		}else{
			if($toName === "Unknown" && !$isToId){
				$toName = ucwords(str_replace("_", " ", $to[0]));
			}
		}

		$this->shops[$loc] = [
			"from" => [
				"name" => $fromName." x $fromCount",
				"id" => $fromId,
				"damage" => $fromDamage,
				"count" => $fromCount
			],

			"to" => [
				"name" => $toName." x $toCount",
				"id" => $toId,
				"damage" => $toDamage,
				"count" => $toCount
			],

			"owner" => $owner->getName(),

			"desc" => (count($replace) >= 3) ? $replace[2] : ""
		];

		$this->shops[$loc]["case"] = new EntityItemCase($sign, $this->shops[$loc]["from"], $this->shops[$loc]["to"]);

		$this->saveShops();

		return $loc;
	}

	public function getLocationByPosition(Position $pos){
		return $pos->getLevel()->getFolderName().";".$pos->getX().";".$pos->getY().";".$pos->getZ();
	}

	public function getPositionByLocation($locText){
		$loc = explode(";", $locText);
		return new Position($loc[1], $loc[2], $loc[3], $this->getServer()->getLevelByName($loc[0]));
	}

	public function checkItemCase(Player $player){
		foreach($this->shops as $loc => $data){
			if($data["case"]->getLevel()->getFolderName() === $player->getLevel()->getFolderName()){
				$data["case"]->spawnTo($player);
			}
		}
	}

	public function setDoubleTap(Player $player, $loc){
		$this->doubleTap[$player->getName()] = [
			"id" => $loc,
			"time" => microtime(true)
		];

		$player->sendMessage(TextFormat::AQUA.self::getTranslation("DOUBLE_TAP_TO_EXCHANGE", $this->shops[$loc]["from"]["name"], $this->shops[$loc]["to"]["name"]));
	}

	public static function getTranslation($translationId, ...$args){
		if(!isset(self::$translation[$translationId])){
			return $translationId.", ".implode(", ", $args);
		}

		$translation = self::$translation[$translationId];

		foreach($args as $key => $value){
			$translation = str_replace("%s".($key + 1), $value, $translation);
		}

		return $translation;
	}

	public function onPlayerMove(PlayerMoveEvent $event){
		if($event->getFrom()->getLevel() === $event->getTo()->getLevel()) return;

		$this->checkItemCase($event->getPlayer());
	}

	public function onPlayerJoin(PlayerJoinEvent $event){
		$this->checkItemCase($event->getPlayer());
	}

	public function onBlockBreak(BlockBreakEvent $event){
		$loc = $this->getLocationByPosition($event->getBlock());

		if(isset($this->shops[$loc])){
			if(!$event->getPlayer()->hasPermission("exchange.destroy")){
				$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("NO_PERMISSION_DESTROY"));
				$event->setCancelled(true);
				return;
			}

			if($this->shops[$loc]["owner"] !== $event->getPlayer()->getName()){
				$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("NOT_YOUR_EXCHANGE"));
				$event->setCancelled(true);
				return;
			}

			$this->shops[$loc]["case"]->despawn();
			unset($this->shops[$loc]);
			$event->getPlayer()->sendMessage(TextFormat::AQUA.self::getTranslation("EXCHANGE_DESTROYED"));
			$this->saveShops();
		}
	}

	public function onSignChange(SignChangeEvent $event){
		$str = $event->getLines();

		if(strtolower($str[0]) !== "[exchange]") return;

		if(!$event->getPlayer()->hasPermission("exchange.create")){
			$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("NO_PERMISSION_CREATE"));
			return;
		}

		$returnVal = $this->addShop($event->getBlock(), $event->getPlayer(), $str[1], $str[2], $str[3]);

		if($returnVal === self::FROM_NOT_FOUND){
			$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("FROM_NOT_FOUND"));
			return;
		}elseif($returnVal === self::TO_NOT_FOUND){
			$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("TO_NOT_FOUND"));
			return;
		}

		$shopData = $this->shops[$returnVal];
		$event->setLine(0, self::getTranslation("EXCHANGE"));
		$event->setLine(1, self::getTranslation("ITEM_FROM", $shopData["from"]["name"]));
		$event->setLine(2, self::getTranslation("ITEM_TO", $shopData["to"]["name"]));
		$event->setLine(3, $shopData["desc"]);
	}

	public function onPlayerInteract(PlayerInteractEvent $event){
		if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
			return;
		}

		$loc = $this->getLocationByPosition($event->getBlock());

		if(!isset($this->shops[$loc])) return;

		$shopData = $this->shops[$loc];

		if($event->getItem()->isPlaceable()) array_push($this->placeQueue, $event->getPlayer());

		$event->setCancelled();

		if(!$event->getPlayer()->hasPermission("exchange.use")){
			$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("NO_PERMISSION_USE"));
			return;
		}

		if(!$event->getPlayer()->getInventory()->contains(Item::get($shopData["from"]["id"], $shopData["from"]["damage"], $shopData["from"]["count"]))){
			$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("INSUFFICIENT_ITEM"));
			return;
		}

		if(!$event->getPlayer()->getInventory()->canAddItem(Item::get($shopData["to"]["id"], $shopData["to"]["damage"], $shopData["to"]["count"]))){
			$event->getPlayer()->sendMessage(TextFormat::RED.self::getTranslation("INSUFFICIENT_INVENTORY"));
			return;
		}

		if(!isset($this->doubleTap[$event->getPlayer()->getName()])){
			$this->setDoubleTap($event->getPlayer(), $loc);
			return;
		}

		if($this->doubleTap[$event->getPlayer()->getName()]["id"] !== $loc){
			$this->setDoubleTap($event->getPlayer(), $loc);
			return;
		}

		if($this->doubleTap[$event->getPlayer()->getName()]["time"] - microtime(true) >= 1.5){
			$this->setDoubleTap($event->getPlayer(), $loc);
			return;
		}

		unset($this->doubleTap[$event->getPlayer()->getName()]);

		$event->getPlayer()->sendMessage(TextFormat::AQUA.self::getTranslation("EXCHANGED"));

		if(count($event->getPlayer()->getInventory()->removeItem(Item::get($shopData["from"]["id"], $shopData["from"]["damage"], $shopData["from"]["count"]))) <= 0){
			$event->getPlayer()->getInventory()->addItem(Item::get($shopData["to"]["id"], $shopData["to"]["damage"], $shopData["to"]["count"]));
		}
	}
}
