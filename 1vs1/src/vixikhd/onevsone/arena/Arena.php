<?php

/**
 * Copyright 2018-2019 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace vixikhd\onevsone\arena;

use pocketmine\block\Block;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\tile\Tile;
use vixikhd\onevsone\event\PlayerArenaWinEvent;
use vixikhd\onevsone\event\PlayerEquipEvent;
use vixikhd\onevsone\math\Vector3;
use vixikhd\onevsone\OneVsOne;

/**
 * Class Arena
 * @package onevsone\arena
 */
class Arena implements Listener {

    const MSG_MESSAGE = 0;
    const MSG_TIP = 1;
    const MSG_POPUP = 2;
    const MSG_TITLE = 3;

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    /** @var OneVsOne $plugin */
    public $plugin;

    /** @var ArenaScheduler $scheduler */
    public $scheduler;

    /** @var int $phase */
    public $phase = 0;

    /** @var array $data */
    public $data = [];

    /** @var bool $setting */
    public $setup = false;

    /** @var Player[] $players */
    public $players = [];

    /** @var Player[] $toRespawn */
    public $toRespawn = [];

    /** @var Level $level */
    public $level = null;

    /** @var string $kit */
    public $kit;

    /**
     * Arena constructor.
     * @param OneVsOne $plugin
     * @param array $arenaFileData
     */
    public function __construct(OneVsOne $plugin, array $arenaFileData) {
        $this->plugin = $plugin;
        $this->data = $arenaFileData;
        $this->setup = !$this->enable(\false);

        $this->plugin->getScheduler()->scheduleRepeatingTask($this->scheduler = new ArenaScheduler($this), 20);

        if($this->setup) {
            if(empty($this->data)) {
                $this->createBasicData();
            }
        }
        else {
            $this->loadArena();
        }
    }

    /**
     * @param Player $player
     */
    public function joinToArena(Player $player) {
        if(!$this->data["enabled"]) {
            $player->sendMessage("§c> Arena is under setup!");
            return;
        }

        if(count($this->players) >= $this->data["slots"]) {
            $player->sendMessage("§c> Arena is full!");
            return;
        }

        if($this->inGame($player)) {
            $player->sendMessage("§c> You are already in queue!");
            return;
        }

        $selected = false;
        for($lS = 1; $lS <= $this->data["slots"]; $lS++) {
            if(!$selected) {
                if(!isset($this->players[$index = "spawn-{$lS}"])) {
                    $player->teleport(Position::fromObject(Vector3::fromString($this->data["spawns"][$index]), $this->level));
                    $this->players[$index] = $player;
                    $selected = true;
                }
            }
        }

        $this->broadcastMessage("§a> Player {$player->getName()} joined the match! §7[".count($this->players)."/{$this->data["slots"]}]");

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $player->setGamemode($player::ADVENTURE);
        $player->setHealth(20);
        $player->setFood(20);

        $inv = $player->getArmorInventory();
        if(empty($this->plugin->dataProvider->config["kits"]) || !is_array($this->plugin->dataProvider->config["kits"]) || $this->kit === null) {
            $inv->setHelmet(Item::get(Item::DIAMOND_HELMET));
            $inv->setChestplate(Item::get(Item::DIAMOND_CHESTPLATE));
            $inv->setLeggings(Item::get(Item::DIAMOND_LEGGINGS));
            $inv->setBoots(Item::get(Item::DIAMOND_BOOTS));

            $player->getInventory()->addItem(Item::get(Item::IRON_SWORD));
            $player->getInventory()->addItem(Item::get(Item::GOLDEN_APPLE, 0, 5));
            $event = new PlayerEquipEvent($this->plugin, $player, $this);
            $event->call();
            return;
        }


        $kitData = $this->plugin->dataProvider->config["kits"][$this->kit];
        if(isset($kitData["helmet"])) $inv->setHelmet(Item::get($kitData["helmet"][0], $kitData["helmet"][1], $kitData["helmet"][2]));
        if(isset($kitData["chestplate"])) $inv->setChestplate(Item::get($kitData["chestplate"][0], $kitData["chestplate"][1], $kitData["chestplate"][2]));
        if(isset($kitData["leggings"])) $inv->setLeggings(Item::get($kitData["leggings"][0], $kitData["leggings"][1], $kitData["leggings"][2]));
        if(isset($kitData["boots"])) $inv->setBoots(Item::get($kitData["boots"][0], $kitData["boots"][1], $kitData["boots"][2]));

        foreach ($kitData as $slot => [$id, $damage, $count]) {
            if(is_numeric($slot)) {
                $slot = (int)$slot;
                $player->getInventory()->setItem($slot, Item::get($id, $damage, $count));
            }
        }

        $event = new PlayerEquipEvent($this->plugin, $player, $this);
        $event->call();
    }

