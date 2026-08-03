<?php
/**
 * Copyright 2014 Adobe
 * All Rights Reserved.
 */
namespace Magento\Sales\Model\Service;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\ShipmentManagementInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\CancelOperation;

/**
 * Class ShipmentService
 */
class ShipmentService implements ShipmentManagementInterface
{
    /**
     * Repository
     *
     * @var \Magento\Sales\Api\ShipmentCommentRepositoryInterface
     */
    protected $commentRepository;

    /**
     * Search Criteria Builder
     *
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    protected $criteriaBuilder;

    /**
     * Filter Builder
     *
     * @var \Magento\Framework\Api\FilterBuilder
     */
    protected $filterBuilder;

    /**
     * Repository
     *
     * @var \Magento\Sales\Api\ShipmentRepositoryInterface
     */
    protected $repository;

    /**
     * Shipment Notifier
     *
     * @var \Magento\Shipping\Model\ShipmentNotifier
     */
    protected $notifier;

    /**
     * @var CancelOperation
     */
    private $cancelOperation;

    /**
     * Constructor
     *
     * @param \Magento\Sales\Api\ShipmentCommentRepositoryInterface $commentRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder
     * @param \Magento\Framework\Api\FilterBuilder $filterBuilder
     * @param \Magento\Sales\Api\ShipmentRepositoryInterface $repository
     * @param \Magento\Shipping\Model\ShipmentNotifier $notifier
     * @param CancelOperation|null $cancelOperation
     */
    public function __construct(
        \Magento\Sales\Api\ShipmentCommentRepositoryInterface $commentRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $criteriaBuilder,
        \Magento\Framework\Api\FilterBuilder $filterBuilder,
        \Magento\Sales\Api\ShipmentRepositoryInterface $repository,
        \Magento\Shipping\Model\ShipmentNotifier $notifier,
        ?CancelOperation $cancelOperation = null
    ) {
        $this->commentRepository = $commentRepository;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->filterBuilder = $filterBuilder;
        $this->repository = $repository;
        $this->notifier = $notifier;
        $this->cancelOperation = $cancelOperation
            ?: ObjectManager::getInstance()->get(CancelOperation::class);
    }

    /**
     * Returns shipment label
     *
     * @param int $id
     * @return string
     */
    public function getLabel($id)
    {
        return (string)$this->repository->get($id)->getShippingLabel();
    }

    /**
     * Returns list of comments attached to shipment
     * @param int $id
     * @return \Magento\Sales\Api\Data\ShipmentCommentSearchResultInterface
     */
    public function getCommentsList($id)
    {
        $this->criteriaBuilder->addFilters(
            [$this->filterBuilder->setField('parent_id')->setValue($id)->setConditionType('eq')->create()]
        );
        $searchCriteria = $this->criteriaBuilder->create();
        return $this->commentRepository->getList($searchCriteria);
    }

    /**
     * Notify user
     *
     * @param int $id
     * @return bool
     */
    public function notify($id)
    {
        $shipment = $this->repository->get($id);
        return $this->notifier->notify($shipment);
    }

    /**
     * @inheritdoc
     */
    public function cancel($id)
    {
        $shipment = $this->repository->get($id);
        if (!$shipment instanceof Shipment) {
            throw new LocalizedException(__('This shipment cannot be canceled.'));
        }
        $this->cancelOperation->execute($shipment);
        return true;
    }
}
