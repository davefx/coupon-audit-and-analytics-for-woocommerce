<?php
/**
 * Coupon inventory list table.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Service\InventoryEntry;
use WP_List_Table;

/**
 * Renders the inventory using WordPress's own table, rather than a bespoke one.
 *
 * §16 left the choice open between a React screen embedded in WooCommerce
 * Analytics and dedicated pages with native list tables, and recommended the
 * latter for v1: it is faster to build and does not break every time WooCommerce
 * restructures its own admin.
 */
final class InventoryListTable extends WP_List_Table {

	/**
	 * Coupons shown per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * The rows to display, handed in by the page.
	 *
	 * @var list<InventoryEntry>
	 */
	private array $entries = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'coupon',
				'plural'   => 'coupons',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The table's columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'code'     => __( 'Code', 'coupon-audit-and-analytics-for-woocommerce' ),
			'status'   => __( 'Status', 'coupon-audit-and-analytics-for-woocommerce' ),
			'scope'    => __( 'Applies to', 'coupon-audit-and-analytics-for-woocommerce' ),
			'expires'  => __( 'Expires', 'coupon-audit-and-analytics-for-woocommerce' ),
			'usage'    => __( 'Used', 'coupon-audit-and-analytics-for-woocommerce' ),
			'findings' => __( 'Findings', 'coupon-audit-and-analytics-for-woocommerce' ),
		);
	}

	/**
	 * Columns the user can sort by.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns() {
		return array(
			'code'    => array( 'code', false ),
			'status'  => array( 'status', false ),
			'expires' => array( 'expires', false ),
		);
	}

	/**
	 * Supply the rows to display.
	 *
	 * The table is given its data rather than fetching it, so that the screen
	 * reads the inventory exactly once and this class keeps no logic (§5).
	 *
	 * @param list<InventoryEntry> $entries Every judged coupon.
	 */
	public function set_entries( array $entries ): void {
		$this->entries = $entries;
	}

	/**
	 * Build the rows for the current page.
	 */
	public function prepare_items(): void {
		$entries = $this->sort( $this->entries );

		$total  = count( $entries );
		$page   = max( 1, $this->get_pagenum() );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = array_slice( $entries, $offset, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Shown when the store has no coupons at all.
	 */
	public function no_items(): void {
		esc_html_e( 'No coupons found.', 'coupon-audit-and-analytics-for-woocommerce' );
	}

	/**
	 * Render one cell.
	 *
	 * @param InventoryEntry $item        The row.
	 * @param string         $column_name Which column is being rendered.
	 *
	 * @return string Already-escaped HTML.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'code':
				return $this->code_cell( $item );

			case 'status':
				return sprintf(
					'<span class="dfxcaaw-status dfxcaaw-status--%1$s">%2$s</span>',
					esc_attr( $item->status->value ),
					esc_html( self::status_label( $item->status ) )
				);

			case 'scope':
				return esc_html(
					$item->coupon->scope->is_universal()
						? __( 'Everything', 'coupon-audit-and-analytics-for-woocommerce' )
						: __( 'Restricted', 'coupon-audit-and-analytics-for-woocommerce' )
				);

			case 'expires':
				return $this->expiry_cell( $item );

			case 'usage':
				return esc_html( $this->usage_label( $item ) );

			case 'findings':
				return $this->findings_cell( $item );

			default:
				return '';
		}
	}

	/**
	 * The code, linked to the coupon editor where the user can act on it.
	 *
	 * @param InventoryEntry $entry The row.
	 */
	private function code_cell( InventoryEntry $entry ): string {
		$link = get_edit_post_link( $entry->coupon->id->value );

		// WooCommerce stores codes HTML-encoded, so a coupon called "A&B" is
		// held as "a&amp;b". Escaping that directly would render "a&amp;b" on
		// screen; decoding first and escaping after shows what the customer types.
		$code = esc_html( wp_specialchars_decode( $entry->coupon->code, ENT_QUOTES ) );

		if ( null === $link ) {
			return sprintf( '<strong>%s</strong>', $code );
		}

		return sprintf( '<strong><a href="%1$s">%2$s</a></strong>', esc_url( $link ), $code );
	}

	/**
	 * The expiry date, or a note that there is none.
	 *
	 * @param InventoryEntry $entry The row.
	 */
	private function expiry_cell( InventoryEntry $entry ): string {
		if ( null === $entry->coupon->expires_at ) {
			return sprintf(
				'<em>%s</em>',
				esc_html__( 'Never', 'coupon-audit-and-analytics-for-woocommerce' )
			);
		}

		$formatted = wp_date(
			(string) get_option( 'date_format' ),
			$entry->coupon->expires_at->getTimestamp()
		);

		return esc_html( false === $formatted ? '' : $formatted );
	}

	/**
	 * Usage against the limit, if there is one.
	 *
	 * @param InventoryEntry $entry The row.
	 */
	private function usage_label( InventoryEntry $entry ): string {
		if ( null === $entry->coupon->usage_limit ) {
			return (string) $entry->coupon->usage_count;
		}

		return sprintf(
			/* translators: 1: times used, 2: usage limit. */
			__( '%1$d of %2$d', 'coupon-audit-and-analytics-for-woocommerce' ),
			$entry->coupon->usage_count,
			$entry->coupon->usage_limit
		);
	}

	/**
	 * Why this coupon was flagged, if it was.
	 *
	 * @param InventoryEntry $entry The row.
	 */
	private function findings_cell( InventoryEntry $entry ): string {
		$labels = array_map(
			static fn ( OrphanReason $reason ): string => sprintf(
				'<span class="dfxcaaw-finding">%s</span>',
				esc_html( self::orphan_label( $reason ) )
			),
			$entry->orphan_reasons
		);

		$worst = $entry->worst_overlap();

		if ( null !== $worst ) {
			$labels[] = sprintf(
				'<span class="dfxcaaw-finding dfxcaaw-finding--%1$s">%2$s</span>',
				esc_attr( $worst->value ),
				esc_html(
					sprintf(
						/* translators: 1: severity of the worst collision, 2: how many coupons it collides with. */
						_n(
							'Overlaps %2$d coupon (%1$s)',
							'Overlaps %2$d coupons (%1$s)',
							count( $entry->overlaps ),
							'coupon-audit-and-analytics-for-woocommerce'
						),
						self::severity_label( $worst ),
						count( $entry->overlaps )
					)
				)
			);
		}

		if ( array() === $labels ) {
			return '<span class="dfxcaaw-finding-none">&mdash;</span>';
		}

		return implode( ' ', $labels );
	}

	/**
	 * The human-readable name of an overlap severity.
	 *
	 * @param OverlapSeverity $severity The severity to label.
	 */
	private static function severity_label( OverlapSeverity $severity ): string {
		return match ( $severity ) {
			OverlapSeverity::HIGH   => __( 'high', 'coupon-audit-and-analytics-for-woocommerce' ),
			OverlapSeverity::MEDIUM => __( 'medium', 'coupon-audit-and-analytics-for-woocommerce' ),
			OverlapSeverity::LOW    => __( 'low', 'coupon-audit-and-analytics-for-woocommerce' ),
		};
	}

	/**
	 * Sort the entries by the requested column.
	 *
	 * Sorting happens here rather than in the query because the inventory is
	 * already fully in memory — the dead-campaign rule requires it — and status
	 * is derived rather than stored, so the database could not order by it.
	 *
	 * @param list<InventoryEntry> $entries The rows to sort.
	 *
	 * @return list<InventoryEntry>
	 */
	private function sort( array $entries ): array {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'code'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? -1 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		usort(
			$entries,
			static function ( InventoryEntry $a, InventoryEntry $b ) use ( $orderby, $order ): int {
				switch ( $orderby ) {
					case 'status':
						$comparison = strcmp( $a->status->value, $b->status->value );
						break;

					case 'expires':
						$comparison = ( $a->coupon->expires_at?->getTimestamp() ?? PHP_INT_MAX )
							<=> ( $b->coupon->expires_at?->getTimestamp() ?? PHP_INT_MAX );
						break;

					default:
						$comparison = strcmp( $a->coupon->code, $b->coupon->code );
				}

				return $comparison * $order;
			}
		);

		return $entries;
	}

	/**
	 * The human-readable name of a status.
	 *
	 * The enum deliberately carries no labels: a translated string there would
	 * drag WordPress into the domain (§5). Presentation belongs here.
	 *
	 * @param CouponStatus $status The status to label.
	 */
	private static function status_label( CouponStatus $status ): string {
		return match ( $status ) {
			CouponStatus::ACTIVE    => __( 'Active', 'coupon-audit-and-analytics-for-woocommerce' ),
			CouponStatus::SCHEDULED => __( 'Scheduled', 'coupon-audit-and-analytics-for-woocommerce' ),
			CouponStatus::EXPIRED   => __( 'Expired', 'coupon-audit-and-analytics-for-woocommerce' ),
			CouponStatus::EXHAUSTED => __( 'Exhausted', 'coupon-audit-and-analytics-for-woocommerce' ),
			CouponStatus::INACTIVE  => __( 'Inactive', 'coupon-audit-and-analytics-for-woocommerce' ),
		};
	}

	/**
	 * The human-readable name of an orphan finding.
	 *
	 * @param OrphanReason $reason The finding to label.
	 */
	private static function orphan_label( OrphanReason $reason ): string {
		return match ( $reason ) {
			OrphanReason::NO_EXPIRY_DATE => __( 'No expiry date', 'coupon-audit-and-analytics-for-woocommerce' ),
			OrphanReason::DORMANT        => __( 'Dormant', 'coupon-audit-and-analytics-for-woocommerce' ),
			OrphanReason::DEAD_CAMPAIGN  => __( 'Dead campaign', 'coupon-audit-and-analytics-for-woocommerce' ),
		};
	}
}
