<?php
/**
 * Admin asset loading.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Support\PluginContext;

/**
 * Loads the screen's stylesheet, and only on that screen.
 *
 * A plugin that enqueues its assets everywhere in wp-admin slows down every
 * other plugin's screens, which is the sort of thing that gets a plugin
 * uninstalled without a bug report.
 */
final class AssetLoader {

	/**
	 * Handle for the inventory stylesheet.
	 */
	private const STYLE_HANDLE = 'dfxcaaw-inventory-page';

	/**
	 * Constructor.
	 *
	 * @param PluginContext $context Supplies the plugin's URL and version.
	 */
	public function __construct( private readonly PluginContext $context ) {}

	/**
	 * Enqueue assets when the current screen is ours.
	 *
	 * @param string $hook_suffix The screen currently being loaded.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_inventory_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$this->context->url( 'assets/css/inventory.css' ),
			array(),
			$this->context->version
		);
	}

	/**
	 * Whether the given screen is the inventory page.
	 *
	 * The hook suffix WordPress generates for a submenu page depends on the
	 * parent menu's own slug, so it is matched by suffix rather than spelled out.
	 *
	 * @param string $hook_suffix The screen currently being loaded.
	 */
	private function is_inventory_screen( string $hook_suffix ): bool {
		return str_ends_with( $hook_suffix, '_page_' . MenuRegistrar::PAGE_SLUG );
	}
}
