<?php
/**
 * Promotions plugin for Craft CMS 3.x
 *
 * Adds promotions
 *
 * @link      https://kurious.agency
 * @copyright Copyright (c) 2018 Kurious Agency
 */

namespace kuriousagency\commerce\promotions;

use kuriousagency\commerce\promotions\adjusters\Discount3for2;
use kuriousagency\commerce\promotions\adjusters\Bundles;
use kuriousagency\commerce\promotions\adjusters\Trade;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\commerce\adjusters\Discount as CommerceDiscount;
use craft\commerce\services\OrderAdjustments;
use craft\commerce\events\DiscountAdjustmentsEvent;

use yii\base\Event;

/**
 * Class Promotions
 *
 * @author    Kurious Agency
 * @package   Promotions
 * @since     1.0.0
 *
 */
class Promotions extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var Promotions
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
		self::$plugin = $this;
		
		Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, function(RegisterComponentTypesEvent $e) {
			
			$adjusters = [
				Discount3for2::class, 
				Bundles::class, 
				//Trade::class, 
			];

			$existing = [];
			foreach ($e->types as $type)
			{
				$key = explode('\\',$type);
				$existing[] = end($key);
			}

			foreach ($adjusters as $type)
			{
				$key = explode('\\',$type);
				if (!in_array(end($key), $existing)) {
					$e->types = array_merge([$type], $e->types);
				}
			}
			//Craft::dd($e->types);
		});

		Event::on(CommerceDiscount::class, CommerceDiscount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED, function(DiscountAdjustmentsEvent $e) {
			
			if (strpos(strtolower($e->discount->name), 'bundle') !== false) {
				$e->isValid = false;
			}
			/*if (strpos(strtolower($e->discount->name), 'trade') !== false) {
				$e->isValid = false;
			}*/
			if (strpos(strtolower($e->discount->name), '3for2') !== false) {
				$e->isValid = false;
			}
			//Craft::dd(strpos(strtolower($e->discount->name), 'trade'));
			
		});

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                }
            }
        );

        Craft::info(
            Craft::t(
                'commerce-promotions',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

}
