<?php

declare(strict_types=1);

namespace GameParrot\BridgeBlockFix;

use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use WeakMap;
use WeakReference;
use function array_push;
use function in_array;
use function microtime;

class BridgeBlockFixSession
{
    /** @var WeakMap<Player, BridgeBlockFixSession> */
    private static WeakMap $sessions;

    /** @var WeakReference<Player> */
    private WeakReference $player;

    protected float $lastRightClickTime = 0.0;
    protected ?UseItemTransactionData $lastRightClickData = null;

    public function __construct(Player $player)
    {
        $this->player = WeakReference::create($player);
    }

    public static function get(Player $player): self
    {
        if (!isset(self::$sessions)) {
            self::$sessions = new WeakMap();
        }

        return self::$sessions[$player] ??= new self($player);
    }

    public function handleClickBlockTransaction(UseItemTransactionData $data): void
    {
        $this->player->get()->selectHotbarSlot($data->getHotbarSlot());
        //TODO: start hack for client spam bug
        $clickPos = $data->getClickPosition();
        $spamBug = ($this->lastRightClickData !== null &&
            microtime(true) - $this->lastRightClickTime < 0.1 && //100ms
            $this->lastRightClickData->getPlayerPosition()->distanceSquared($data->getPlayerPosition()) < 0.00001 &&
            $this->lastRightClickData->getBlockPosition()->equals($data->getBlockPosition()) &&
            $this->lastRightClickData->getClickPosition()->distanceSquared($clickPos) < 0.00001 && //signature spam bug has 0 distance, but allow some error
            $data->getClientInteractPrediction() === PredictedResult::FAILURE
        );
        //get rid of continued spam if the player clicks and holds right-click
        $this->lastRightClickData = $data;
        $this->lastRightClickTime = microtime(true);
        if ($spamBug) {
            return;
        }
        //TODO: end hack for client spam bug

        self::validateFacing($data->getFace());

        $blockPos = $data->getBlockPosition();
        $vBlockPos = new Vector3($blockPos->getX(), $blockPos->getY(), $blockPos->getZ());
        if (!$this->player->get()->interactBlock($vBlockPos, $data->getFace(), $clickPos) && !$this->isFailedPrediction($data)) {
            $this->onFailedBlockAction($vBlockPos, $data->getFace());
        }
    }

    private static function validateFacing(int $facing): void
    {
        if (!in_array($facing, Facing::ALL, true)) {
            throw new PacketHandlingException("Invalid facing value $facing");
        }
    }

    private function isFailedPrediction(UseItemTransactionData $data): bool
    {
        return $data->getClientInteractPrediction() === PredictedResult::FAILURE;
    }

    private function onFailedBlockAction(Vector3 $blockPos, ?int $face): void
    {
        if ($blockPos->distanceSquared($this->player->get()->getLocation()) < 10000) {
            $blocks = $blockPos->sidesArray();
            if ($face !== null) {
                $sidePos = $blockPos->getSide($face);

                array_push($blocks, ...$sidePos->sidesArray()); //getAllSides() on each of these will include $blockPos and $sidePos because they are next to each other
            } else {
                $blocks[] = $blockPos;
            }
            foreach ($this->player->get()->getWorld()->createBlockUpdatePackets(TypeConverter::getInstance(), $blocks) as $packet) {
                $this->player->get()->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }
}
