<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Shipment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Store\Model\ScopeInterface;

/**
 * Determines whether a full shipment cancellation is allowed.
 */
class CanCancel
{
    /**
     * Carrier configuration path for allowing shipment cancellation.
     */
    public const XML_PATH_CARRIER_ALLOW_CANCEL = 'carriers/%s/allow_cancel_shipment';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Check if the shipment can be canceled.
     *
     * Full shipment cancel only. Not allowed when the order is complete (or closed/canceled),
     * when the shipment is already canceled, or when the order's carrier does not allow cancel.
     *
     * @param ShipmentInterface $shipment
     * @return bool
     */
    public function execute(ShipmentInterface $shipment): bool
    {
        if (!$shipment->getEntityId()) {
            return false;
        }

        if ((int)$shipment->getShipmentStatus() === Shipment::STATUS_CANCELED) {
            return false;
        }

        $order = $this->resolveOrder($shipment);
        if ($order === null) {
            return false;
        }

        $blockedStates = [
            Order::STATE_COMPLETE,
            Order::STATE_CLOSED,
            Order::STATE_CANCELED,
            Order::STATE_HOLDED,
            Order::STATE_PAYMENT_REVIEW,
        ];
        if (in_array($order->getState(), $blockedStates, true)) {
            return false;
        }

        $carrierCode = $this->resolveCarrierCode($order);
        if ($carrierCode === '') {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            sprintf(self::XML_PATH_CARRIER_ALLOW_CANCEL, $carrierCode),
            ScopeInterface::SCOPE_STORE,
            $order->getStoreId()
        );
    }

    /**
     * Resolve order model for the shipment.
     *
     * @param ShipmentInterface $shipment
     * @return Order|null
     */
    private function resolveOrder(ShipmentInterface $shipment): ?Order
    {
        if ($shipment instanceof Shipment) {
            $order = $shipment->getOrder();
            return $order instanceof Order ? $order : null;
        }

        $orderId = (int)$shipment->getOrderId();
        if ($orderId <= 0) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        return $order instanceof Order ? $order : null;
    }

    /**
     * Resolve carrier code from the order shipping method.
     *
     * @param Order $order
     * @return string
     */
    private function resolveCarrierCode(Order $order): string
    {
        $method = $order->getShippingMethod(true);
        if (is_object($method)) {
            $carrierCode = (string)$method->getData('carrier_code');
            if ($carrierCode !== '') {
                return $carrierCode;
            }
        }

        $shippingMethod = (string)$order->getShippingMethod();
        if ($shippingMethod === '') {
            return '';
        }

        $parts = explode('_', $shippingMethod, 2);
        return $parts[0] ?? '';
    }
}