    /**
     * @param Player $player
     * @param string $quitMsg
     * @param bool $death
     */

    public function startGame() {
        $players = [];
        foreach ($this->players as $player) {
            $players[$player->getName()] = $player;
        }


        $this->players = $players;
        $this->phase = 1;

        $this->broadcastMessage("Match Started!", self::MSG_TITLE);
    }

    public function startRestart() {
        $player = null;
        foreach ($this->players as $p) {
            $player = $p;
        }

        if($player === null || (!$player instanceof Player) || (!$player->isOnline())) {
            $this->phase = self::PHASE_RESTART;
            return;
        }

        $player->addTitle("§aYOU WON!");
        $this->plugin->getServer()->getPluginManager()->callEvent(new PlayerArenaWinEvent($this->plugin, $player, $this));
        $this->plugin->getServer()->broadcastMessage("§a[1vs1] Player {$player->getName()} won the match at {$this->level->getFolderName()}!");
        $this->phase = self::PHASE_RESTART;
    }

    /**
     * @param Player $player
     * @return bool $isInGame
     */
    public function inGame(Player $player): bool {
        switch ($this->phase) {
            case self::PHASE_LOBBY:
                $inGame = false;
                foreach ($this->players as $players) {
                    if($players->getId() == $player->getId()) {
                        $inGame = true;
                    }
                }
                return $inGame;
            default:
                return isset($this->players[$player->getName()]);
        }
    }

