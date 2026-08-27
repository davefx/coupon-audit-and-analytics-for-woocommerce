<?php
/**
 * Inventory orchestration.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Service;

use DFX\CouponAAW\Catalog\CatalogRepositoryInterface;
use Generator;
use DFX\CouponAAW\Domain\Coupon\ConfigurationAuditor;
use DFX\CouponAAW\Domain\Coupon\CouponFilter;
use DFX\CouponAAW\Domain\Coupon\CouponId;
use DFX\CouponAAW\Domain\Coupon\CouponProjection;
use DFX\CouponAAW\Domain\Coupon\OrphanReason;
use DFX\CouponAAW\Domain\Coupon\OrphanDetector;
use DFX\CouponAAW\Domain\Coupon\StatusResolver;
use DFX\CouponAAW\Domain\Coupon\CouponSnapshot;
use DFX\CouponAAW\Domain\Overlap\Overlap;
use DFX\CouponAAW\Domain\Profit\Money;
use DFX\CouponAAW\Domain\Coupon\CouponStatus;
use DFX\CouponAAW\Domain\Overlap\OverlapDetector;
use DFX\CouponAAW\Repository\CouponRepositoryInterface;

/**
 * Builds the coupon inventory.
 *
 * Orchestration only: it coordinates the repository and the domain and
 * calculates nothing itself (§5). It holds no state between calls, so two
 * builds either side of a write see the world either side of that write.
 *
 * Everything it needs from the catalogue is fetched once, before the loop. The
 * loop itself issues no queries at all, which is what keeps the cost of the
 * page flat as a shop's coupon list grows.
 */
final class InventoryService {

	/**
	 * How many coupons an export builds at a time.
	 *
	 * Large enough that the catalogue is priced in useful batches, small enough
	 * that the shop is never in memory at once. The screen's twenty would make
	 * an export of twenty-six thousand coupons thirteen hundred catalogue reads.
	 */
	public const STREAM_CHUNK = 250;

	/**
	 * Constructor.
	 *
	 * @param CouponRepositoryInterface  $coupons  Source of coupons.
	 * @param StatusResolver             $status   Resolves each coupon's status.
	 * @param OrphanDetector             $orphans  Judges each coupon against the rest.
	 * @param OverlapDetector            $overlaps Finds colliding pairs.
	 * @param ConfigurationAuditor       $auditor  Checks each coupon's own terms.
	 * @param CatalogRepositoryInterface $catalog Supplies the cheapest reachable price.
	 */
	public function __construct(
		private readonly CouponRepositoryInterface $coupons,
		private readonly StatusResolver $status,
		private readonly OrphanDetector $orphans,
		private readonly OverlapDetector $overlaps,
		private readonly ConfigurationAuditor $auditor,
		private readonly CatalogRepositoryInterface $catalog
	) {}

	/**
	 * The figures above the table, without loading the table.
	 *
	 * The tiles describe the whole shop rather than the page in front of you,
	 * which is what used to force every coupon into memory on every request —
	 * about a second and 27 MB per thousand, so a shop with twenty-six thousand
	 * could not open the screen at all.
	 *
	 * Every figure on them is decided from scalars: the status counts, the
	 * "needs attention" tally, and how many coupons apply to the whole
	 * catalogue. Overlaps are the exception and were already capped, so they are
	 * counted the same way as before or not at all.
	 */
	public function summary(): InventorySummary {
		$coupons = $this->coupons->project();

		$overlaps = count( $coupons ) > OverlapDetector::SYNCHRONOUS_LIMIT
			? null
			: count( $this->overlaps->detect( $this->coupons->all() ) );

		return $this->summarise( $coupons, $this->orphans->reasons_for_all( $coupons ), $overlaps );
	}

