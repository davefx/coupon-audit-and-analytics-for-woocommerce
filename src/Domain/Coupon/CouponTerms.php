<?php
/**
 * The commercial terms of a coupon.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DFX\CouponAAW\Domain\Profit\Money;

/**
 * Everything about a coupon that is a term of the offer rather than a matter of
 * scheduling or scope.
 *
 * These are the fields a shop owner scans an overview for — how much, on what
 * minimum, how many times each — and none of them were captured before. A
 * coupon overview that cannot tell you what the discount is has missed its own
 * point.
 */
final class CouponTerms {

	/**
     * @var DiscountAmount
     * @readonly
     */
    public DiscountAmount $amount;
    /**
     * @var Money|null
     * @readonly
     */
    public ?Money $minimum_spend = null;
    /**
     * @var Money|null
     * @readonly
     */
    public ?Money $maximum_spend = null;
    /**
     * @var bool
     * @readonly
     */
    public bool $grants_free_shipping = false;
    /**
     * @var bool
     * @readonly
     */
    public bool $is_individual_use = false;
    /**
     * @var int|null
     * @readonly
     */
    public ?int $usage_limit_per_user = null;
    /**
     * @var int|null
     * @readonly
     */
    public ?int $limit_usage_to_items = null;
    /**
     * @var list<string>
     * @readonly
     */
    public array $email_restrictions = array();
    /**
	 * Constructor.
	 *
	 * @param DiscountAmount $amount                What the coupon takes off.
	 * @param Money|null     $minimum_spend         Least the basket may be worth, if set.
	 * @param Money|null     $maximum_spend         Most the basket may be worth, if set.
	 * @param bool           $grants_free_shipping  Whether it also removes shipping.
	 * @param bool           $is_individual_use     Whether it refuses to be combined with others.
	 * @param int|null       $usage_limit_per_user  Redemptions allowed per customer, if capped.
	 * @param int|null       $limit_usage_to_items  Items in one basket it may apply to, if capped.
	 * @param list<string>   $email_restrictions    Billing addresses it is limited to.
	 */
	public function __construct(DiscountAmount $amount, ?Money $minimum_spend = null, ?Money $maximum_spend = null, bool $grants_free_shipping = false, bool $is_individual_use = false, ?int $usage_limit_per_user = null, ?int $limit_usage_to_items = null, array $email_restrictions = array())
    {
        $this->amount = $amount;
        $this->minimum_spend = $minimum_spend;
        $this->maximum_spend = $maximum_spend;
        $this->grants_free_shipping = $grants_free_shipping;
        $this->is_individual_use = $is_individual_use;
        $this->usage_limit_per_user = $usage_limit_per_user;
        $this->limit_usage_to_items = $limit_usage_to_items;
        $this->email_restrictions = $email_restrictions;
    }

	/**
	 * Whether the coupon is restricted to particular billing addresses.
	 */
	public function has_email_restrictions(): bool {
		return array() !== $this->email_restrictions;
	}
}
