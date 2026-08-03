<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Model\Order\Shipment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\CanCancel;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for shipment cancel eligibility.
 */
class CanCancelTest extends TestCase
{
    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfig;

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var CanCancel
     */
    private $canCancel;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->canCancel = new CanCancel($this->scopeConfig, $this->orderRepository);
    }

    public function testCannotCancelWithoutEntityId(): void
    {
        $order = $this->createProcessingOrder();
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getEntityId')->willReturn(null);
        $shipment->method('getShipmentStatus')->willReturn(Shipment::STATUS_NEW);
        $shipment->method('getOrder')->willReturn($order);

        $this->scopeConfig->expects($this->never())->method('isSetFlag');

        $this->assertFalse($this->canCancel->execute($shipment));
    }

    public function testCannotCancelWhenAlreadyCanceled(): void
    {
        $order = $this->createProcessingOrder();
        $shipment = $this->createConfiguredShipment(Shipment::STATUS_CANCELED, $order);

        // Config would allow cancel; status guard must still block.
        $this->scopeConfig->expects($this->never())->method('isSetFlag');

        $this->assertFalse($this->canCancel->execute($shipment));
    }

    /**
     * @param string $state
     */
    #[DataProvider('blockedOrderStatesDataProvider')]
    public function testCannotCancelWhenOrderStateBlocked(string $state): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturnCallback(
            static function ($asObject = false) {
                if ($asObject) {
                    return new DataObject(['carrier_code' => 'flatrate', 'method' => 'flatrate']);
                }
                return 'flatrate_flatrate';
            }
        );

        $shipment = $this->createConfiguredShipment(Shipment::STATUS_NEW, $order);
        $this->scopeConfig->expects($this->never())->method('isSetFlag');

        $this->assertFalse($this->canCancel->execute($shipment));
    }

    public static function blockedOrderStatesDataProvider(): array
    {
        return [
            'complete' => [Order::STATE_COMPLETE],
            'closed' => [Order::STATE_CLOSED],
            'canceled' => [Order::STATE_CANCELED],
            'holded' => [Order::STATE_HOLDED],
            'payment_review' => [Order::STATE_PAYMENT_REVIEW],
        ];
    }

    public function testCannotCancelWhenCarrierDisallows(): void
    {
        $order = $this->createProcessingOrder();
        $shipment = $this->createConfiguredShipment(Shipment::STATUS_NEW, $order);
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(
                'carriers/flatrate/allow_cancel_shipment',
                ScopeInterface::SCOPE_STORE,
                1
            )
            ->willReturn(false);

        $this->assertFalse($this->canCancel->execute($shipment));
    }

    public function testCanCancelWhenCarrierAllowsAndOrderProcessing(): void
    {
        $order = $this->createProcessingOrder();
        $shipment = $this->createConfiguredShipment(Shipment::STATUS_NEW, $order);
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(
                'carriers/flatrate/allow_cancel_shipment',
                ScopeInterface::SCOPE_STORE,
                1
            )
            ->willReturn(true);

        $this->assertTrue($this->canCancel->execute($shipment));
    }

    public function testCannotCancelWhenShippingMethodMissing(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturn(null);

        $shipment = $this->createConfiguredShipment(Shipment::STATUS_NEW, $order);
        $this->scopeConfig->expects($this->never())->method('isSetFlag');

        $this->assertFalse($this->canCancel->execute($shipment));
    }

    public function testCarrierCodeFallsBackToStringParse(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturnCallback(
            static function ($asObject = false) {
                if ($asObject) {
                    return new DataObject(['carrier_code' => '', 'method' => '']);
                }
                return 'flatrate_flatrate';
            }
        );

        $shipment = $this->createConfiguredShipment(Shipment::STATUS_NEW, $order);
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('carriers/flatrate/allow_cancel_shipment', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);

        $this->assertTrue($this->canCancel->execute($shipment));
    }

    /**
     * @return Order|MockObject
     */
    private function createProcessingOrder(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturnCallback(
            static function ($asObject = false) {
                if ($asObject) {
                    return new DataObject(['carrier_code' => 'flatrate', 'method' => 'flatrate']);
                }
                return 'flatrate_flatrate';
            }
        );
        return $order;
    }

    /**
     * @param int $shipmentStatus
     * @param Order $order
     * @return Shipment|MockObject
     */
    private function createConfiguredShipment(int $shipmentStatus, Order $order): Shipment
    {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getEntityId')->willReturn(10);
        $shipment->method('getShipmentStatus')->willReturn($shipmentStatus);
        $shipment->method('getOrder')->willReturn($order);
        return $shipment;
    }
}
