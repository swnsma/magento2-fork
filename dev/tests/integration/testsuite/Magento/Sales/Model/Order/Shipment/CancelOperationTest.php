<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Shipment;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentManagementInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for full shipment cancellation.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class CancelOperationTest extends TestCase
{
    private const ORDER_INCREMENT_ID = '100000001';
    private const CARRIER_CANCEL_PATH = 'carriers/flatrate/allow_cancel_shipment';

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var CancelOperation
     */
    private $cancelOperation;

    /**
     * @var CanCancel
     */
    private $canCancel;

    /**
     * @var ShipmentRepositoryInterface
     */
    private $shipmentRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var ShipmentFactory
     */
    private $shipmentFactory;

    /**
     * @var MutableScopeConfigInterface
     */
    private $mutableConfig;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->cancelOperation = $this->objectManager->get(CancelOperation::class);
        $this->canCancel = $this->objectManager->get(CanCancel::class);
        $this->shipmentRepository = $this->objectManager->get(ShipmentRepositoryInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->shipmentFactory = $this->objectManager->get(ShipmentFactory::class);
        $this->mutableConfig = $this->objectManager->get(MutableScopeConfigInterface::class);
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->setCarrierCancelConfig('0');
        parent::tearDown();
    }

    /**
     * Cancel shipment reverses qty_shipped, marks canceled, and allows a new shipment.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCancelShipmentSuccessfullyAndAllowReship(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);

        foreach ($order->getAllItems() as $item) {
            $this->assertEquals((float)$item->getQtyOrdered(), (float)$item->getQtyShipped());
        }
        $this->assertTrue($this->canCancel->execute($shipment));

        $this->cancelOperation->execute($shipment);

        $reloadedShipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $this->assertEquals(Shipment::STATUS_CANCELED, (int)$reloadedShipment->getShipmentStatus());
        $this->assertTrue($reloadedShipment->isCanceled());
        $this->assertFalse($this->canCancel->execute($reloadedShipment));

        $reloadedOrder = $this->orderRepository->get((int)$order->getEntityId());
        $this->assertEquals(Order::STATE_PROCESSING, $reloadedOrder->getState());
        foreach ($reloadedOrder->getAllItems() as $item) {
            $this->assertEquals(0.0, (float)$item->getQtyShipped());
        }
        $this->assertTrue($reloadedOrder->canShip());
        // Canceled shipment documents must not block order-level "has shipments" checks.
        $this->assertFalse($reloadedOrder->hasShipments());

        $historyComments = array_map(
            static function ($history) {
                return (string)$history->getComment();
            },
            $reloadedOrder->getStatusHistories() ?: []
        );
        $this->assertNotEmpty(
            array_filter(
                $historyComments,
                static function (string $comment) use ($reloadedShipment) {
                    return str_contains($comment, (string)$reloadedShipment->getIncrementId())
                        && str_contains(strtolower($comment), 'canceled');
                }
            )
        );

        $secondShipment = $this->createAndRegisterShipment($reloadedOrder);
        $this->assertNotEquals($reloadedShipment->getEntityId(), $secondShipment->getEntityId());
        $this->assertSame(Shipment::STATUS_NEW, (int)$secondShipment->getShipmentStatus());
        $this->assertFalse($secondShipment->isCanceled());
        $this->assertSame((int)$order->getEntityId(), (int)$secondShipment->getOrderId());

        $orderAfterReship = $this->orderRepository->get((int)$order->getEntityId());
        foreach ($orderAfterReship->getAllItems() as $item) {
            $this->assertEquals((float)$item->getQtyOrdered(), (float)$item->getQtyShipped());
        }
    }

    /**
     * hasShipments() ignores canceled documents and returns true for active ones.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testHasShipmentsIgnoresCanceledDocuments(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);

        $order = $this->orderRepository->get((int)$order->getEntityId());
        $this->assertTrue($order->hasShipments());

        $this->cancelOperation->execute($shipment);

        $canceledShipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $this->assertTrue($canceledShipment->isCanceled());
        // Bypass OrderRepository identity map which can retain a stale order instance.
        $order = $this->objectManager->create(Order::class)->load((int)$order->getEntityId());
        $this->assertFalse($order->hasShipments());

        $this->createAndRegisterShipment($order);
        $order = $this->objectManager->create(Order::class)->load((int)$order->getEntityId());
        $this->assertTrue($order->hasShipments());
    }

    /**
     * Cancel is not allowed when carrier config is disabled.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCannotCancelWhenCarrierConfigDisabled(): void
    {
        $this->setCarrierCancelConfig('0');
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);
        $qtyBefore = $this->captureShippedQtys($order);

        $this->assertFalse($this->canCancel->execute($shipment));

        try {
            $this->cancelOperation->execute($shipment);
            $this->fail('Expected LocalizedException was not thrown.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cannot be canceled', (string)$e->getMessage());
        }

        $this->assertShipmentUnchanged((int)$shipment->getEntityId(), $qtyBefore);
    }

    /**
     * Cancel is not allowed when the order is complete.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCannotCancelWhenOrderIsComplete(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);
        $this->invoiceOrder($order);

        $order = $this->orderRepository->get((int)$order->getEntityId());
        $this->assertEquals(Order::STATE_COMPLETE, $order->getState());

        $shipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $qtyBefore = $this->captureShippedQtys($order);
        $this->assertFalse($this->canCancel->execute($shipment));

        try {
            $this->cancelOperation->execute($shipment);
            $this->fail('Expected LocalizedException was not thrown.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cannot be canceled', (string)$e->getMessage());
        }

        $this->assertShipmentUnchanged((int)$shipment->getEntityId(), $qtyBefore);
    }

    /**
     * A canceled shipment cannot be canceled again.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCannotCancelAlreadyCanceledShipment(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);
        $this->cancelOperation->execute($shipment);

        $shipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $this->assertTrue($shipment->isCanceled());
        $order = $this->orderRepository->get((int)$order->getEntityId());
        $qtyBefore = $this->captureShippedQtys($order);

        try {
            $this->cancelOperation->execute($shipment);
            $this->fail('Expected LocalizedException was not thrown.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cannot be canceled', (string)$e->getMessage());
        }

        $this->assertShipmentUnchanged((int)$shipment->getEntityId(), $qtyBefore, true);
    }

    /**
     * Missing shipping method prevents cancel (carrier config cannot be resolved).
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCannotCancelWithoutShippingMethod(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);

        // Clear shipping method after ship so carrier resolution fails for cancel eligibility.
        $order = $this->orderRepository->get((int)$order->getEntityId());
        $order->setShippingMethod(null);
        $this->objectManager->get(OrderResource::class)->save($order);

        $shipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        // CanCancel resolves order via shipment->getOrder() which reloads from DB without method.
        $order = $this->orderRepository->get((int)$order->getEntityId());
        $shipment->setOrder($order);
        $this->assertFalse($this->canCancel->execute($shipment));
    }

    /**
     * Holded orders cannot cancel shipment.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCannotCancelWhenOrderIsHolded(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);

        $order = $this->orderRepository->get((int)$order->getEntityId());
        $order->setState(Order::STATE_HOLDED)
            ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_HOLDED));
        $this->objectManager->get(OrderResource::class)->save($order);

        $shipment = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $qtyBefore = $this->captureShippedQtys($this->orderRepository->get((int)$order->getEntityId()));
        $this->assertFalse($this->canCancel->execute($shipment));

        try {
            $this->cancelOperation->execute($shipment);
            $this->fail('Expected LocalizedException was not thrown.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cannot be canceled', (string)$e->getMessage());
        }
        $this->assertShipmentUnchanged((int)$shipment->getEntityId(), $qtyBefore);
    }

    /**
     * REST/service cancel path.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testShipmentManagementCancel(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();
        $shipment = $this->createAndRegisterShipment($order);

        /** @var ShipmentManagementInterface $management */
        $management = $this->objectManager->get(ShipmentManagementInterface::class);
        $this->assertTrue($management->cancel((int)$shipment->getEntityId()));

        $reloaded = $this->shipmentRepository->get((int)$shipment->getEntityId());
        $this->assertTrue($reloaded->isCanceled());
        $this->assertFalse(
            $this->orderRepository->get((int)$order->getEntityId())->hasShipments()
        );
    }

    /**
     * Canceling one of two shipments only reverses that shipment's qty.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCancelOneOfTwoShipments(): void
    {
        $this->enableCarrierCancel();
        $order = $this->prepareOrderWithShippingMethod();

        $firstItems = [];
        $secondItems = [];
        foreach ($order->getAllItems() as $item) {
            $half = (float)$item->getQtyOrdered() / 2;
            $firstItems[$item->getId()] = $half;
            $secondItems[$item->getId()] = $half;
        }

        $firstShipment = $this->createAndRegisterShipment($order, $firstItems);
        $order = $this->orderRepository->get((int)$order->getEntityId());
        $secondShipment = $this->createAndRegisterShipment($order, $secondItems);

        $this->cancelOperation->execute($firstShipment);

        $firstShipment = $this->shipmentRepository->get((int)$firstShipment->getEntityId());
        $secondShipment = $this->shipmentRepository->get((int)$secondShipment->getEntityId());
        $order = $this->orderRepository->get((int)$order->getEntityId());

        $this->assertTrue($firstShipment->isCanceled());
        $this->assertFalse($secondShipment->isCanceled());
        foreach ($order->getAllItems() as $item) {
            $this->assertEquals((float)$item->getQtyOrdered() / 2, (float)$item->getQtyShipped());
        }
    }

    /**
     * Enable cancel shipment for flatrate carrier.
     *
     * @return void
     */
    private function enableCarrierCancel(): void
    {
        $this->setCarrierCancelConfig('1');
    }

    /**
     * Set allow_cancel_shipment at default and store scopes (store overrides default).
     *
     * @param string $value
     * @return void
     */
    private function setCarrierCancelConfig(string $value): void
    {
        $this->mutableConfig->setValue(
            self::CARRIER_CANCEL_PATH,
            $value,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $this->mutableConfig->setValue(
            self::CARRIER_CANCEL_PATH,
            $value,
            ScopeInterface::SCOPE_STORE,
            'default'
        );
    }

    /**
     * Load fixture order and set flatrate shipping method.
     *
     * @return Order
     */
    private function prepareOrderWithShippingMethod(): Order
    {
        $order = $this->getOrder(self::ORDER_INCREMENT_ID);
        $order->setShippingMethod('flatrate_flatrate');
        // Resource save keeps shipping_method reliably on the sales_order row.
        $this->objectManager->get(OrderResource::class)->save($order);

        $reloaded = $this->orderRepository->get((int)$order->getEntityId());
        $this->assertSame(
            'flatrate_flatrate',
            (string)$reloaded->getShippingMethod(),
            'Order shipping method must be persisted for carrier cancel config lookup.'
        );

        return $reloaded;
    }

    /**
     * Create, register and persist a shipment for the order.
     *
     * @param Order $order
     * @param array|null $itemsMap
     * @return Shipment
     */
    private function createAndRegisterShipment(Order $order, ?array $itemsMap = null): Shipment
    {
        if ($itemsMap === null) {
            $itemsMap = [];
            foreach ($order->getAllItems() as $item) {
                $qty = (float)$item->getQtyToShip();
                if ($qty > 0) {
                    $itemsMap[$item->getId()] = $qty;
                }
            }
        }

        // Keep carrier code available after transaction save / order reload.
        if (!$order->getShippingMethod()) {
            $order->setShippingMethod('flatrate_flatrate');
        }

        /** @var Shipment $shipment */
        $shipment = $this->shipmentFactory->create($order, $itemsMap);
        $shipment->register();
        $order->setIsInProcess(true);

        $this->objectManager->create(Transaction::class)
            ->addObject($shipment)
            ->addObject($order)
            ->save();

        return $this->shipmentRepository->get((int)$shipment->getEntityId());
    }

    /**
     * Invoice entire order (pushes fully shipped order to complete when combined with shipment).
     *
     * @param Order $order
     * @return void
     */
    private function invoiceOrder(Order $order): void
    {
        $invoice = $order->prepareInvoice();
        $invoice->register();
        $order->setIsInProcess(true);

        $this->objectManager->create(Transaction::class)
            ->addObject($invoice)
            ->addObject($order)
            ->save();
    }

    /**
     * @param string $incrementId
     * @return Order
     */
    private function getOrder(string $incrementId): Order
    {
        /** @var SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $searchCriteria = $searchCriteriaBuilder->addFilter(OrderInterface::INCREMENT_ID, $incrementId)->create();
        $items = $this->orderRepository->getList($searchCriteria)->getItems();
        $this->assertNotEmpty($items, 'Order fixture was not loaded.');

        /** @var Order $order */
        $order = array_pop($items);
        return $order;
    }

    /**
     * @param Order $order
     * @return array<int, float>
     */
    private function captureShippedQtys(Order $order): array
    {
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            $qtys[(int)$item->getItemId()] = (float)$item->getQtyShipped();
        }
        return $qtys;
    }

    /**
     * @param int $shipmentId
     * @param array<int, float> $expectedQtys
     * @param bool $expectCanceled
     * @return void
     */
    private function assertShipmentUnchanged(
        int $shipmentId,
        array $expectedQtys,
        bool $expectCanceled = false
    ): void {
        $shipment = $this->shipmentRepository->get($shipmentId);
        if ($expectCanceled) {
            $this->assertTrue($shipment->isCanceled());
        } else {
            $this->assertFalse($shipment->isCanceled());
        }
        $order = $this->orderRepository->get((int)$shipment->getOrderId());
        foreach ($order->getAllItems() as $item) {
            $itemId = (int)$item->getItemId();
            if (array_key_exists($itemId, $expectedQtys)) {
                $this->assertEquals(
                    $expectedQtys[$itemId],
                    (float)$item->getQtyShipped(),
                    'qty_shipped changed after a failed cancel attempt.'
                );
            }
        }
    }
}
