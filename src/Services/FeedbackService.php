<?php

namespace Feedback\Services;

use Plenty\Plugin\Http\Request;
use Feedback\Helpers\FeedbackCoreHelper;
use IO\Services\SessionStorageService;
use Plenty\Modules\Feedback\Contracts\FeedbackAverageRepositoryContract;
use Plenty\Modules\Feedback\Contracts\FeedbackRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Item\Attribute\Contracts\AttributeNameRepositoryContract;
use Plenty\Modules\Item\Attribute\Contracts\AttributeValueNameRepositoryContract;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Item\Variation\Contracts\VariationRepositoryContract;

class FeedbackService
{
    /** @var Request $request */
    private $request;
    /** @var FeedbackCoreHelper $coreHelper */
    private $coreHelper;
    /** @var FeedbackRepositoryContract $feedbackRepository */
    private $feedbackRepository;
    /** @var FeedbackAverageRepositoryContract $feedbackAverageRepository */
    private $feedbackAverageRepository;
    /** @var AccountService $accountService */
    private $accountService;
    /** @var SessionStorageService $sessionStorage */
    private $sessionStorage;

    public function __construct(
        Request $request,
        FeedbackCoreHelper $coreHelper,
        FeedbackRepositoryContract $feedbackRepository,
        FeedbackAverageRepositoryContract $feedbackAverageRepository,
        AccountService $accountService,
        SessionStorageService $sessionStorage
    ) {
        $this->request = $request;
        $this->coreHelper = $coreHelper;
        $this->feedbackRepository = $feedbackRepository;
        $this->feedbackAverageRepository = $feedbackAverageRepository;
        $this->accountService = $accountService;
        $this->sessionStorage = $sessionStorage;
    }

    /**
     * Delivers data for the feedback-container Vue components props
     * @param $item
     * @return array
     */
    public function getFeedbackData($item)
    {
        $itemId = $item['documents'][0]['data']['item']['id'];
        $average = $this->feedbackAverageRepository->getFeedbackAverage($itemId);

        if (empty($average)) {
            // Default values if the average table doesn't have any entry for this item/variation
            $counts = [
                'ratingsCountOf1' => 0,
                'ratingsCountOf2' => 0,
                'ratingsCountOf3' => 0,
                'ratingsCountOf4' => 0,
                'ratingsCountOf5' => 0,
                'ratingsCountTotal' => 0,
                'averageValue' => 0,
                'highestCount' => 0
            ];
        } else {
            $counts = [
                'ratingsCountOf1' => $average->ratingsCountOf1,
                'ratingsCountOf2' => $average->ratingsCountOf2,
                'ratingsCountOf3' => $average->ratingsCountOf3,
                'ratingsCountOf4' => $average->ratingsCountOf4,
                'ratingsCountOf5' => $average->ratingsCountOf5
            ];

            $highestCount = max($counts);
            $counts['ratingsCountTotal'] = $average->ratingsCountTotal;
            $counts['averageValue'] = $average->averageValue;
            $counts['highestCount'] = $highestCount;
        }

        $data['counts'] = $counts;
        $data['item'] = $item;

        return $data;
    }

    /**
     * @return array
     */
    public function getFeedbackAverageDataSingleItem($item) {
        $itemId = $item['documents'][0]['data']['item']['id'];

        if ((int)$itemId > 0) {
            $average = $this->feedbackAverageRepository->getFeedbackAverage($itemId);
        }

        if( empty($average)) {
            $counts['averageValue'] = 0;
        } else {
            $counts['averageValue'] = $average->averageValue;
        }

        $data['counts'] = $counts;

        $showEmptyRatingsInCategoryView = $this->coreHelper->configValueAsBool(FeedbackCoreHelper::KEY_SHOW_EMPTY_RATINGS_IN_CATEGORY_VIEW);
        $data['options']['showEmptyRatingsInCategoryView'] = $showEmptyRatingsInCategoryView;

        return $data;
    }

