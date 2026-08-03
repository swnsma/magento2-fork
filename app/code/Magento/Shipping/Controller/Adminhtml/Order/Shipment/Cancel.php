<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Shipping\Controller\Adminhtml\Order\Shipment;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Shipment\CancelOperation;
use Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader;
use Psr\Log\LoggerInterface;

/**
 * Admin cancel shipment action (full shipment only, POST with form key).
 */
class Cancel extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::shipment';

    /**
     * @var ShipmentLoader
     */
    private $shipmentLoader;

    /**
     * @var CancelOperation
     */
    private $cancelOperation;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Action\Context $context
     * @param ShipmentLoader $shipmentLoader
     * @param CancelOperation $cancelOperation
     * @param LoggerInterface $logger
     */
    public function __construct(
        Action\Context $context,
        ShipmentLoader $shipmentLoader,
        CancelOperation $cancelOperation,
        LoggerInterface $logger
    ) {
        $this->shipmentLoader = $shipmentLoader;
        $this->cancelOperation = $cancelOperation;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Cancel shipment
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $shipmentId = (int)$this->getRequest()->getParam('shipment_id');
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        try {
            $this->shipmentLoader->setOrderId($this->getRequest()->getParam('order_id'));
            $this->shipmentLoader->setShipmentId($shipmentId);
            $this->shipmentLoader->setShipment($this->getRequest()->getParam('shipment'));
            $this->shipmentLoader->setTracking($this->getRequest()->getParam('tracking'));
            $shipment = $this->shipmentLoader->load();

            if (!$shipment) {
                $this->messageManager->addErrorMessage(__('This shipment no longer exists.'));
                return $resultRedirect->setPath('sales/shipment/');
            }

            $this->cancelOperation->execute($shipment);
            $this->messageManager->addSuccessMessage(__('You canceled the shipment.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(__('Shipment canceling error'));
        }

        return $resultRedirect->setPath('adminhtml/order_shipment/view', ['shipment_id' => $shipmentId]);
    }
}