	/**
	 * One page of the audit screen.
	 *
	 * This is the read the screen actually makes. Narrowing and ordering happen
	 * on projections — scalars, a few hundred bytes each — and only the coupons
	 * that survive as far as the visible page are built in full. At the twenty
	 * or so rows a page shows, that is the difference between a gigabyte and a
	 * few megabytes.
	 *
	 * The summary is still the whole shop's, not the page's, and deliberately:
	 * tiles that changed as you turned the page would be answering a question
	 * nobody asked.
	 *
	 * @param CouponFilter   $filter   Which coupons are being asked for.
	 * @param InventoryOrder $order    How to sort them.
	 * @param int            $page     Which page, counting from one.
	 * @param int            $per_page How many rows a page holds.
	 */
	public function screen( CouponFilter $filter, InventoryOrder $order, int $page, int $per_page ): InventoryScreen {
		$context = $this->judge( $filter, $order );

		$visible = array_slice(
			$context['matching'],
			max( 0, $page - 1 ) * $per_page,
			$per_page
		);

		return new InventoryScreen(
			$this->summarise(
				$context['projections'],
				$context['orphans'],
				null === $context['overlaps'] ? null : count( $context['overlaps'] )
			),
			$this->rows_for( $visible, $context ),
			count( $context['matching'] ),
			null !== $context['overlaps']
		);
	}

	/**
	 * Every matching coupon, a chunk at a time.
	 *
	 * What the export wants. It asks the same question the screen asks and gets
	 * the same answer in the same order — the difference is only how much is in
	 * memory at once, which is a chunk rather than the shop.
	 *
	 * The expensive part of a screen is not deciding *which* coupons but
	 * building them, so that is what is chunked. Judging the shop happens once,
	 * here, and not once per chunk.
	 *
	 * @param CouponFilter   $filter Which coupons are being asked for.
	 * @param InventoryOrder $order  How to sort them.
	 * @param int            $chunk  How many to build at a time.
	 *
	 * @return Generator<int, InventoryEntry>
	 */
	public function stream( CouponFilter $filter, InventoryOrder $order, int $chunk = self::STREAM_CHUNK ): Generator {
		$context = $this->judge( $filter, $order );

		foreach ( array_chunk( $context['matching'], max( 1, $chunk ) ) as $slice ) {
			foreach ( $this->rows_for( $slice, $context ) as $entry ) {
				yield $entry;
			}
		}
	}

	/**
	 * Read the shop, judge it, and narrow it to what was asked for.
	 *
	 * Shared by the screen and the export so that the two cannot disagree about
	 * what matches or in what order — which is the only way a report and the
	 * page it was exported from can be trusted to be the same thing.
	 *
	 * @param CouponFilter   $filter Which coupons are being asked for.
	 * @param InventoryOrder $order  How to sort them.
	 *
	 * @return array{projections: list<CouponProjection>, matching: list<CouponProjection>, orphans: array<int, list<OrphanReason>>, loaded: list<CouponSnapshot>|null, overlaps: list<Overlap>|null}
	 */
	private function judge( CouponFilter $filter, InventoryOrder $order ): array {
		$projections = $this->coupons->project();
		$orphans     = $this->orphans->reasons_for_all( $projections );

		/*
		 * Overlap is pairwise, so it needs every coupon and a projection does
		 * not make it cheaper. It was already capped for exactly that reason;
		 * above the cap the tile says so and nothing is loaded.
		 */
		$loaded   = count( $projections ) > OverlapDetector::SYNCHRONOUS_LIMIT
			? null
			: $this->coupons->all();
		$overlaps = null === $loaded ? null : $this->overlaps->detect( $loaded );

		$matching = $filter->is_empty()
			? $projections
			: array_values(
				array_filter(
					$projections,
					fn ( CouponProjection $coupon ): bool => $filter->matches(
						$coupon,
						$this->status->resolve( $coupon ),
						$orphans[ $coupon->id->value ] ?? array()
					)
				)
			);

		return array(
			'projections' => $projections,
			'matching'    => $this->sorted( $matching, $order ),
			'orphans'     => $orphans,
			'loaded'      => $loaded,
			'overlaps'    => $overlaps,
		);
	}