    /**
     * Create a feedback entry in the db
     * @return string
     */
    public function create()
    {
        $creatorContactId = $this->accountService->getAccountContactId();

        // Set options
        $options = [
            'feedbackRelationTargetId' => $this->request->input('targetId'),
            'feedbackRelationSources' => [
                [
                    'feedbackRelationSourceType' => 'contact',
                    'feedbackRelationSourceId' => $creatorContactId
                ]
            ],
            'commentRelationTargetType' => 'feedbackComment',
            'ratingRelationTargetType' => 'feedbackRating'
        ];

        // Check the type and set the target accordingly
        if ($this->request->input('type') == 'review') {

            $options['feedbackRelationTargetType'] = 'variation';

            // Limit the feedbacks count of a user per item
            $numberOfFeedbacks = (int) $this->request->input("options.numberOfFeedbacks");
            // Default visibility of the feedback
            $options['isVisible'] = $this->request->input("options.releaseFeedbacks") === 'true';
            // Allow feedbacks with no rating
            $allowNoRatingFeedbacks = $this->request->input("options.allowNoRatingFeedbacks") === 'true';
            // Allow creation of feedbacks only if the item/variation was already bought
            $allowFeedbacksOnlyIfPurchased = $this->request->input("options.allowFeedbacksOnlyIfPurchased") === 'true';

            if (!$allowNoRatingFeedbacks && empty($this->request->input('ratingValue'))) {
                return 'Can\'t create review with no rating';
            }

            // get variations bought
            $orders = pluginApp(OrderRepositoryContract::class)->allOrdersByContact($creatorContactId);

            $purchasedVariations = [];

            foreach ($orders->getResult() as $order) {
                foreach ($order->orderItems as $orderItem) {
                    $purchasedVariations[] = $orderItem->itemVariationId;
                }
            }

            if (in_array($this->request->input('targetId'), $purchasedVariations)) {
                $creatorPurchasedThisVariation = true;
                $options['feedbackRelationSources'][] = [
                    "feedbackRelationSourceType" => 'orderItem',
                    "feedbackRelationSourceId" => $options['feedbackRelationTargetId']
                ];
            }

            if ($allowFeedbacksOnlyIfPurchased && !$creatorPurchasedThisVariation) {
                return 'Not allowed to create review without purchasing the item first';
            }

            if (!empty($numberOfFeedbacks) && $numberOfFeedbacks != 0) {

                // Get the feedbacks that this user created on this item
                $countFeedbacksOfUserPerItem = $this->listFeedbacks(1, 50, [], [
                    'sourceId' => $creatorContactId,
                    'targetId' => $options['feedbackRelationTargetId']
                ])->getTotalCount();

                if ($countFeedbacksOfUserPerItem >= $numberOfFeedbacks) {
                    return 'Too many reviews';
                }
            }

            return $this->feedbackRepository->createFeedback(array_merge($this->request->all(), $options));

        } elseif ($this->request->input('type') == 'reply') {
            $options['feedbackRelationTargetType'] = 'feedback';
            $options['isVisible'] = true;

            return $this->feedbackRepository->createFeedback(array_merge($this->request->all(), $options));
        }
    }

    /**
     * Delete a feedback entry in the db
     * @param $feedbackId
     * @return bool
     */
    public function delete($feedbackId)
    {
        $feedback = $this->feedbackRepository->getFeedback($feedbackId);

        // Check if frontend user is the creator
        if ($this->accountService->getAccountContactId() == $feedback->sourceRelation[0]->feedbackRelationSourceId) {
            return $this->feedbackRepository->deleteFeedback($feedbackId);
        }

        return false;
    }

    /**
     * Update a feedback entry in the db
     * @param $feedbackId
     * @return mixed
     */
    public function update($feedbackId)
    {
        $data = $this->request->all();
        $data['isVisible'] = $data['releaseFeedbacks'] === "true";

        return $this->feedbackRepository->updateFeedback($data, $feedbackId);
    }

