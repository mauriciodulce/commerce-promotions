<?php
/**
 * Promotions plugin for Craft CMS 3.x
 *
 * Adds promotions
 *
 * @link      https://kurious.agency
 * @copyright Copyright (c) 2018 Kurious Agency
 */

namespace kuriousagency\commerce\promotions\adjusters;


use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\events\DiscountAdjustmentsEvent;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Discount as DiscountModel;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Discount as DiscountRecord;

/**
 * Discount Adjuster
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Bundles extends Component implements AdjusterInterface
{
    // Constants
    // =========================================================================

    /**
     * The discount adjustment type.
     */
    const ADJUSTMENT_TYPE = 'discount';


    // Properties
    // =========================================================================

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var
     */
    private $_discount;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $discounts = Commerce::getInstance()->getDiscounts()->getAllDiscounts();

        // Find discounts with no coupon or the coupon that matches the order.
        $availableDiscounts = [];
        foreach ($discounts as $discount) {

            if (!$discount->enabled) {
                continue;
			}
			
			if (strpos(strtolower($discount->name), 'bundle') !== false) {

				if ($discount->code == null) {
					$availableDiscounts[] = $discount;
				} else {
					if ($this->_order->couponCode && (strcasecmp($this->_order->couponCode, $discount->code) == 0)) {
						$availableDiscounts[] = $discount;
					}
				}
				continue;
			}
        }

        $adjustments = [];

		//Craft::dd($availableDiscounts);

        foreach ($availableDiscounts as $discount) {
            $newAdjustments = $this->_getAdjustments($discount);
            if ($newAdjustments) {
                $adjustments = array_merge($adjustments, $newAdjustments);

                if ($discount->stopProcessing) {
                    break;
                }
            }
        }

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment
     */
    private function _createOrderAdjustment(DiscountModel $discount): OrderAdjustment
    {
        //preparing model
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $discount->name;
        $adjustment->orderId = $this->_order->id;
        $adjustment->description = $discount->description;
        $adjustment->sourceSnapshot = $discount->attributes;

        return $adjustment;
    }

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment[]|false
     */
    private function _getAdjustments(DiscountModel $discount)
    {
        $adjustments = [];

        $this->_discount = $discount;

        $now = new \DateTime();
        $from = $this->_discount->dateFrom;
        $to = $this->_discount->dateTo;
        if (($from && $from > $now) || ($to && $to < $now)) {
            return false;
		}

		$total = 0;
		$matchingItems = [];
		$matchingIds = [];
		
		// TODO : check user groups
		//checking items
		foreach ($this->_order->getLineItems() as $item) {
			if (Commerce::getInstance()->getDiscounts()->matchLineItem($item, $this->_discount)) {
				if (!$this->_discount->allGroups) {
					$customer = $this->_order->getCustomer();
                    $user = $customer ? $customer->getUser() : null;
                    $userGroups = Commerce::getInstance()->getCustomers()->getUserGroupIdsForUser($user);
                    if ($user && array_intersect($userGroups, $this->_discount->getUserGroupIds())) {
                        if ($item->qty >= $this->_discount->purchaseQty) {
							$matchingItems[] = $item;
							$matchingIds[] = $item->purchasableId;
						}
                    }
				} else {
					if ($item->qty >= $this->_discount->purchaseQty) {
						$matchingItems[] = $item;
						$matchingIds[] = $item->purchasableId;
					}
				}
			}
		}

		foreach ($this->_discount->getPurchasableIds() as $purchasable)
		{
			if(!in_array($purchasable, $matchingIds, true)) {
				return false;
			}
		}

		$bundles = null;
		foreach ($matchingItems as $item)
		{
			$qty = floor(($item->qty / $this->_discount->purchaseQty));
			if (!$bundles || $qty < $bundles) {
				$bundles = $qty;
			}
		}
		foreach ($matchingItems as $item)
		{
			//Craft::dd($item->getAdjustments());
			$total += ($bundles * $this->_discount->purchaseQty) * $item->salePrice;
		}

		$adjustment = $this->_createOrderAdjustment($this->_discount);
		$adjustment->amount = Currency::round($total * $this->_discount->percentDiscount);
		if ($adjustment->amount != 0) {
			$adjustments[] = $adjustment;
		}

		if ($bundles && $discount->freeShipping) {
			foreach ($this->_order->getLineItems() as $item) {
				$adjustment = $this->_createOrderAdjustment($this->_discount);
				$shippingCost = $item->getAdjustmentsTotalByType('shipping');
				if ($shippingCost > 0) {
					$adjustment->lineItemId = $item->id;
					$adjustment->amount = $shippingCost * -1;
					$adjustment->description = Craft::t('commerce', 'Remove Shipping Cost');
					$adjustments[] = $adjustment;
				}
			}
		}
		
		return $adjustments;

    }
}
