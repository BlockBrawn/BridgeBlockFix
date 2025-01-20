<?php

declare(strict_types=1);

namespace GameParrot\BridgeBlockFix;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\handler\ItemStackContainerIdTranslator;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use function count;

class Main extends PluginBase implements Listener
{
    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function handleInventoryTransaction(InventoryTransactionPacket $packet, Player $player): bool
    {
        if ($packet->trData instanceof UseItemTransactionData && $packet->trData->getActionType() === UseItemTransactionData::ACTION_CLICK_BLOCK) {
            $inventoryManager = $player->getNetworkSession()->getInvManager();
            if (!$inventoryManager) {
                return false;
            }

            if (count($packet->trData->getActions()) > 50) {
                throw new PacketHandlingException("Too many actions in inventory transaction");
            }
            if (count($packet->requestChangedSlots) > 10) {
                throw new PacketHandlingException("Too many slot sync requests in inventory transaction");
            }

            $inventoryManager->setCurrentItemStackRequestId($packet->requestId);
            $inventoryManager->addRawPredictedSlotChanges($packet->trData->getActions());

            BridgeBlockFixSession::get($player)->handleClickBlockTransaction($packet->trData);

            $inventoryManager->syncMismatchedPredictedSlotChanges();

            //requestChangedSlots asks the server to always send out the contents of the specified slots, even if they
            //haven't changed. Handling this is necessary to ensure the client inventory stays in sync if the server
            //rejects the transaction. The most common example of this is equipping armor by right-click, which doesn't send
            //a legacy prediction action for the destination armor slot.
            foreach ($packet->requestChangedSlots as $containerInfo) {
                foreach ($containerInfo->getChangedSlotIndexes() as $netSlot) {
                    [$windowId, $slot] = ItemStackContainerIdTranslator::translate($containerInfo->getContainerId(), $inventoryManager->getCurrentWindowId(), $netSlot);
                    $inventoryAndSlot = $inventoryManager->locateWindowAndSlot($windowId, $slot);
                    if ($inventoryAndSlot !== null) { //trigger the normal slot sync logic
                        $inventoryManager->onSlotChange($inventoryAndSlot[0], $inventoryAndSlot[1]);
                    }
                }
            }

            $inventoryManager->setCurrentItemStackRequestId(null);

            return true;
        }

        return false;
    }

    public function onDataPacket(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();

        if (!($packet instanceof InventoryTransactionPacket)) {
            return;
        }

        if (!is_null($player = $event->getOrigin()->getPlayer()) && $this->handleInventoryTransaction($packet, $player)) {
            $event->cancel();
        }
    }
}