    /**
     * @param $itemId
     * @param $page
     * @return array
     */
    public function paginate($itemId, $page)
    {
        $lang = $this->sessionStorage->getLang();
        $itemVariations = [];
        $itemDataList = pluginApp(ItemRepositoryContract::class)->show(
            $itemId,
            ['id'],
            $lang,
            ['variations']
        );
        foreach ($itemDataList['variations'] as $itemData) {
            $itemVariations[] = $itemData['id'];
        }

        $itemAttributes = [];
        foreach ($itemVariations as $itemVariation) {
            $variationAttributes = pluginApp(VariationRepositoryContract::class)->show(
                $itemVariation,
                ['variationAttributeValues' => true]
                , '*'
            );

            $a[0] = $variationAttributes;
            $b[0] = $a;
            $actualVariationAttributes = $b[0][0]['variationAttributeValues'];

            foreach ($actualVariationAttributes as $variationAttribute) {
                $attributeName = pluginApp(AttributeNameRepositoryContract::class)->findOne(
                    $variationAttribute->attribute_id,
                    $lang
                );
                $attributeValue = pluginApp(AttributeValueNameRepositoryContract::class)->findOne(
                    $variationAttribute->value_id,
                    $lang
                );

                $itemAttributes[$itemVariation][$variationAttribute->attribute_id][$variationAttribute->value_id] = [
                    'attributeName' => $attributeName->name,
                    'attributeValue' => $attributeValue->name
                ];
            }
        }

        $page = isset($page) && $page != 0 ? $page : 1;
        $itemsPerPage = 10;
        $with = [];
        $filters = [
            'isVisible' => 1,
            'itemId' => $itemId,
        ];

        if ($this->accountService->getAccountContactId() > 0) {
            $filters['hideSourceId'] = $this->accountService->getAccountContactId();
        }

        $feedbacks = $this->listFeedbacks(
            $page,
            $itemsPerPage,
            $with,
            $filters
        );
        $feedbackResults = $feedbacks->getResult();

        foreach ($feedbackResults as &$feedback) {
            if ($feedback->targetRelation->feedbackRelationType == 'variation') {
                $feedback->targetRelation->variationAttributes = json_decode($feedback->targetRelation->targetRelationName);
            }
        }

        return [
            'feedbacks' => $feedbackResults,
            'itemAttributes' => $itemAttributes,
            'pagination' => [
                'page' => $page,
                'lastPage' => $feedbacks->getLastPage(),
                'isLastPage' => $feedbacks->isLastPage()
            ]
        ];
    }

    public function getAuthenticatedUser(int $itemId, int $variationId)
    {
        $allowFeedbacksOnlyIfPurchased = $this->request->input("allowFeedbacksOnlyIfPurchased") === 'true';
        $numberOfFeedbacks = (int) $this->request->input("numberOfFeedbacks");

        $contactId = $this->accountService->getAccountContactId();
        $isLoggedIn = $this->accountService->getIsAccountLoggedIn();
        $hasPurchased = true;
        $limitReached = false;
        $userFeedbacks = [];

        if ($isLoggedIn) {
            if ($allowFeedbacksOnlyIfPurchased) {
                // get variations bought
                $orders = pluginApp(OrderRepositoryContract::class)->allOrdersByContact($contactId);
                $purchasedVariations = [];

                foreach ($orders->getResult() as $order) {
                    foreach ($order->orderItems as $orderItem) {
                        $purchasedVariations[] = $orderItem->itemVariationId;
                    }
                }

                $hasPurchased = in_array($variationId, $purchasedVariations);
            }

            // Pagination settings for currently authenticated user's feedbacks
            $page = $this->request->get('page', 1);
            $itemsPerPage = $this->request->get('itemsPerPage', 50);

            $with = [];
            $filters = [
                'itemId' => $itemId,
                'sourceId' => $contactId
            ];

            // List of currently authenticated user's feedbacks
            $feedbacks = $this->listFeedbacks($page, $itemsPerPage, $with, $filters);
            $userFeedbacks = $feedbacks->getResult();

            foreach ($userFeedbacks as &$feedback) {
                if ($feedback->targetRelation->feedbackRelationType == 'variation') {
                    $feedback->targetRelation->variationAttributes = json_decode($feedback->targetRelation->targetRelationName);
                }
            }

            if (!is_null($numberOfFeedbacks) && $numberOfFeedbacks > 0) {
                $limitReached = $numberOfFeedbacks <= $feedbacks->getTotalCount();
            }
        }

        return [
            'id' => $contactId,
            'isLoggedIn' => $isLoggedIn,
            'limitReached' => $limitReached,
            'hasPurchased' => $hasPurchased,
            'feedbacks' => $userFeedbacks
        ];
    }

    /**
     * @return \Plenty\Repositories\Models\PaginatedResult
     */
    private function listFeedbacks(int $page = 1, int $itemsPerPage = 50, array $with = [], array $filters = [])
    {
        return $this->feedbackRepository->listFeedbacks(
            $page, // page
            $itemsPerPage, // feedbacks per page
            $with, // with relations
            $filters // filters
        );
    }
}
