<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Model\Order\Shipment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Shipment\Item;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for reversing qty_shipped on shipment item cancel.
 */
class ItemCancelTest extends TestCase
{
    public function testCancelDecreasesQtyShipped(): void
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->expects($this->once())->method('getQtyShipped')->willReturn(5.0);
        $orderItem->expects($this->once())->method('setQtyShipped')->with(3.0)->willReturnSelf();

        $shipmentItem = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderItem', 'getQty'])
            ->getMock();
        $shipmentItem->method('getOrderItem')->willReturn($orderItem);
        $shipmentItem->method('getQty')->willReturn(2.0);

        $this->assertSame($shipmentItem, $shipmentItem->cancel());
    }

    public function testCancelExactZero(): void
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->expects($this->once())->method('getQtyShipped')->willReturn(2.0);
        $orderItem->expects($this->once())->method('setQtyShipped')->with(0.0)->willReturnSelf();

        $shipmentItem = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderItem', 'getQty'])
            ->getMock();
        $shipmentItem->method('getOrderItem')->willReturn($orderItem);
        $shipmentItem->method('getQty')->willReturn(2.0);

        $shipmentItem->cancel();
    }

    public function testCancelThrowsWhenQtyInconsistent(): void
    {
        $this->expectException(LocalizedException::class);

        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->expects($this->once())->method('getQtyShipped')->willReturn(1.0);
        $orderItem->expects($this->never())->method('setQtyShipped');

        $shipmentItem = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderItem', 'getQty'])
            ->getMock();
        $shipmentItem->method('getOrderItem')->willReturn($orderItem);
        $shipmentItem->method('getQty')->willReturn(5.0);

        $shipmentItem->cancel();
    }
}