	/**
	 * Build the rows for a run of projections already chosen.
	 *
	 * Below the overlap cap every coupon is already in hand, so the rows are
	 * picked out of what was loaded rather than read again. Above it, only the
	 * ones being drawn are ever read.
	 *
	 * @param list<CouponProjection>                                                                                                                                                                 $chosen  The coupons to build.
	 * @param array{projections: list<CouponProjection>, matching: list<CouponProjection>, orphans: array<int, list<OrphanReason>>, loaded: list<CouponSnapshot>|null, overlaps: list<Overlap>|null} $context What the shop was judged to be.
	 *
	 * @return list<InventoryEntry>
	 */
	private function rows_for( array $chosen, array $context ): array {
		$wanted = array_values( array_map( static fn ( CouponProjection $coupon ): CouponId => $coupon->id, $chosen ) );

		$snapshots = null === $context['loaded']
			? $this->coupons->some( $wanted )
			: $this->pick( $context['loaded'], $wanted );

		return $this->entries_for( $snapshots, $context['orphans'], $context['overlaps'] ?? array() );
	}

	/**
	 * Reduce a set of coupons to the figures above the table.
	 *
	 * Takes `Judgeable`s rather than snapshots so that the cheap path and the
	 * expensive one cannot count differently.
	 *
	 * @param list<CouponProjection>         $coupons  The coupons to count.
	 * @param array<int, list<OrphanReason>> $orphans  What was found against each.
	 * @param int|null                       $overlaps How many collisions, or null when not checked.
	 */
	private function summarise( array $coupons, array $orphans, ?int $overlaps ): InventorySummary {
		// Seeded with every status rather than filled in afterwards, so that a
		// screen can show a zero rather than a gap *and* the order of the map
		// is the enum's rather than whichever status the database happened to
		// return first. Two summaries of the same shop must be the same value.
		$by_status = array();

		foreach ( CouponStatus::cases() as $status ) {
			$by_status[ $status->value ] = 0;
		}
		$orphan_count = 0;
		$unrestricted = 0;

		foreach ( $coupons as $coupon ) {
			$status = $this->status->resolve( $coupon );
			$key    = $status->value;

			++$by_status[ $key ];

			if ( array() !== ( $orphans[ $coupon->id()->value ] ?? array() ) ) {
				++$orphan_count;
			}

			if ( $status->is_usable() && $coupon->is_universal() ) {
				++$unrestricted;
			}
		}

		return new InventorySummary(
			count( $coupons ),
			$by_status,
			$orphan_count,
			$unrestricted,
			$overlaps
		);
	}

	/**
	 * Put projections in the order the screen asked for.
	 *
	 * Status is resolved once per coupon and sorted on afterwards rather than
	 * inside the comparison, which would resolve it a few times over for every
	 * coupon in the shop.
	 *
	 * @param list<CouponProjection> $coupons The coupons to order.
	 * @param InventoryOrder         $order   The order asked for.
	 *
	 * @return list<CouponProjection>
	 */
	private function sorted( array $coupons, InventoryOrder $order ): array {
		$direction = $order->direction();

		if ( InventoryOrder::BY_STATUS === $order->by ) {
			$keyed = array();

			foreach ( $coupons as $coupon ) {
				$keyed[] = array( $this->status->resolve( $coupon )->value, $coupon );
			}

			usort(
				$keyed,
				static fn ( array $a, array $b ): int => strcmp( $a[0], $b[0] ) * $direction
			);

			return array_values( array_map( static fn ( array $pair ): CouponProjection => $pair[1], $keyed ) );
		}

		if ( InventoryOrder::BY_CREATED === $order->by ) {
			usort(
				$coupons,
				static fn ( CouponProjection $a, CouponProjection $b ): int => (
					$a->created_at->getTimestamp() <=> $b->created_at->getTimestamp()
				) * $direction
			);

			return $coupons;
		}

		if ( InventoryOrder::BY_LAST_USED === $order->by ) {
			// PHP_INT_MIN, not PHP_INT_MAX. A coupon nobody has ever redeemed is
			// the stalest thing in the shop, not the freshest — see the note on
			// the constant, which is where the difference from expiry is argued.
			usort(
				$coupons,
				static fn ( CouponProjection $a, CouponProjection $b ): int => (
					( $a->last_used_at?->getTimestamp() ?? PHP_INT_MIN )
						<=> ( $b->last_used_at?->getTimestamp() ?? PHP_INT_MIN )
				) * $direction
			);

			return $coupons;
		}

		if ( InventoryOrder::BY_EXPIRES === $order->by ) {
			usort(
				$coupons,
				static fn ( CouponProjection $a, CouponProjection $b ): int => (
					( $a->expires_at?->getTimestamp() ?? PHP_INT_MAX ) <=> ( $b->expires_at?->getTimestamp() ?? PHP_INT_MAX )
				) * $direction
			);

			return $coupons;
		}

		usort(
			$coupons,
			static fn ( CouponProjection $a, CouponProjection $b ): int => strcmp( $a->code, $b->code ) * $direction
		);

		return $coupons;
	}

