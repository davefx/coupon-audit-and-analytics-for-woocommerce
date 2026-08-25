<?php
/**
 * Coupon inventory screen.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Service\InventoryService;
use DFX\CouponAAW\Service\InventorySummary;

/**
 * The first screen this plugin ever shows, and the whole of the audit half.
 *
 * No logic lives here: it asks the service for the inventory, and formats it
 * (§5). The screen reads top-down as an answer to one question — what is live in
 * this store, and what should not be.
 */
final class InventoryPage {

	/**
	 * The capability required to see any of this.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Constructor.
	 *
	 * @param InventoryService   $inventory Supplies the figures.
	 * @param InventoryListTable $table   Renders the rows.
	 */
	public function __construct(
		private readonly InventoryService $inventory,
		private readonly InventoryListTable $table
	) {}

	/**
	 * Render the screen.
	 *
	 * The capability is checked again here even though the menu already gates
	 * it: a page callback is reachable by URL, and §14 asks for the check on
	 * every admin entry point rather than on the ones that look reachable.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to view the coupon audit.',
					'coupon-audit-and-analytics-for-woocommerce'
				),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a filter from the URL, not acting on a submission.
		$query = $_GET;

		$filter = InventoryFilterRequest::from( $query );

		$screen = $this->inventory->screen(
			$filter,
			InventoryOrderRequest::from( $query ),
			InventoryOrderRequest::page( $query ),
			InventoryListTable::PER_PAGE
		);

		$this->table->set_filter( $filter );
		$this->table->set_page( $screen->entries, $screen->total, $screen->summary );
		$this->table->prepare_items();

		echo '<div class="wrap dfxcaaw-inventory">';
		printf( '<h1>%s</h1>', esc_html__( 'Coupon Audit', 'coupon-audit-and-analytics-for-woocommerce' ) );

		$this->render_summary( $screen->summary );

		// Outside the form: these are links, and a link inside a GET form is
		// still a link, but WordPress puts the views above the filter controls
		// on every screen that has both and a reader expects them there.
		$this->table->views();

		echo '<form method="get">';
		printf(
			'<input type="hidden" name="page" value="%s" />',
			esc_attr( MenuRegistrar::PAGE_SLUG )
		);
		$this->table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The figures above the table.
	 *
	 * @param InventorySummary $summary The counts to show.
	 */
	private function render_summary( InventorySummary $summary ): void {
		echo '<div class="dfxcaaw-summary">';

		$this->render_tile(
			__( 'Coupons', 'coupon-audit-and-analytics-for-woocommerce' ),
			$summary->total,
			''
		);
		$this->render_tile(
			__( 'Active', 'coupon-audit-and-analytics-for-woocommerce' ),
			$summary->of( CouponStatus::ACTIVE ),
			''
		);
		$this->render_tile(
			__( 'Needs attention', 'coupon-audit-and-analytics-for-woocommerce' ),
			$summary->orphans,
			$summary->orphans > 0 ? 'dfxcaaw-tile--warn' : ''
		);
		$this->render_tile(
			__( 'Apply to everything', 'coupon-audit-and-analytics-for-woocommerce' ),
			$summary->unrestricted,
			$summary->unrestricted > 0 ? 'dfxcaaw-tile--warn' : ''
		);

		if ( null === $summary->overlaps ) {
			$this->render_text_tile(
				__( 'Overlapping pairs', 'coupon-audit-and-analytics-for-woocommerce' ),
				/* translators: shown when the inventory is too large to compare on page load. */
				__( 'Not checked', 'coupon-audit-and-analytics-for-woocommerce' ),
				''
			);
		} else {
			$this->render_tile(
				__( 'Overlapping pairs', 'coupon-audit-and-analytics-for-woocommerce' ),
				$summary->overlaps,
				$summary->overlaps > 0 ? 'dfxcaaw-tile--warn' : ''
			);
		}

		echo '</div>';

		if ( null === $summary->overlaps ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: the number of coupons above which the check is skipped. */
						__(
							'Overlap detection was skipped: comparing every coupon against every other is too slow to do while you wait above %d coupons.',
							'coupon-audit-and-analytics-for-woocommerce'
						),
						OverlapDetector::SYNCHRONOUS_LIMIT
					)
				)
			);
		}

		if ( $summary->total > 0 && 0 === $summary->orphans && 0 === $summary->unrestricted && 0 === $summary->overlaps ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__(
					'Nothing needs attention. Every coupon expires, none applies to your whole catalogue, and none of them collide.',
					'coupon-audit-and-analytics-for-woocommerce'
				)
			);
		}
	}

	/**
	 * One figure.
	 *
	 * @param string $label    What is being counted.
	 * @param int    $value    The count.
	 * @param string $modifier Extra CSS class, or an empty string.
	 */
	private function render_tile( string $label, int $value, string $modifier ): void {
		$this->render_text_tile( $label, number_format_i18n( $value ), $modifier );
	}

	/**
	 * One tile whose value is words rather than a number.
	 *
	 * @param string $label    What is being reported.
	 * @param string $value    The value to show.
	 * @param string $modifier Extra CSS class, or an empty string.
	 */
	private function render_text_tile( string $label, string $value, string $modifier ): void {
		printf(
			'<div class="dfxcaaw-tile %1$s"><span class="dfxcaaw-tile__value">%2$s</span><span class="dfxcaaw-tile__label">%3$s</span></div>',
			esc_attr( $modifier ),
			esc_html( $value ),
			esc_html( $label )
		);
	}
}
