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
use DFX\CouponAAW\Service\MarginPageResult;
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
        [$from, $to] = $this->margins->window();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a page number from the URL, not acting on a submission.
        $page = InventoryOrderRequest::page( $_GET );
        $result = $this->margins->page( $page, MarginListTable::PER_PAGE );
        /**
         * Fires with the margin rows the screen is about to draw.
         *
         * The rows of one page, in the order they will appear. An add-on
         * rendering a column of its own needs this: without it, it has no way to
         * know which twenty coupons are on screen, so it reads the whole window
         * to fill twenty cells — which is the cost the paging exists to remove,
         * moved one plugin over.
         *
         * The whole result is passed rather than the list of rows, and not only
         * because the window totals are useful. `do_action()` carries a legacy
         * branch: an array holding exactly one object is unwrapped, and the
         * listener is handed that object instead of the array. A shop whose
         * window contains a single coupon would call every listener with a
         * `CouponMargin` where it expected a list. An object argument never
         * takes that path.
         *
         * @since 0.6.0
         *
         * @param MarginPageResult $result The page about to be drawn, and what
         *                                 the window came to.
         */
        do_action( 'dfxcaaw_margin_rows', $result );
        $this->table->set_page( $result->margins, $result->total );
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
        $this->render_failed_days_notice();
        $this->render_coverage_notice( $result );
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
     * Name the days whose figures could not be built.
     *
     * A day that failed and a day on which no coupon was used look identical
     * here — both are simply absent from the table. Recording a failure and
     * never saying so is the same as forgetting it, and a shop would read the
     * gap as a quiet week rather than as missing data.
     *
     * Said plainly and without a remedy attached, because there is nothing for
     * the reader to do: the days are retried on their own, and again whenever a
     * new version of this plugin arrives.
     */
    private function render_failed_days_notice() : void {
        $days = $this->aggregator->failed_days();
        if ( array() === $days ) {
            return;
        }
        printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( sprintf( 
            /* translators: 1: how many days, 2: the earliest of them. */
            _n(
                'Figures for %1$d day could not be built, the earliest being %2$s. It will be tried again.',
                'Figures for %1$d days could not be built, the earliest being %2$s. They will be tried again.',
                count( $days ),
                'coupon-audit-and-analytics-for-woocommerce'
            ),
            count( $days ),
            $days[0]
         ) ) );
    }

    /**
     * Explain an empty or partial table, which is the common case.
     *
     * Decided from the window rather than from the twenty rows on screen. A
     * notice saying that none of these orders has a cost recorded must not
     * become true on page three and false on page four.
     *
     * @param MarginPageResult $result The page, and what the window came to.
     */
    private function render_coverage_notice( MarginPageResult $result ) : void {
        if ( 0 === $result->total ) {
            return;
        }
        if ( 0 === $result->with_cost ) {
            printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'No margin can be shown yet, because none of these orders has a cost recorded against it. Set product costs in WooCommerce, or in whichever cost-of-goods plugin your shop already uses, and the figures will appear.', 'coupon-audit-and-analytics-for-woocommerce' ) );
            return;
        }
        if ( !$result->cost_is_complete() ) {
            printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html__( 'Some coupons have no cost recorded against their orders, so no margin is shown for them. Margins marked as an estimate cover only part of their lines.', 'coupon-audit-and-analytics-for-woocommerce' ) );
        }
    }

}
