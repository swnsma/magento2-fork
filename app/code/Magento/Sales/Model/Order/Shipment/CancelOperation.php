<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Shipment;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * Performs full cancellation of a shipment document.
 *
 * Reverses qty_shipped on order items and marks the shipment as canceled.
 * Does not refund payment. Stock/reservation adjustments are left to observers.
 *
 * Dispatched events (each payload includes `shipment` and `order`):
 *
 * | Event | When | Typical use |
 * |-------|------|-------------|
 * | sales_order_shipment_cancel_before | Before qty/status mutation | Validation / veto via exception |
 * | sales_order_shipment_cancel | After successful DB commit | Primary extension point |
 * | sales_order_shipment_cancel_after | After successful DB commit | Same timing as cancel |
 * | sales_order_shipment_cancel_commit_after | After successful DB commit | Post-commit work (e.g. stock) |
 *
 * Prefer sales_order_shipment_cancel_commit_after for external or stock-related side effects
 * so they run only after the sales transaction has committed.
 */
class CancelOperation
{
    /**
     * @var CanCancel
     */
    private $canCancel;

    /**
     * @var EventManagerInterface
     */
    private $eventManager;

    /**
     * @var ShipmentRepositoryInterface
     */
    private $shipmentRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderMutexInterface
     */
    private $orderMutex;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param CanCancel $canCancel
     * @param EventManagerInterface $eventManager
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderMutexInterface $orderMutex
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        CanCancel $canCancel,
        EventManagerInterface $eventManager,
        ShipmentRepositoryInterface $shipmentRepository,
        OrderRepositoryInterface $orderRepository,
        OrderMutexInterface $orderMutex,
        ResourceConnection $resourceConnection
    ) {
        $this->canCancel = $canCancel;
        $this->eventManager = $eventManager;
        $this->shipmentRepository = $shipmentRepository;
        $this->orderRepository = $orderRepository;
        $this->orderMutex = $orderMutex;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Cancel the given shipment fully.
     *
     * @param Shipment $shipment
     * @return Shipment
     * @throws LocalizedException
     */
    public function execute(Shipment $shipment): Shipment
    {
        $orderId = (int)$shipment->getOrderId();
        $shipmentId = (int)$shipment->getEntityId();
        if ($shipmentId <= 0 || $orderId <= 0) {
            throw new LocalizedException(__('This shipment cannot be canceled.'));
        }

        return $this->orderMutex->execute(
            $orderId,
            function () use ($shipmentId, $orderId) {
                return $this->cancelShipment($shipmentId, $orderId);
            }
        );
    }

    /**
     * Cancel shipment under order row lock with fresh entity data.
     *
     * @param int $shipmentId
     * @param int $orderId
     * @return Shipment
     * @throws LocalizedException
     */
    private function cancelShipment(int $shipmentId, int $orderId): Shipment
    {
        $order = $this->orderRepository->get($orderId);
        /** @var Shipment $shipment */
        $shipment = $this->shipmentRepository->get($shipmentId);
        $shipment->setOrder($order);

        if (!$this->canCancel->execute($shipment)) {
            throw new LocalizedException(__('This shipment cannot be canceled.'));
        }

        $eventData = ['shipment' => $shipment, 'order' => $order];
        $this->eventManager->dispatch('sales_order_shipment_cancel_before', $eventData);

        /** @var ShipmentItem $item */
        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $order->getItemById($item->getOrderItemId());
            if ($orderItem) {
                $item->setOrderItem($orderItem);
            }
            $item->cancel();
        }

        $shipment->setShipmentStatus(Shipment::STATUS_CANCELED);

        $order->setIsInProcess(true);
        $order->addCommentToStatusHistory(
            __('Shipment #%1 was canceled.', $shipment->getIncrementId())
        );

        $connection = $this->resourceConnection->getConnection('sales');
        $connection->beginTransaction();
        try {
            // Repository save keeps identity-map registry in sync (unlike raw model transaction).
            $this->shipmentRepository->save($shipment);
            $this->orderRepository->save($order);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        // Refresh payload references after save for observers.
        $eventData = ['shipment' => $shipment, 'order' => $order];

        $this->eventManager->dispatch('sales_order_shipment_cancel', $eventData);
        $this->eventManager->dispatch('sales_order_shipment_cancel_after', $eventData);
        $this->eventManager->dispatch('sales_order_shipment_cancel_commit_after', $eventData);

        return $shipment;
    }
}