	/**
	 * Take the named coupons out of a set already in memory.
	 *
	 * @param list<CouponSnapshot> $coupons The coupons already loaded.
	 * @param list<CouponId>       $wanted  Which of them the page shows, in order.
	 *
	 * @return list<CouponSnapshot>
	 */
	private function pick( array $coupons, array $wanted ): array {
		$by_id = array();

		foreach ( $coupons as $coupon ) {
			$by_id[ $coupon->id->value ] = $coupon;
		}

		$picked = array();

		foreach ( $wanted as $id ) {
			if ( isset( $by_id[ $id->value ] ) ) {
				$picked[] = $by_id[ $id->value ];
			}
		}

		return $picked;
	}

	/**
	 * Every coupon, with what the domain concluded about each.
	 *
	 * The expensive read. Still used where the whole inventory is genuinely
	 * needed — overlap detection, and the pre-publish check — and no longer by
	 * the summary above.
	 */
	public function build(): Inventory {
		$coupons = $this->coupons->all();

		$overlaps = count( $coupons ) > OverlapDetector::SYNCHRONOUS_LIMIT
			? null
			: $this->overlaps->detect( $coupons );

		return new Inventory(
			$this->entries_for( $coupons, $this->orphans->reasons_for_all( $coupons ), $overlaps ?? array() ),
			$overlaps
		);
	}

	/**
	 * Turn coupons into rows.
	 *
	 * Shared by the whole-shop read and the paged one, so a row cannot come out
	 * differently depending on which asked for it. The catalogue is priced in
	 * bulk here, once, for exactly the coupons handed in — which on the paged
	 * path is the twenty on screen rather than the whole shop.
	 *
	 * @param list<CouponSnapshot>           $coupons  The coupons to turn into rows.
	 * @param array<int, list<OrphanReason>> $orphans  What was found against each, judged against the whole shop.
	 * @param list<Overlap>                  $overlaps Every collision found, or none when they were not checked.
	 *
	 * @return list<InventoryEntry>
	 */
	private function entries_for( array $coupons, array $orphans, array $overlaps ): array {
		$pricing   = new ScopePricing( $this->catalog, $coupons );
		$by_coupon = $this->group_by_coupon( $overlaps );
		$entries   = array();

		foreach ( $coupons as $coupon ) {
			$entries[] = new InventoryEntry(
				$coupon,
				$this->status->resolve( $coupon ),
				$orphans[ $coupon->id->value ] ?? array(),
				$by_coupon[ $coupon->id->value ] ?? array(),
				$this->auditor->issues( $coupon, $this->cheapest_for( $coupon, $pricing ) )
			);
		}

		return $entries;
	}

	/**
	 * The cheapest thing a coupon can be applied to.
	 *
	 * A percentage discount cannot exceed what it is applied to, so there is
	 * nothing the catalogue could say that would matter.
	 *
	 * @param CouponSnapshot $coupon  The coupon.
	 * @param ScopePricing   $pricing Prices already fetched for this inventory.
	 */
	private function cheapest_for( CouponSnapshot $coupon, ScopePricing $pricing ): ?Money {
		if ( ! $coupon->terms->amount->is_fixed() ) {
			return null;
		}

		return $pricing->cheapest( $coupon->scope );
	}

	/**
	 * Index the overlaps by each coupon they involve.
	 *
	 * Built once rather than filtered per row, which would be quadratic all over
	 * again in the one place the index was meant to save.
	 *
	 * @param list<Overlap> $overlaps Every collision found.
	 *
	 * @return array<int, list<Overlap>>
	 */
	private function group_by_coupon( array $overlaps ): array {
		$by_coupon = array();

		foreach ( $overlaps as $overlap ) {
			$by_coupon[ $overlap->one->id->value ][]   = $overlap;
			$by_coupon[ $overlap->other->id->value ][] = $overlap;
		}

		return $by_coupon;
	}
}
