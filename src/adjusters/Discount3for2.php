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
class Discount3for2 extends Component implements AdjusterInterface
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
			//Craft::dd($discount->name);
			if (strpos(strtolower($discount->name), '3for2') !== false) {
				//Craft::dump(strpos($discount->name, '3for2'));

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
		//Craft::dd($adjustments);

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment
     */
    private function _createOrderAdjustment(DiscountModel $discount, $data): OrderAdjustment
    {
        //preparing model
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $discount->name;
        $adjustment->orderId = $this->_order->id;
        $adjustment->description = $discount->description;
        $adjustment->sourceSnapshot = array_merge($discount->attributes, $data);

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

        //checking items
        $matchingQty = 0;
        $matchingTotal = 0;
        $matchingLineIds = [];
        foreach ($this->_order->getLineItems() as $item) {
            if (Commerce::getInstance()->getDiscounts()->matchLineItem($item, $this->_discount)) {
                if (!$this->_discount->allGroups) {
                    $customer = $this->_order->getCustomer();
                    $user = $customer ? $customer->getUser() : null;
                    $userGroups = Commerce::getInstance()->getCustomers()->getUserGroupIdsForUser($user);
                    if ($user && array_intersect($userGroups, $this->_discount->getUserGroupIds())) {
                        $matchingLineIds[] = $item->id;
                        $matchingQty += $item->qty;
                        $matchingTotal += $item->getSubtotal();
                    }
                } else {
					$customer = $this->_order->getCustomer();
					$user = $customer ? $customer->getUser() : null;
					$customersGroup = Craft::$app->getUserGroups()->getGroupByHandle('customers');
					if (!$user || ($user && $customersGroup && $user->isInGroup('customers'))) {
                    	$matchingLineIds[] = $item->id;
                    	$matchingQty += $item->qty;
						$matchingTotal += $item->getSubtotal();
					}
                }
            }
		}
		
		//Craft::dd($matchingQty);

        if (!$matchingQty) {
            return false;
        }

        // Have they entered a max qty?
        if ($this->_discount->maxPurchaseQty > 0 && $matchingQty > $this->_discount->maxPurchaseQty) {
            return false;
        }

        // Reject if they have not added enough matching items
        if ($matchingQty < $this->_discount->purchaseQty) {
            return false;
        }

        // Reject if the matching items values is not enough
        if ($matchingTotal < $this->_discount->purchaseTotal) {
            return false;
		}
		
		//Craft::dd($matchingLineIds);
		//$amounts = [];

        foreach ($this->_order->getLineItems() as $item) {
            if (in_array($item->id, $matchingLineIds, false)) {
				$adjustment = $this->_createOrderAdjustment($this->_discount, ['qty'=>floor($item->qty/3)]);
				/*if(!$item->id){
					
					$lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($this->_order->id, $item->purchasableId, $item->options);
					Craft::dd($lineItem);
				}*/
				//Craft::dump($item->id);
                $adjustment->lineItemId = $item->id;

				$amountPerItem = Currency::round($this->_discount->perItemDiscount * $item->qty);

				$lineItemDiscount = (floor($item->qty/3)) * $item->salePrice;

				//Craft::dump($lineItemDiscount);

				$diff = null;
                // If the discount is now larger than the subtotal only make the discount amount the same as the total of the line.
                if ((($lineItemDiscount + $item->getAdjustmentsTotalByType('discount')) * -1) > $item->getSubtotal()) {
                    $diff = ($lineItemDiscount + $item->getAdjustmentsTotalByType('discount')) - $item->getSubtotal();
                }

                if ($diff !== null) {
                    $adjustment->amount = 0-$diff;
                } else {
                    $adjustment->amount = 0-$lineItemDiscount;
				}
				//Craft::dd($adjustment->amount);

                if ($adjustment->amount != 0) {
                    $adjustments[] = $adjustment;
                }

				//$adjustment->amount = - ((floor($item->qty/3)) * $item->salePrice);

				/*if ($adjustment->amount != 0) {
                    $adjustments[] = $adjustment;
                }*/
				
				// for($i = 0; $i < $item->qty; $i++)
				// {
				// 	$amounts[] = $item->salePrice;
				// }

                //Default is percentage off already discounted price
                /*$existingLineItemDiscount = $item->getAdjustmentsTotalByType('discount');
                $existingLineItemPrice = ($item->getSubtotal() + $existingLineItemDiscount);
                $amountPercentage = Currency::round($this->_discount->percentDiscount * $existingLineItemPrice);

                if ($this->_discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
                    $amountPercentage = Currency::round($this->_discount->percentDiscount * $item->getSubtotal());
                }

                $lineItemDiscount = $amountPerItem + $amountPercentage;

                $diff = null;
                // If the discount is now larger than the subtotal only make the discount amount the same as the total of the line.
                if ((($lineItemDiscount + $item->getAdjustmentsTotalByType('discount')) * -1) > $item->getSubtotal()) {
                    $diff = ($lineItemDiscount + $item->getAdjustmentsTotalByType('discount')) - $item->getSubtotal();
                }

                if ($diff !== null) {
                    $adjustment->amount = $diff;
                } else {
                    $adjustment->amount = $lineItemDiscount;
                }

                if ($adjustment->amount != 0) {
                    $adjustments[] = $adjustment;
                }*/
            }
		}
		//Craft::dd($adjustments);


        foreach ($this->_order->getLineItems() as $item) {
            if (in_array($item->id, $matchingLineIds, false) && $discount->freeShipping) {
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
		
		//Craft::dd($discount->baseDiscount);

        if ($discount->baseDiscount !== null && $discount->baseDiscount != 0) {
            $baseDiscountAdjustment = $this->_createOrderAdjustment($discount);
            $baseDiscountAdjustment->lineItemId = null;
            $baseDiscountAdjustment->amount = $discount->baseDiscount;
            $adjustments[] = $baseDiscountAdjustment;
        }

        // only display adjustment if an amount was calculated
        if (!count($adjustments)) {
            return false;
        }

        // Raise the 'beforeMatchLineItem' event
        $event = new DiscountAdjustmentsEvent([
            'order' => $this->_order,
            'discount' => $discount,
            'adjustments' => $adjustments
        ]);


        return $event->adjustments;
    }
}
