<?php

/**
 * Coupon margin screen.
 *
 * @package DFX\CouponAAW
 */
declare (strict_types = 1);
namespace DFX\CouponAAW\Admin;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Domain\Profit\CouponMargin;
use DFX\CouponAAW\Install\Aggregator;
use DFX\CouponAAW\Service\MarginService;
/**
 * The analytics half, and the screen §6.3 was written for.
 *
 * Its hardest job is saying "I do not know" convincingly. A store with no cost
 * system gets a screen that explains what to install rather than a table of
 * zeroes; a store with partial data gets its margins labelled as estimates.
 */
final class MarginPage {
    /**
     * The screen's page slug.
     */
    public const PAGE_SLUG = 'dfxcaaw-margins';

    /**
     * Constructor.
     *
     * @param MarginService   $margins    Supplies the figures.
     * @param MarginListTable $table      Renders the rows.
     * @param Aggregator      $aggregator Reports backfill progress.
     */
    public function __construct( private readonly MarginService $margins, private readonly MarginListTable $table, private readonly Aggregator $aggregator ) {
    }

    /**
     * Render the screen.
     */
    public function render() : void {
        if ( !current_user_can( InventoryPage::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to view coupon margins.', 'coupon-audit-and-analytics-for-woocommerce' ), 403 );
        }
        $margins = $this->margins->margins();
        [$from, $to] = $this->margins->window();
        $this->table->set_margins( $margins );
        $this->table->prepare_items();
        echo '<div class="wrap dfxcaaw-margins">';
        printf( '<h1>%s</h1>', esc_html__( 'Coupon Margin', 'coupon-audit-and-analytics-for-woocommerce' ) );
        printf( '<p class="description">%s</p>', esc_html( sprintf( 
            /* translators: 1: first day of the window, 2: last day. */
            __( 'Gross margin from %1$s to %2$s.', 'coupon-audit-and-analytics-for-woocommerce' ),
            $this->date( $from ),
            $this->date( $to )
         ) ) );
        $this->render_backfill_notice();
        $this->render_coverage_notice( $margins );
        echo '<form method="get">';
        printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE_SLUG ) );
        $this->table->display();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Format a day the way the store formats dates.
     *
     * @param DateTimeImmutable $day The day to format.
     */
    private function date( DateTimeImmutable $day ) : string {
        $formatted = wp_date( (string) get_option( 'date_format' ), $day->getTimestamp() );
        return ( false === $formatted ? $day->format( 'Y-m-d' ) : $formatted );
    }

    /**
     * Say so while history is still being read.
     *
     * Without this, a store that has just installed the plugin sees a nearly
     * empty screen and concludes the plugin does not work.
     */
    private function render_backfill_notice() : void {
        $cursor = $this->aggregator->backfill_cursor();
        if ( null === $cursor ) {
            return;
        }
        printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( sprintf( 
            /* translators: %s: the date the backfill has reached. */
            __( 'Still reading past orders — figures are complete up to %s and will fill in as the work continues.', 'coupon-audit-and-analytics-for-woocommerce' ),
            $cursor
         ) ) );
    }

    /**
     * Explain an empty or partial table, which is the common case.
     *
     * @param list<CouponMargin> $margins The figures being shown.
     */
    private function render_coverage_notice( array $margins ) : void {
        if ( array() === $margins ) {
            return;
        }
        $known = 0;
        foreach ( $margins as $margin ) {
            $known += ( CostCoverage::NONE === $margin->coverage() ? 0 : 1 );
        }
        if ( 0 === $known ) {
            printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'No margin can be shown yet, because none of these orders has a cost recorded against it. Set product costs in WooCommerce, or in whichever cost-of-goods plugin your shop already uses, and the figures will appear.', 'coupon-audit-and-analytics-for-woocommerce' ) );
            return;
        }
        if ( $known < count( $margins ) ) {
            printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html__( 'Some coupons have no cost recorded against their orders, so no margin is shown for them. Margins marked as an estimate cover only part of their lines.', 'coupon-audit-and-analytics-for-woocommerce' ) );
        }
    }

}
