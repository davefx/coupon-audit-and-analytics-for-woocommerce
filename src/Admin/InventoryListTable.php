<?php

/**
 * Coupon inventory list table.
 *
 * @package DFX\CouponAAW
 */
declare (strict_types = 1);
namespace DFX\CouponAAW\Admin;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Coupon\ConfigurationIssue;
use DFX\CouponAAW\Domain\Coupon\CouponFilter;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Overlap\OverlapSeverity;
use DFX\CouponAAW\Service\InventoryEntry;
use DFX\CouponAAW\Service\InventorySummary;
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
     *
     * Public because the screen has to know it before the table does: it is the
     * screen that asks the service for a page, and the table is handed the
     * result.
     */
    public const PER_PAGE = 20;

    /**
     * The rows to display, handed in by the page.
     *
     * @var list<InventoryEntry>
     */
    private array $entries = array();

    /**
     * How many rows matched the filter, of which this page shows some.
     *
     * @var int
     */
    private int $total = 0;

    /**
     * What is true of the whole shop, for the views and their counts.
     *
     * @var InventorySummary|null
     */
    private ?InventorySummary $summary = null;

    /**
     * Constructor.
     *
     * @param CouponTermsFormatter $formatter Puts terms and scope into words.
     * @param CouponFilter         $filter    Which coupons the rows were narrowed to.
     */
    public function __construct( private readonly CouponTermsFormatter $formatter, private CouponFilter $filter = new CouponFilter() ) {
        parent::__construct( array(
            'singular' => 'coupon',
            'plural'   => 'coupons',
            'ajax'     => false,
        ) );
    }

    /**
     * The table's columns.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        $columns = array(
            'code'      => __( 'Code', 'coupon-audit-and-analytics-for-woocommerce' ),
            'status'    => __( 'Status', 'coupon-audit-and-analytics-for-woocommerce' ),
            'discount'  => __( 'Discount', 'coupon-audit-and-analytics-for-woocommerce' ),
            'spend'     => __( 'Basket', 'coupon-audit-and-analytics-for-woocommerce' ),
            'scope'     => __( 'Applies to', 'coupon-audit-and-analytics-for-woocommerce' ),
            'terms'     => __( 'Terms', 'coupon-audit-and-analytics-for-woocommerce' ),
            'expires'   => __( 'Expires', 'coupon-audit-and-analytics-for-woocommerce' ),
            'created'   => __( 'Created', 'coupon-audit-and-analytics-for-woocommerce' ),
            'usage'     => __( 'Used', 'coupon-audit-and-analytics-for-woocommerce' ),
            'last_used' => __( 'Last used', 'coupon-audit-and-analytics-for-woocommerce' ),
            'findings'  => __( 'Findings', 'coupon-audit-and-analytics-for-woocommerce' ),
        );
        /**
         * Filters the columns of the coupon audit table.
         *
         * A plugin adding a column here should also answer
         * `dfxcaaw_inventory_cell` for it.
         *
         * @since 0.2.0
         *
         * @param array<string, string> $columns Column keys mapped to their headings.
         */
        return apply_filters( 'dfxcaaw_inventory_columns', $columns );
    }

    /**
     * Columns the user can sort by.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public function get_sortable_columns() {
        return array(
            'code'      => array('code', false),
            'status'    => array('status', false),
            'expires'   => array('expires', false),
            'created'   => array('created', false),
            'last_used' => array('last_used', false),
        );
    }

    /**
     * Supply the rows of one page, and how many rows there are altogether.
     *
     * The table is given its data rather than fetching it, so that the screen
     * reads the inventory exactly once and this class keeps no logic (§5). It
     * used to be given every coupon and to sort, count and slice them itself,
     * which cannot survive a shop with twenty-six thousand of them. Narrowing,
     * ordering and paging all happen in the service now, and the table is handed
     * the answer.
     *
     * The summary comes with them because the views describe the shop rather
     * than the page — the same numbers the tiles show, from the same object, so
     * the screen cannot say one thing above the table and another beside a link.
     *
     * @param list<InventoryEntry> $entries The rows to draw.
     * @param int                  $total   How many rows the filter matched.
     * @param InventorySummary     $summary What is true of the whole shop.
     */
    public function set_page( array $entries, int $total, InventorySummary $summary ) : void {
        $this->entries = $entries;
        $this->total = $total;
        $this->summary = $summary;
    }

    /**
     * The links above the table, and what each of them would show.
     *
     * This is where the screen admits that it starts on a subset. A screen that
     * quietly shows the coupons in force is, to the person reading it, a screen
     * that has lost the rest — so the default is named, marked as current, and
     * sits next to the link that undoes it.
     *
     * "Needs attention" and "Applies to everything" are here because their tiles
     * were a dead end: a shop was told that four hundred coupons needed looking
     * at and given no way to see which four hundred.
     *
     * @return array<string, string>
     */
    public function get_views() {
        if ( null === $this->summary ) {
            return array();
        }
        $views = array(
            'in-force'     => $this->view(
                __( 'In force', 'coupon-audit-and-analytics-for-woocommerce' ),
                $this->summary->of( CouponStatus::ACTIVE ),
                array(),
                null === $this->filter->finding && array(CouponStatus::ACTIVE) === $this->filter->statuses
            ),
            'scheduled'    => $this->view(
                __( 'Scheduled', 'coupon-audit-and-analytics-for-woocommerce' ),
                $this->summary->of( CouponStatus::SCHEDULED ),
                array(
                    InventoryFilterRequest::STATUS_ARG => CouponStatus::SCHEDULED->value,
                ),
                null === $this->filter->finding && array(CouponStatus::SCHEDULED) === $this->filter->statuses
            ),
            'attention'    => $this->view(
                __( 'Needs attention', 'coupon-audit-and-analytics-for-woocommerce' ),
                $this->summary->orphans,
                array(
                    InventoryFilterRequest::FINDING_ARG => CouponFilter::FINDING_ATTENTION,
                    InventoryFilterRequest::STATUS_ARG  => InventoryFilterRequest::STATUS_ALL,
                ),
                CouponFilter::FINDING_ATTENTION === $this->filter->finding
            ),
            'unrestricted' => $this->view(
                __( 'Applies to everything', 'coupon-audit-and-analytics-for-woocommerce' ),
                $this->summary->unrestricted,
                array(
                    InventoryFilterRequest::FINDING_ARG => CouponFilter::FINDING_UNRESTRICTED,
                    InventoryFilterRequest::STATUS_ARG  => InventoryFilterRequest::STATUS_ALL,
                ),
                CouponFilter::FINDING_UNRESTRICTED === $this->filter->finding
            ),
            'all'          => $this->view(
                __( 'All', 'coupon-audit-and-analytics-for-woocommerce' ),
                $this->summary->total,
                array(
                    InventoryFilterRequest::STATUS_ARG => InventoryFilterRequest::STATUS_ALL,
                ),
                null === $this->filter->finding && null === $this->filter->statuses
            ),
        );
        /**
         * Filters the views offered above the coupon audit table.
         *
         * @since 0.6.0
         *
         * @param array<string, string> $views Keys mapped to their rendered links.
         */
        return apply_filters( 'dfxcaaw_inventory_views', $views );
    }

    /**
     * One view link, with the shop's count beside it.
     *
     * The count is the shop's and never the page's. A link reading "Needs
     * attention (20)" on a screen showing twenty rows of a shop with four
     * hundred would be describing the page it is already on.
     *
     * @param string                $label   What to call it.
     * @param int                   $count   How many coupons it would show.
     * @param array<string, string> $args    The query arguments that select it.
     * @param bool                  $current Whether it is the view in force.
     */
    private function view(
        string $label,
        int $count,
        array $args,
        bool $current
    ) : string {
        $url = add_query_arg( array_merge( array(
            'page' => MenuRegistrar::PAGE_SLUG,
        ), $args ), admin_url( 'admin.php' ) );
        return sprintf(
            '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
            esc_url( $url ),
            ( $current ? ' class="current" aria-current="page"' : '' ),
            esc_html( $label ),
            esc_html( number_format_i18n( $count ) )
        );
    }

    /**
     * Tell the table which filter produced the entries it was given.
     *
     * It does not do the filtering — it is handed the result — but it draws the
     * controls and says what an empty table means, and both need to know.
     *
     * @param CouponFilter $filter The filter in force.
     */
    public function set_filter( CouponFilter $filter ) : void {
        $this->filter = $filter;
    }

    /**
     * Build the rows for the current page.
     */
    public function prepare_items() : void {
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());
        $this->items = $this->entries;
        $this->set_pagination_args( array(
            'total_items' => $this->total,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil( $this->total / self::PER_PAGE ),
        ) );
    }

    /**
     * Shown when there is nothing to list.
     *
     * A filtered screen says so. Repeating "no coupons found" at somebody who has
     * just narrowed the view reads as though the coupons have gone.
     */
    public function no_items() : void {
        // Judged on the shop rather than on the filter. Since the screen starts
        // on the coupons in force, the filter is never empty and could no longer
        // tell "you have no coupons" from "none of them matched" — and telling
        // somebody their filters matched nothing when they have no coupons at
        // all sends them looking for a filter to clear.
        if ( null === $this->summary || 0 === $this->summary->total ) {
            esc_html_e( 'No coupons found.', 'coupon-audit-and-analytics-for-woocommerce' );
            return;
        }
        esc_html_e( 'No coupons match these filters.', 'coupon-audit-and-analytics-for-woocommerce' );
    }

    /**
     * The filter controls, above the table.
     *
     * @param string $which Which end of the table this is.
     */
    protected function extra_tablenav( $which ) : void {
        if ( 'top' !== $which ) {
            return;
        }
        echo '<div class="alignleft actions">';
        $this->render_select(
            InventoryFilterRequest::TYPE_ARG,
            __( 'All discount types', 'coupon-audit-and-analytics-for-woocommerce' ),
            InventoryFilterRequest::discount_types(),
            (string) $this->filter->discount_type
        );
        $this->render_select(
            InventoryFilterRequest::EXPIRY_ARG,
            __( 'Expiry: any', 'coupon-audit-and-analytics-for-woocommerce' ),
            array(
                InventoryFilterRequest::EXPIRY_WITH    => __( 'Expires', 'coupon-audit-and-analytics-for-woocommerce' ),
                InventoryFilterRequest::EXPIRY_WITHOUT => __( 'Never expires', 'coupon-audit-and-analytics-for-woocommerce' ),
            ),
            $this->expiry_choice()
        );
        submit_button(
            __( 'Filter', 'coupon-audit-and-analytics-for-woocommerce' ),
            '',
            'filter_action',
            false
        );
        echo '</div>';
    }

    /**
     * One filter dropdown.
     *
     * @param string                $name    The query argument it sets.
     * @param string                $any     The label for choosing nothing.
     * @param array<string, string> $options Value to label.
     * @param string                $current What is chosen now.
     */
    private function render_select(
        string $name,
        string $any,
        array $options,
        string $current
    ) : void {
        printf( '<label class="screen-reader-text" for="%1$s">%2$s</label>', esc_attr( $name ), esc_html( $any ) );
        printf( '<select name="%1$s" id="%1$s">', esc_attr( $name ) );
        printf( '<option value="">%s</option>', esc_html( $any ) );
        foreach ( $options as $value => $label ) {
            printf(
                '<option value=\'%1$s\'%2$s>%3$s</option>',
                esc_attr( (string) $value ),
                selected( (string) $value, $current, false ),
                esc_html( (string) $label )
            );
        }
        echo '</select>';
    }

    /**
     * The expiry choice as the query argument spells it.
     */
    private function expiry_choice() : string {
        if ( null === $this->filter->has_expiry ) {
            return '';
        }
        return ( $this->filter->has_expiry ? InventoryFilterRequest::EXPIRY_WITH : InventoryFilterRequest::EXPIRY_WITHOUT );
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
                return sprintf( '<span class="dfxcaaw-status dfxcaaw-status--%1$s">%2$s</span>', esc_attr( $item->status->value ), esc_html( self::status_label( $item->status ) ) );
            case 'discount':
                return $this->formatter->amount( $item->coupon->terms );
            case 'spend':
                return $this->formatter->required_spend( $item->coupon->terms );
            case 'scope':
                return $this->formatter->scope( $item->coupon->scope );
            case 'terms':
                return $this->formatter->flags( $item->coupon->terms );
            case 'expires':
                return $this->expiry_cell( $item );
            case 'created':
                return $this->date_cell( $item->coupon->created_at );
            case 'last_used':
                return $this->date_cell( $item->coupon->last_used_at );
            case 'usage':
                return $this->formatter->usage( $item->coupon->usage_count, $item->coupon->usage_limit, $item->coupon->terms );
            case 'findings':
                return $this->findings_cell( $item );
            default:
                /**
                 * Filters the contents of a coupon audit cell.
                 *
                 * How a plugin fills a column it added through
                 * `dfxcaaw_inventory_columns`. The value is printed as markup, so
                 * whatever answers this filter is responsible for escaping it.
                 *
                 * @since 0.2.0
                 *
                 * @param string         $content Cell contents; empty by default.
                 * @param string         $column  Which column is being rendered.
                 * @param InventoryEntry $entry   The coupon and its findings.
                 */
                return (string) apply_filters(
                    'dfxcaaw_inventory_cell',
                    '',
                    $column_name,
                    $item
                );
        }
    }

    /**
     * The code, linked to the coupon editor where the user can act on it.
     *
     * @param InventoryEntry $entry The row.
     */
    private function code_cell( InventoryEntry $entry ) : string {
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
    private function expiry_cell( InventoryEntry $entry ) : string {
        return $this->date_cell( $entry->coupon->expires_at );
    }

    /**
     * A date in the shop's own format, or a note that there is not one.
     *
     * "Never" rather than an empty cell. An empty cell reads as data that failed
     * to load; on this screen an absent date is a fact, and usually the
     * interesting one — a coupon nobody has ever redeemed, or one that will
     * never stop working.
     *
     * @param DateTimeImmutable|null $date The date, or null when there is none.
     */
    private function date_cell( ?DateTimeImmutable $date ) : string {
        if ( null === $date ) {
            return sprintf( '<em>%s</em>', esc_html__( 'Never', 'coupon-audit-and-analytics-for-woocommerce' ) );
        }
        $formatted = wp_date( (string) get_option( 'date_format' ), $date->getTimestamp() );
        return esc_html( ( false === $formatted ? '' : $formatted ) );
    }

    /**
     * Why this coupon was flagged, if it was.
     *
     * @param InventoryEntry $entry The row.
     */
    private function findings_cell( InventoryEntry $entry ) : string {
        $labels = array_map( static fn( OrphanReason $reason ): string => sprintf( '<span class="dfxcaaw-finding">%s</span>', esc_html( self::orphan_label( $reason ) ) ), $entry->orphan_reasons );
        foreach ( $entry->issues as $issue ) {
            $labels[] = sprintf( '<span class="dfxcaaw-finding dfxcaaw-finding--high">%s</span>', esc_html( self::issue_label( $issue ) ) );
        }
        $worst = $entry->worst_overlap();
        if ( null !== $worst ) {
            $labels[] = sprintf( '<span class="dfxcaaw-finding dfxcaaw-finding--%1$s">%2$s</span>', esc_attr( $worst->value ), esc_html( sprintf( 
                /* translators: 1: severity of the worst collision, 2: how many coupons it collides with. */
                _n(
                    'Overlaps %2$d coupon (%1$s)',
                    'Overlaps %2$d coupons (%1$s)',
                    count( $entry->overlaps ),
                    'coupon-audit-and-analytics-for-woocommerce'
                ),
                self::severity_label( $worst ),
                count( $entry->overlaps )
             ) ) );
        }
        if ( array() === $labels ) {
            return '<span class="dfxcaaw-finding-none">&mdash;</span>';
        }
        return implode( ' ', $labels );
    }

    /**
     * The human-readable name of a fault in a coupon's terms.
     *
     * @param ConfigurationIssue $issue The fault to label.
     */
    private static function issue_label( ConfigurationIssue $issue ) : string {
        return match ($issue) {
            ConfigurationIssue::DISCOUNT_EXCEEDS_MINIMUM_SPEND => __( 'Discount exceeds minimum spend', 'coupon-audit-and-analytics-for-woocommerce' ),
            ConfigurationIssue::DISCOUNT_EXCEEDS_PRODUCT_PRICE => __( 'Discount exceeds a product price', 'coupon-audit-and-analytics-for-woocommerce' ),
            ConfigurationIssue::UNBOUNDED_FIXED_DISCOUNT => __( 'Fixed discount with no minimum', 'coupon-audit-and-analytics-for-woocommerce' ),
        };
    }

    /**
     * The human-readable name of an overlap severity.
     *
     * @param OverlapSeverity $severity The severity to label.
     */
    private static function severity_label( OverlapSeverity $severity ) : string {
        return match ($severity) {
            OverlapSeverity::HIGH => __( 'high', 'coupon-audit-and-analytics-for-woocommerce' ),
            OverlapSeverity::MEDIUM => __( 'medium', 'coupon-audit-and-analytics-for-woocommerce' ),
            OverlapSeverity::LOW => __( 'low', 'coupon-audit-and-analytics-for-woocommerce' ),
        };
    }

    /**
     * The human-readable name of a status.
     *
     * The enum deliberately carries no labels: a translated string there would
     * drag WordPress into the domain (§5). Presentation belongs here.
     *
     * @param CouponStatus $status The status to label.
     */
    private static function status_label( CouponStatus $status ) : string {
        return match ($status) {
            CouponStatus::ACTIVE => __( 'Active', 'coupon-audit-and-analytics-for-woocommerce' ),
            CouponStatus::SCHEDULED => __( 'Scheduled', 'coupon-audit-and-analytics-for-woocommerce' ),
            CouponStatus::EXPIRED => __( 'Expired', 'coupon-audit-and-analytics-for-woocommerce' ),
            CouponStatus::EXHAUSTED => __( 'Exhausted', 'coupon-audit-and-analytics-for-woocommerce' ),
            CouponStatus::INACTIVE => __( 'Inactive', 'coupon-audit-and-analytics-for-woocommerce' ),
        };
    }

    /**
     * The human-readable name of an orphan finding.
     *
     * @param OrphanReason $reason The finding to label.
     */
    private static function orphan_label( OrphanReason $reason ) : string {
        return match ($reason) {
            OrphanReason::NO_EXPIRY_DATE => __( 'No expiry date', 'coupon-audit-and-analytics-for-woocommerce' ),
            OrphanReason::DORMANT => __( 'Dormant', 'coupon-audit-and-analytics-for-woocommerce' ),
            OrphanReason::DEAD_CAMPAIGN => __( 'Dead campaign', 'coupon-audit-and-analytics-for-woocommerce' ),
        };
    }

}
