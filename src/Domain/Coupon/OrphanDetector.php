<?php
/**
 * Orphan detection.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Domain\Coupon;

use DateTimeImmutable;
use DFX\CouponAAW\Domain\Clock\ClockInterface;
use InvalidArgumentException;

/**
 * Finds coupons that are still live but should not be (§8.4).
 *
 * Only an active coupon can be an orphan. A coupon that is already expired,
 * exhausted or unpublished is not a relic needing attention — it is simply off,
 * and listing it would bury the findings that matter.
 */
final class OrphanDetector {

	/**
     * @var StatusResolver
     * @readonly
     */
    private StatusResolver $status;
    /**
     * @var ClockInterface
     * @readonly
     */
    private ClockInterface $clock;
    /**
     * @var int
     * @readonly
     */
    private int $dormant_after_days = 90;
    /**
	 * Characters that separate a campaign from the code that belongs to it.
	 */
	private const CAMPAIGN_SEPARATORS = array( '-', '_' );

	/**
	 * Constructor.
	 *
	 * @param StatusResolver $status             Decides whether a coupon is live at all.
	 * @param ClockInterface $clock              Supplies the current instant.
	 * @param int            $dormant_after_days Days without a redemption before a coupon counts as dormant.
	 *
	 * @throws InvalidArgumentException When the threshold is not a positive number of days.
	 */
	public function __construct(
		StatusResolver $status,
		ClockInterface $clock,
		int $dormant_after_days = 90
	) {
		$this->status = $status;
        $this->clock = $clock;
        $this->dormant_after_days = $dormant_after_days;
        if ( $dormant_after_days < 1 ) {
			throw new InvalidArgumentException( 'The dormancy threshold must be at least one day.' );
		}
	}

	/**
	 * Every ground on which this coupon counts as an orphan.
	 *
	 * The order is stable — expiry, dormancy, campaign — so callers and tests
	 * can compare the result directly.
	 *
	 * @param Judgeable       $coupon    The coupon to judge.
	 * @param list<Judgeable> $inventory The surrounding coupons, needed only to
	 *                                        judge whether a campaign has died. Omit it
	 *                                        and that rule simply does not fire.
	 *
	 * @return list<OrphanReason>
	 */
	public function reasons( Judgeable $coupon, array $inventory = array() ): array {
		return $this->judge( $coupon, $this->index( $inventory ) );
	}

	/**
	 * Judge a whole inventory at once.
	 *
	 * The dead-campaign rule weighs each coupon against every other one, and
	 * asking it coupon by coupon means walking the inventory afresh every time —
	 * a thousand coupons is a million comparisons, and the screen stops loading.
	 * The campaigns are counted once here instead, and each coupon is then judged
	 * against the tally.
	 *
	 * @param list<Judgeable> $inventory Every coupon in the shop.
	 *
	 * @return array<int, list<OrphanReason>> Reasons keyed by coupon ID.
	 */
	public function reasons_for_all( array $inventory ): array {
		$index   = $this->index( $inventory );
		$reasons = array();

		foreach ( $inventory as $coupon ) {
			$reasons[ $coupon->id()->value ] = $this->judge( $coupon, $index );
		}

		return $reasons;
	}

	/**
	 * Judge one coupon against an already-counted inventory.
	 *
	 * @param Judgeable     $coupon The coupon to judge.
	 * @param CampaignIndex $index  The counted inventory.
	 *
	 * @return list<OrphanReason>
	 */
	private function judge( Judgeable $coupon, CampaignIndex $index ): array {
		if ( ! $this->status->resolve( $coupon )->is_usable() ) {
			return array();
		}

		$reasons = array();

		if ( null === $coupon->expires_at() ) {
			$reasons[] = OrphanReason::NO_EXPIRY_DATE();
		}

		if ( $this->is_dormant( $coupon ) ) {
			$reasons[] = OrphanReason::DORMANT();
		}

		if ( $index->every_sibling_expired( $coupon, $this->campaign_of( $coupon->code() ) ) ) {
			$reasons[] = OrphanReason::DEAD_CAMPAIGN();
		}

		return $reasons;
	}

	/**
	 * Count the campaigns in an inventory.
	 *
	 * @param list<Judgeable> $inventory The surrounding coupons.
	 */
	private function index( array $inventory ): CampaignIndex {
		$members = array();
		$expired = array();
		$ids     = array();

		foreach ( $inventory as $candidate ) {
			$ids[ $candidate->id()->value ] = true;

			$campaign = $this->campaign_of( $candidate->code() );

			if ( null === $campaign ) {
				continue;
			}

			$members[ $campaign ] = ( $members[ $campaign ] ?? 0 ) + 1;
			$expired[ $campaign ] = ( $expired[ $campaign ] ?? 0 )
				+ ( CouponStatus::EXPIRED() === $this->status->resolve( $candidate ) ? 1 : 0 );
		}

		return new CampaignIndex( $members, $expired, $ids );
	}

	/**
	 * Whether the coupon is an orphan on any ground at all.
	 *
	 * @param Judgeable       $coupon    The coupon to judge.
	 * @param list<Judgeable> $inventory The surrounding coupons.
	 */
	public function is_orphan( Judgeable $coupon, array $inventory = array() ): bool {
		return array() !== $this->reasons( $coupon, $inventory );
	}

	/**
	 * Whether the coupon has gone untouched for longer than the threshold.
	 *
	 * A coupon that has never been redeemed is measured from its creation
	 * instead, so that one created yesterday is not reported as dormant on the
	 * day the report is most likely to be read.
	 *
	 * @param Judgeable $coupon The coupon to judge.
	 */
	private function is_dormant( Judgeable $coupon ): bool {
		$last_activity = $coupon->last_used_at() ?? $coupon->created_at();

		return $last_activity < $this->dormancy_cutoff();
	}

	/**
	 * The instant before which a lack of activity counts as dormancy.
	 */
	private function dormancy_cutoff(): DateTimeImmutable {
		return $this->clock->now()->modify( sprintf( '-%d days', $this->dormant_after_days ) );
	}

	/**
	 * The campaign a code belongs to, inferred from its prefix.
	 *
	 * A campaign is only recognised where the code carries a separator.
	 * Grouping codes on a shared spelling alone — WELCOME with WELCOME2 —
	 * would invent campaigns nobody ran, and every coupon in the invented
	 * campaign would then be judged against strangers.
	 *
	 * @param string $code The coupon code.
	 */
	private function campaign_of( string $code ): ?string {
		$position = false;

		foreach ( self::CAMPAIGN_SEPARATORS as $separator ) {
			$found = strrpos( $code, $separator );

			if ( false !== $found && ( false === $position || $found > $position ) ) {
				$position = $found;
			}
		}

		if ( false === $position || 0 === $position ) {
			return null;
		}

		return (string) substr( $code, 0, $position );
	}
}
