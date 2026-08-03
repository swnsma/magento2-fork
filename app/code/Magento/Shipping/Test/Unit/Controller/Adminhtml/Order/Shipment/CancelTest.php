<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Shipping\Test\Unit\Controller\Adminhtml\Order\Shipment;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect as RedirectResult;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\CancelOperation;
use Magento\Shipping\Controller\Adminhtml\Order\Shipment\Cancel;
use Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for admin shipment cancel controller.
 */
class CancelTest extends TestCase
{
    use MockCreationTrait;

    /**
     * @var Cancel
     */
    private $controller;

    /**
     * @var ShipmentLoader|MockObject
     */
    private $shipmentLoader;

    /**
     * @var CancelOperation|MockObject
     */
    private $cancelOperation;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var RequestInterface|MockObject
     */
    private $request;

    /**
     * @var ManagerInterface|MockObject
     */
    private $messageManager;

    /**
     * @var RedirectResult|MockObject
     */
    private $resultRedirect;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->shipmentLoader = $this->createPartialMockWithReflection(
            ShipmentLoader::class,
            ['setOrderId', 'setShipmentId', 'setShipment', 'setTracking', 'load']
        );
        $this->cancelOperation = $this->createMock(CancelOperation::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->resultRedirect = $this->createMock(RedirectResult::class);
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_REDIRECT)->willReturn($this->resultRedirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $this->controller = $objectManagerHelper->getObject(
            Cancel::class,
            [
                'context' => $context,
                'shipmentLoader' => $this->shipmentLoader,
                'cancelOperation' => $this->cancelOperation,
                'logger' => $this->logger,
            ]
        );
    }

    public function testExecuteSuccess(): void
    {
        $shipment = $this->createMock(Shipment::class);
        $this->stubRequestParams(10, 5);
        $this->shipmentLoader->method('load')->willReturn($shipment);
        $this->cancelOperation->expects($this->once())->method('execute')->with($shipment);
        $this->messageManager->expects($this->once())->method('addSuccessMessage');
        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('adminhtml/order_shipment/view', ['shipment_id' => 10])
            ->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->controller->execute());
    }

    public function testExecuteMissingShipment(): void
    {
        $this->stubRequestParams(10, 5);
        $this->shipmentLoader->method('load')->willReturn(false);
        $this->cancelOperation->expects($this->never())->method('execute');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('sales/shipment/')
            ->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->controller->execute());
    }

    public function testExecuteLocalizedException(): void
    {
        $shipment = $this->createMock(Shipment::class);
        $this->stubRequestParams(10, 5);
        $this->shipmentLoader->method('load')->willReturn($shipment);
        $this->cancelOperation->method('execute')
            ->willThrowException(new LocalizedException(__('This shipment cannot be canceled.')));
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('adminhtml/order_shipment/view', ['shipment_id' => 10])
            ->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->controller->execute());
    }

    public function testExecuteGenericException(): void
    {
        $shipment = $this->createMock(Shipment::class);
        $this->stubRequestParams(10, 5);
        $this->shipmentLoader->method('load')->willReturn($shipment);
        $this->cancelOperation->method('execute')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('critical');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('adminhtml/order_shipment/view', ['shipment_id' => 10])
            ->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->controller->execute());
    }

    /**
     * @param int $shipmentId
     * @param int $orderId
     * @return void
     */
    private function stubRequestParams(int $shipmentId, int $orderId): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['shipment_id', null, $shipmentId],
                ['order_id', null, $orderId],
                ['shipment', null, null],
                ['tracking', null, null],
            ]
        );
        $this->shipmentLoader->expects($this->once())->method('setOrderId')->with($orderId)->willReturnSelf();
        $this->shipmentLoader->expects($this->once())->method('setShipmentId')->with($shipmentId)->willReturnSelf();
        $this->shipmentLoader->expects($this->once())->method('setShipment')->willReturnSelf();
        $this->shipmentLoader->expects($this->once())->method('setTracking')->willReturnSelf();
    }
}
