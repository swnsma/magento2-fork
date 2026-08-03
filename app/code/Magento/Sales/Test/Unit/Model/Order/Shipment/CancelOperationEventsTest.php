<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Model\Order\Shipment;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\CanCancel;
use Magento\Sales\Model\Order\Shipment\CancelOperation;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;

/**
 * Ensures cancel dispatches extension hooks after a successful persist.
 */
class CancelOperationEventsTest extends TestCase
{
    use MockCreationTrait;

    public function testDispatchesCancelEventsAfterCommit(): void
    {
        $orderId = 10;
        $shipmentId = 20;

        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getItemId')->willReturn(1);
        $orderItem->method('getQtyShipped')->willReturn(2.0);
        $orderItem->expects($this->once())->method('setQtyShipped')->with(0.0)->willReturnSelf();

        $order = $this->createPartialMockWithReflection(
            Order::class,
            ['getEntityId', 'getItemById', 'setIsInProcess', 'addCommentToStatusHistory']
        );
        $order->method('getEntityId')->willReturn($orderId);
        $order->method('getItemById')->with(1)->willReturn($orderItem);
        $order->expects($this->once())->method('setIsInProcess')->with(true)->willReturnSelf();
        $order->expects($this->once())->method('addCommentToStatusHistory')->willReturnSelf();

        $shipmentItem = $this->getMockBuilder(ShipmentItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderItemId', 'getQty', 'setOrderItem', 'cancel', 'getOrderItem'])
            ->getMock();
        $shipmentItem->method('getOrderItemId')->willReturn(1);
        $shipmentItem->method('getQty')->willReturn(2.0);
        $shipmentItem->method('getOrderItem')->willReturn($orderItem);
        $shipmentItem->expects($this->once())->method('setOrderItem')->with($orderItem)->willReturnSelf();
        $shipmentItem->expects($this->once())->method('cancel')->willReturnCallback(
            static function () use ($orderItem, $shipmentItem) {
                $orderItem->setQtyShipped(0.0);
                return $shipmentItem;
            }
        );

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getEntityId')->willReturn($shipmentId);
        $shipment->method('getOrderId')->willReturn($orderId);
        $shipment->method('getIncrementId')->willReturn('100000001');
        $shipment->method('getAllItems')->willReturn([$shipmentItem]);
        $shipment->expects($this->once())->method('setOrder')->with($order)->willReturnSelf();
        $shipment->expects($this->once())
            ->method('setShipmentStatus')
            ->with(Shipment::STATUS_CANCELED)
            ->willReturnSelf();

        $canCancel = $this->createMock(CanCancel::class);
        $canCancel->method('execute')->with($shipment)->willReturn(true);

        $shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $shipmentRepository->method('get')->with($shipmentId)->willReturn($shipment);
        $shipmentRepository->expects($this->once())->method('save')->with($shipment)->willReturn($shipment);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->with($orderId)->willReturn($order);
        $orderRepository->expects($this->once())->method('save')->with($order)->willReturn($order);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->never())->method('rollBack');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->with('sales')->willReturn($connection);

        $orderMutex = $this->createMock(OrderMutexInterface::class);
        $orderMutex->method('execute')->willReturnCallback(
            static function (int $id, callable $callable) {
                return $callable();
            }
        );

        $dispatched = [];
        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $eventName, array $data = []) use (&$dispatched) {
                $dispatched[] = $eventName;
                return null;
            }
        );

        $operation = new CancelOperation(
            $canCancel,
            $eventManager,
            $shipmentRepository,
            $orderRepository,
            $orderMutex,
            $resourceConnection
        );

        $inputShipment = $this->createMock(Shipment::class);
        $inputShipment->method('getEntityId')->willReturn($shipmentId);
        $inputShipment->method('getOrderId')->willReturn($orderId);

        $operation->execute($inputShipment);

        $this->assertSame(
            [
                'sales_order_shipment_cancel_before',
                'sales_order_shipment_cancel',
                'sales_order_shipment_cancel_after',
                'sales_order_shipment_cancel_commit_after',
            ],
            $dispatched
        );
    }
}
