<?php

/**
 * Coupon margin list table.
 *
 * @package DFX\CouponAAW
 */
declare (strict_types = 1);
namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Domain\Profit\CostCoverage;
use DFX\CouponAAW\Domain\Profit\CouponMargin;
use WP_List_Table;
/**
 * Shows what each coupon earned, and how much of that is actually known.
 *
 * §6.3's three states are the whole design of this table. A margin computed
 * over incomplete data is never shown as though it were complete, and one
 * computed over no data is not shown at all — a wrong number in a financial
 * dashboard destroys trust far faster than a missing number builds it.
 */
final class MarginListTable extends WP_List_Table {
    /**
     * Coupons shown per page.
     */
    private const PER_PAGE = 20;

    /**
     * The rows to display.
     *
     * @var list<CouponMargin>
     */
    private array $margins = array();

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => 'coupon',
            'plural'   => 'coupons',
            'ajax'     => false,
        ) );
    }

    /**
     * Supply the rows.
     *
     * @param list<CouponMargin> $margins The figures to display.
     */
    public function set_margins( array $margins ) : void {
        $this->margins = $margins;
    }

    /**
     * The table's columns.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        $columns = array(
            'code'     => __( 'Coupon', 'coupon-audit-and-analytics-for-woocommerce' ),
            'orders'   => __( 'Orders', 'coupon-audit-and-analytics-for-woocommerce' ),
            'revenue'  => __( 'Revenue', 'coupon-audit-and-analytics-for-woocommerce' ),
            'discount' => __( 'Given away', 'coupon-audit-and-analytics-for-woocommerce' ),
            'cost'     => __( 'Cost of goods', 'coupon-audit-and-analytics-for-woocommerce' ),
            'margin'   => __( 'Gross margin', 'coupon-audit-and-analytics-for-woocommerce' ),
        );
        return $columns;
    }

    /**
     * Build the rows for the current page.
     */
    public function prepare_items() : void {
        $total = count( $this->margins );
        $page = max( 1, $this->get_pagenum() );
        $offset = ($page - 1) * self::PER_PAGE;
        $this->_column_headers = array($this->get_columns(), array(), array());
        $this->items = array_slice( $this->margins, $offset, self::PER_PAGE );
        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil( $total / self::PER_PAGE ),
        ) );
    }

    /**
     * Shown when there is nothing to report.
     */
    public function no_items() : void {
        esc_html_e( 'No coupon was used in this period.', 'coupon-audit-and-analytics-for-woocommerce' );
    }

    /**
     * Render one cell.
     *
     * @param CouponMargin $item        The row.
     * @param string       $column_name Which column is being rendered.
     *
     * @return string Already-escaped HTML.
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'code':
                return $this->code_cell( $item );
            case 'orders':
                return esc_html( number_format_i18n( $item->orders ) );
            case 'revenue':
                return $this->money( $item->net_revenue->amount, $item->currency() );
            case 'discount':
                return $this->money( $item->discount->amount, $item->currency() );
            case 'cost':
                return $this->cost_cell( $item );
            case 'margin':
                return $this->margin_cell( $item );
            default:
                return '';
        }
    }

    /**
     * The coupon's code, or a note that it has been deleted.
     *
     * @param CouponMargin $margin The row.
     */
    private function code_cell( CouponMargin $margin ) : string {
        if ( null === $margin->code ) {
            return sprintf( '<em>%s</em>', esc_html( sprintf( 
                /* translators: %d: the ID of a coupon that no longer exists. */
                __( 'deleted coupon #%d', 'coupon-audit-and-analytics-for-woocommerce' ),
                $margin->coupon_id->value
             ) ) );
        }
        return sprintf( '<strong>%s</strong>', esc_html( wp_specialchars_decode( $margin->code, ENT_QUOTES ) ) );
    }

    /**
     * Cost of goods, with the covered share where it is not everything.
     *
     * @param CouponMargin $margin The row.
     */
    private function cost_cell( CouponMargin $margin ) : string {
        if ( CostCoverage::NONE === $margin->coverage() ) {
            return sprintf( '<span class="dfxcaaw-unknown">%s</span>', esc_html__( 'Not known', 'coupon-audit-and-analytics-for-woocommerce' ) );
        }
        $cost = $this->money( $margin->cost->amount, $margin->currency() );
        if ( CostCoverage::FULL === $margin->coverage() ) {
            return $cost;
        }
        return $cost . sprintf( '<br /><span class="dfxcaaw-coverage">%s</span>', esc_html( sprintf( 
            /* translators: %d: percentage of order lines whose cost is known. */
            __( 'over %d%% of lines', 'coupon-audit-and-analytics-for-woocommerce' ),
            $margin->coverage_percentage()
         ) ) );
    }

    /**
     * The margin, or an explanation of why there is none.
     *
     * @param CouponMargin $margin The row.
     */
    private function margin_cell( CouponMargin $margin ) : string {
        $value = $margin->margin();
        if ( null === $value ) {
            return sprintf( '<span class="dfxcaaw-unknown">%s</span>', esc_html__( 'Needs cost data', 'coupon-audit-and-analytics-for-woocommerce' ) );
        }
        $share = $margin->margin_percentage();
        $rendered = sprintf( '<strong class="%1$s">%2$s</strong>', ( $value->is_negative() ? 'dfxcaaw-negative' : '' ), $this->money( $value->amount, $margin->currency() ) );
        if ( null !== $share ) {
            $rendered .= sprintf( ' <span class="dfxcaaw-share">%s</span>', esc_html( sprintf( 
                /* translators: %s: margin as a percentage of revenue. */
                __( '(%s%%)', 'coupon-audit-and-analytics-for-woocommerce' ),
                number_format_i18n( $share, 1 )
             ) ) );
        }
        if ( CostCoverage::PARTIAL === $margin->coverage() ) {
            $rendered .= sprintf( '<br /><span class="dfxcaaw-coverage">%s</span>', esc_html__( 'estimate', 'coupon-audit-and-analytics-for-woocommerce' ) );
        }
        return $rendered;
    }

    /**
     * Render an amount held in minor units.
     *
     * @param int    $minor_units The amount.
     * @param string $currency    Its currency.
     */
    private function money( int $minor_units, string $currency ) : string {
        $decimals = wc_get_price_decimals();
        return wp_kses_post( wc_price( $minor_units / 10 ** $decimals, array(
            'currency' => $currency,
        ) ) );
    }

}