    /**
     * @param string $message
     * @param int $id
     * @param string $subMessage
     */
    public function broadcastMessage(string $message, int $id = 0, string $subMessage = "") {
        foreach ($this->players as $player) {
            switch ($id) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->addTitle($message, $subMessage);
                    break;
            }
        }
    }

    /**
     * @return bool $end
     */
    public function checkEnd(): bool {
        return count($this->players) <= 1;
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) {
        if($this->phase != self::PHASE_LOBBY) return;
        $player = $event->getPlayer();
        if($this->inGame($player)) {
            $index = null;
            foreach ($this->players as $i => $p) {
                if($p->getId() == $player->getId()) {
                    $index = $i;
                }
            }
            if($event->getPlayer()->asVector3()->distance(Vector3::fromString($this->data["spawns"][$index])) > 1) {
                // $event->setCancelled() will not work
                $player->teleport(Vector3::fromString($this->data["spawns"][$index]));
            }
        }
    }

    /**
     * @param PlayerExhaustEvent $event
     */
    public function onExhaust(PlayerExhaustEvent $event) {
        $player = $event->getPlayer();

        if(!$player instanceof Player) return;

        if($this->inGame($player) && $this->phase == self::PHASE_LOBBY && !$this->plugin->dataProvider->config["hunger"]) {
            $event->setCancelled(true);
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if($this->inGame($player) && $event->getBlock()->getId() == Block::CHEST && $this->phase == self::PHASE_LOBBY) {
            $event->setCancelled(\true);
            return;
        }

        if(!$block->getLevel()->getTile($block) instanceof Tile) {
            return;
        }

        $signPos = Position::fromObject(Vector3::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getLevelByName($this->data["joinsign"][1]));

        if((!$signPos->equals($block)) || $signPos->getLevel()->getId() != $block->getLevel()->getId()) {
            return;
        }

        if($this->phase == self::PHASE_GAME) {
            $player->sendMessage("§c> Arena is in-game");
            return;
        }
        if($this->phase == self::PHASE_RESTART) {
            $player->sendMessage("§c> Arena is restarting!");
            return;
        }

        if($this->setup) {
            return;
        }

        $this->joinToArena($player);
    }

    /**
     * @param PlayerDeathEvent $event
     */
    public function onDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();

        if(!$this->inGame($player)) return;

        foreach ($event->getDrops() as $item) {
            $player->getLevel()->dropItem($player, $item);
        }
        $this->toRespawn[$player->getName()] = $player;
        $this->disconnectPlayer($player, "", true);
        $this->broadcastMessage("§a> {$this->plugin->getServer()->getLanguage()->translate($event->getDeathMessage())} §7[".count($this->players)."/{$this->data["slots"]}]");
        $event->setDeathMessage("");
        $event->setDrops([]);
    }

    /**
     * @param PlayerRespawnEvent $event
     */
    public function onRespawn(PlayerRespawnEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->toRespawn[$player->getName()])) {
            $event->setRespawnPosition($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
            unset($this->toRespawn[$player->getName()]);
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) {
        if($this->inGame($event->getPlayer())) {
            $this->disconnectPlayer($event->getPlayer());
        }
    }

    /**
     * @param EntityLevelChangeEvent $event
     */
    public function onLevelChange(EntityLevelChangeEvent $event) {
        $player = $event->getEntity();
        if(!$player instanceof Player) return;
        if($this->inGame($player)) {
            $this->disconnectPlayer($player, "You are successfully leaved arena!");
        }
    }

    /**
     * @param bool $restart
     */
    public function loadArena(bool $restart = false) {
        if(!$this->data["enabled"]) {
            $this->plugin->getLogger()->error("Can not load arena: Arena is not enabled!");
            return;
        }

        if(!$restart) {
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            if(!$this->plugin->getServer()->isLevelLoaded($this->data["level"])) {
                $this->plugin->getServer()->loadLevel($this->data["level"]);
            }

            $this->level = $this->plugin->getServer()->getLevelByName($this->data["level"]);
        }

        else {
            $this->scheduler->reloadTimer();
        }

        if(!$this->level instanceof Level) $this->level = $this->plugin->getServer()->getLevelByName($this->data["level"]);

        $keys = array_keys($this->plugin->dataProvider->config["kits"]);
        $this->kit = $keys[array_rand($keys, 1)];

        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }

    /**
     * @param bool $loadArena
     * @return bool $isEnabled
     */
    public function enable(bool $loadArena = true): bool {
        if(empty($this->data)) {
            return false;
        }
        if($this->data["level"] == null) {
            return false;
        }
        if(!$this->plugin->getServer()->isLevelGenerated($this->data["level"])) {
            return false;
        }
        else {
            if(!$this->plugin->getServer()->isLevelLoaded($this->data["level"]))
                $this->plugin->getServer()->loadLevel($this->data["level"]);
            $this->level = $this->plugin->getServer()->getLevelByName($this->data["level"]);
        }
        if(!is_int($this->data["slots"])) {
            return false;
        }
        if(!is_array($this->data["spawns"])) {
            return false;
        }
        if(count($this->data["spawns"]) != $this->data["slots"]) {
            return false;
        }
        if(!is_array($this->data["joinsign"])) {
            return false;
        }
        if(count($this->data["joinsign"]) !== 2) {
            return false;
        }
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena) $this->loadArena();
        return true;
    }

    private function createBasicData() {
        $this->data = [
            "level" => null,
            "slots" => 2,
            "spawns" => [],
            "enabled" => false,
            "joinsign" => []
        ];
    }

    public function __destruct() {
        unset($this->scheduler);
    }
}
