<?php
/**
 * Warnings shown on the coupon edit screen.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;
use DFX\CouponAAW\Service\PrePublishValidator;
use DFX\CouponAAW\Service\PrePublishWarning;
use DFX\CouponAAW\Service\PrePublishWarningType;

/**
 * Puts the audit's findings where the decision is actually made (§9).
 *
 * The inventory screen tells a user what is wrong across the store, which they
 * will read occasionally. This tells them what is wrong with the coupon in front
 * of them, at the moment they are editing it, which is what turns the plugin
 * into a habit rather than a report.
 *
 * Nothing here can stop a save. These are `notice-warning`, never
 * `notice-error`, and no hook that could veto the save is touched: a plugin that
 * prevents work gets uninstalled the first Tuesday.
 */
final class CouponEditorNotices {

	/**
     * @var PrePublishValidator
     * @readonly
     */
    private PrePublishValidator $validator;
    /**
     * @var CouponRepositoryInterface
     * @readonly
     */
    private CouponRepositoryInterface $coupons;
    /**
	 * Constructor.
	 *
	 * @param PrePublishValidator       $validator Produces the warnings.
	 * @param CouponRepositoryInterface $coupons   Loads the coupon being edited.
	 */
	public function __construct(PrePublishValidator $validator, CouponRepositoryInterface $coupons)
    {
        $this->validator = $validator;
        $this->coupons = $coupons;
    }

	/**
	 * Render the warnings, if this is a coupon being edited.
	 */
	public function render(): void {
		if ( ! $this->is_coupon_editor() || ! current_user_can( InventoryPage::CAPABILITY ) ) {
			return;
		}

		$coupon = $this->current_coupon();

		if ( null === $coupon ) {
			return;
		}

		foreach ( $this->validator->validate( $coupon ) as $warning ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Coupon audit:', 'coupon-audit-and-analytics-for-woocommerce' ),
				esc_html( $this->message( $warning ) )
			);
		}
	}

	/**
	 * Whether the current screen is a single coupon being edited.
	 */
	private function is_coupon_editor(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && 'post' === $screen->base && 'shop_coupon' === $screen->post_type;
	}

	/**
	 * The coupon currently being edited, if it has been saved at all.
	 *
	 * A coupon being created for the first time has nothing stored to check, so
	 * the warnings appear from its first save onwards — which is also the first
	 * moment any of them could be true.
	 */
	private function current_coupon(): ?CouponSnapshot {
		$post_id = get_the_ID();

		if ( ! is_int( $post_id ) || $post_id < 1 ) {
			return null;
		}

		return $this->coupons->find( new CouponId( $post_id ) );
	}

	/**
	 * The sentence shown for one warning.
	 *
	 * @param PrePublishWarning $warning The warning to phrase.
	 *
	 * @throws \InvalidArgumentException When the warning type is not one this method knows.
	 */
	private function message( PrePublishWarning $warning ): string {
		$type = $warning->type;

		if ( PrePublishWarningType::NO_EXPIRY_DATE() === $type ) {
			return __(
				'This coupon has no expiry date, so nothing will ever turn it off.',
				'coupon-audit-and-analytics-for-woocommerce'
			);
		}

		if ( PrePublishWarningType::NO_USAGE_LIMIT() === $type ) {
			return __(
				'This coupon has no usage limit, so it can be redeemed any number of times.',
				'coupon-audit-and-analytics-for-woocommerce'
			);
		}

		if ( PrePublishWarningType::OVERLAPS_EXISTING() === $type ) {
			return $this->overlap_message( $warning );
		}

		throw new \InvalidArgumentException( 'Unhandled pre-publish warning type.' );
	}

	/**
	 * The sentence for an overlap, naming the coupons involved.
	 *
	 * A warning that says only "this overlaps something" gives the user nothing
	 * to act on, so the other codes are named.
	 *
	 * @param PrePublishWarning $warning The overlap warning.
	 */
	private function overlap_message( PrePublishWarning $warning ): string {
		$codes = array_map(
			static fn ( CouponSnapshot $coupon ): string => wp_specialchars_decode( $coupon->code, ENT_QUOTES ),
			$warning->related
		);

		$sentence = sprintf(
			/* translators: 1: number of other coupons, 2: comma-separated coupon codes. */
			_n(
				'This coupon applies to the same products as %1$d other live coupon: %2$s.',
				'This coupon applies to the same products as %1$d other live coupons: %2$s.',
				count( $codes ),
				'coupon-audit-and-analytics-for-woocommerce'
			),
			count( $codes ),
			implode( ', ', $codes )
		);

		if ( OverlapSeverity::HIGH() === $warning->severity ) {
			$sentence .= ' ' . __(
				'Both are applied automatically, so a customer does not have to find them.',
				'coupon-audit-and-analytics-for-woocommerce'
			);
		}

		return $sentence;
	}
}
