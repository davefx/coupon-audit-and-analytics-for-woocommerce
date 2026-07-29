<?php
/**
 * Third-party coupon plugin support.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Integration;

/**
 * Support for another plugin that changes what a coupon means.
 *
 * WooCommerce's coupon is a small thing that many plugins extend, and an audit
 * that ignores those extensions gives confident answers about half the truth. An
 * integration teaches the audit about one of them.
 *
 * Each is verified against the plugin's own source before being written, the
 * same discipline the cost adapters follow, and each stays inert unless that
 * plugin is actually running.
 */
interface IntegrationInterface {

	/**
	 * Whether the plugin this supports is present and active.
	 */
	public function is_active(): bool;

	/**
	 * Stable machine name, for settings and for saying what is in play.
	 */
	public function get_identifier(): string;

	/**
	 * Human-readable name of the plugin being supported.
	 */
	public function get_label(): string;

	/**
	 * Attach whatever hooks this integration needs.
	 *
	 * Called only when `is_active()` is true.
	 */
	public function register(): void;
}
