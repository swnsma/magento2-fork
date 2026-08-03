<?php
/**
 * Copyright 2011 Adobe
 * All Rights Reserved.
 */
namespace Magento\Shipping\Block\Adminhtml;

use Magento\Sales\Model\Order\Shipment\CanCancel;

/**
 * Adminhtml shipment create
 *
 * @api
 * @since 100.0.2
 */
class View extends \Magento\Backend\Block\Widget\Form\Container
{
    /**
     * Application data storage
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * @var CanCancel
     */
    private $canCancel;

    /**
     * @param \Magento\Backend\Block\Widget\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param array $data
     * @param CanCancel|null $canCancel
     */
    public function __construct(
        \Magento\Backend\Block\Widget\Context $context,
        \Magento\Framework\Registry $registry,
        array $data = [],
        ?CanCancel $canCancel = null
    ) {
        $this->_coreRegistry = $registry;
        $this->canCancel = $canCancel
            ?: \Magento\Framework\App\ObjectManager::getInstance()->get(CanCancel::class);
        parent::__construct($context, $data);
    }

    /**
     * Initialize the block and configure buttons
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_objectId = 'shipment_id';
        $this->_mode = 'view';

        parent::_construct();

        $this->buttonList->remove('reset');
        $this->buttonList->remove('delete');
        if (!$this->getShipment()) {
            return;
        }

        if ($this->_authorization->isAllowed('Magento_Sales::emails')) {
            $this->buttonList->update('save', 'label', __('Send Tracking Information'));
            $confirmMessage = $this->escapeJs(
                $this->escapeHtml(__('Are you sure you want to send a Shipment email to customer?'))
            );
            $this->buttonList->update(
                'save',
                'onclick',
                "deleteConfirm('" . $confirmMessage . "', '" . $this->getEmailUrl() . "')"
            );
        }

        if ($this->getShipment()->getId()) {
            $this->buttonList->add(
                'print',
                [
                    'label' => __('Print'),
                    'class' => 'save',
                    'onclick' => 'setLocation(\'' . $this->getPrintUrl() . '\')'
                ]
            );
        }

        $this->addCancelButton();
    }

    /**
     * Add cancel shipment button when allowed.
     *
     * @return void
     */
    private function addCancelButton(): void
    {
        if (!$this->_authorization->isAllowed('Magento_Sales::shipment')) {
            return;
        }
        if (!$this->canCancel->execute($this->getShipment())) {
            return;
        }

        $confirmMessage = $this->escapeJs(
            $this->escapeHtml(__('Are you sure you want to cancel this shipment?'))
        );
        // POST with form_key via mage/dataPost (third arg) for CSRF hardening.
        $this->buttonList->add(
            'cancel_shipment',
            [
                'label' => __('Cancel Shipment'),
                'class' => 'delete',
                'onclick' => "deleteConfirm('"
                    . $confirmMessage
                    . "', '"
                    . $this->getCancelUrl()
                    . "', {data: {}})"
            ]
        );
    }

    /**
     * Get URL for canceling the shipment.
     *
     * @return string
     */
    public function getCancelUrl(): string
    {
        return $this->getUrl(
            'adminhtml/order_shipment/cancel',
            ['shipment_id' => $this->getShipment()->getId()]
        );
    }

    /**
     * Retrieve shipment model instance
     *
     * @return \Magento\Sales\Model\Order\Shipment
     */
    public function getShipment()
    {
        return $this->_coreRegistry->registry('current_shipment');
    }

    /**
     * Get page header text with shipment information
     *
     * @return \Magento\Framework\Phrase
     */
    public function getHeaderText()
    {
        if ($this->getShipment()->getEmailSent()) {
            $emailSent = __('the shipment email was sent');
        } else {
            $emailSent = __('the shipment email is not sent');
        }
        return __(
            'Shipment #%1 | %3 (%2)',
            $this->getShipment()->getIncrementId(),
            $emailSent,
            $this->formatDate(
                $this->_localeDate->date(new \DateTime($this->getShipment()->getCreatedAt())),
                \IntlDateFormatter::MEDIUM,
                true
            )
        );
    }

    /**
     * Get URL for back button navigation
     *
     * @return string
     */
    public function getBackUrl()
    {
        return $this->getUrl(
            'sales/order/view',
            [
                'order_id' => $this->getShipment() ? $this->getShipment()->getOrderId() : null,
                'active_tab' => 'order_shipments'
            ]
        );
    }

    /**
     * Get URL for sending shipment email
     *
     * @return string
     */
    public function getEmailUrl()
    {
        return $this->getUrl('adminhtml/order_shipment/email', ['shipment_id' => $this->getShipment()->getId()]);
    }

    /**
     * Get URL for printing shipment
     *
     * @return string
     */
    public function getPrintUrl()
    {
        return $this->getUrl('sales/shipment/print', ['shipment_id' => $this->getShipment()->getId()]);
    }

    /**
     * Update back button URL configuration
     *
     * @param bool $flag
     * @return $this
     */
    public function updateBackButtonUrl($flag)
    {
        if ($flag) {
            if ($this->getShipment()->getBackUrl()) {
                return $this->buttonList->update(
                    'back',
                    'onclick',
                    'setLocation(\'' . $this->getShipment()->getBackUrl() . '\')'
                );
            }
            return $this->buttonList->update(
                'back',
                'onclick',
                'setLocation(\'' . $this->getUrl('sales/shipment/') . '\')'
            );
        }
        return $this;
    }
}
