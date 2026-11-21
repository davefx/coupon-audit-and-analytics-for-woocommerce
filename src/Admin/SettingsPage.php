<?php
/**
 * Settings screen.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Admin;

use DFX\CouponAAW\Cost\CostSourceRegistry;
use DFX\CouponAAW\Support\SettingsInterface;

/**
 * The two decisions only the shop owner can make.
 *
 * Which cost system to believe, where more than one is installed — §7 is
 * explicit that the user decides, because only they know which one their
 * bookkeeping actually uses. And whether uninstalling should take the data with
 * it, which §14 requires be opted into rather than assumed.
 */
final class SettingsPage {

	/**
     * @var SettingsInterface
     * @readonly
     */
    private SettingsInterface $settings;
    /**
     * @var CostSourceRegistry
     * @readonly
     */
    private CostSourceRegistry $costs;
    /**
	 * The screen's page slug.
	 */
	public const PAGE_SLUG = 'dfxcaaw-settings';

	/**
	 * The nonce action for saving.
	 */
	private const NONCE = 'dfxcaaw_save_settings';

	/**
	 * The query argument naming the tab on screen.
	 */
	public const TAB_ARG = 'tab';

	/**
	 * The tab listing the author's other plugins.
	 */
	public const TAB_MORE_PLUGINS = 'more-plugins';

	/**
	 * This plugin's own entry in the shared catalog, left out of the list.
	 */
	private const SHOWCASE_ID = 'coupon-audit';

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface  $settings Where choices are stored.
	 * @param CostSourceRegistry $costs    Lists the systems available to choose from.
	 */
	public function __construct(SettingsInterface $settings, CostSourceRegistry $costs)
    {
        $this->settings = $settings;
        $this->costs = $costs;
    }

	/**
	 * Render the screen, saving first if something was submitted.
	 */
	public function render(): void {
		if ( ! current_user_can( InventoryPage::CAPABILITY ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to change these settings.',
					'coupon-audit-and-analytics-for-woocommerce'
				),
				403
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing a tab to show, not acting on a submission.
		$tab = isset( $_GET[ self::TAB_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::TAB_ARG ] ) ) : '';

		echo '<div class="wrap dfxcaaw-settings">';
		printf( '<h1>%s</h1>', esc_html__( 'Coupon Audit Settings', 'coupon-audit-and-analytics-for-woocommerce' ) );

		$this->render_tabs( $tab );

		/*
		 * The other tab only lists the author's other plugins. Nothing is saved
		 * from it, so a submission is only looked at on the settings tab.
		 */
		if ( self::TAB_MORE_PLUGINS === $tab ) {
			if ( function_exists( 'dfx_plugin_showcase_render' ) ) {
				dfx_plugin_showcase_render( array( 'exclude' => self::SHOWCASE_ID ) );
			}

			echo '</div>';

			return;
		}

		$saved = $this->maybe_save();

		if ( $saved ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'coupon-audit-and-analytics-for-woocommerce' )
			);
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_cost_source_row();
		$this->render_uninstall_row();
		echo '</tbody></table>';

		submit_button();
		echo '</form></div>';
	}

	/**
	 * The two tabs: the settings, and the author's other plugins.
	 *
	 * @param string $current The tab on screen.
	 */
	private function render_tabs( string $current ): void {
		// The screen sits under whichever menu WooCommerce keeps Coupons in, so
		// its address is asked of WordPress rather than assumed.
		$base = html_entity_decode( menu_page_url( self::PAGE_SLUG, false ) );
		$base = '' === $base ? admin_url( 'admin.php?page=' . self::PAGE_SLUG ) : $base;
		$tabs = array(
			''                     => __( 'Settings', 'coupon-audit-and-analytics-for-woocommerce' ),
			self::TAB_MORE_PLUGINS => function_exists( 'dfx_plugin_showcase_label' ) ? dfx_plugin_showcase_label() : '',
		);

		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			if ( '' === $label ) {
				continue;
			}

			$active = $slug === $current || ( '' === $slug && self::TAB_MORE_PLUGINS !== $current );

			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( '' === $slug ? $base : add_query_arg( self::TAB_ARG, $slug, $base ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Save the submitted settings, if any were submitted by someone allowed to.
	 *
	 * @return bool Whether anything was saved.
	 */
	private function maybe_save(): bool {
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return false;
		}

		$chosen = isset( $_POST['dfxcaaw_cost_source'] )
			? sanitize_key( wp_unslash( $_POST['dfxcaaw_cost_source'] ) )
			: '';

		// Only a source that actually exists may be stored, so a tampered form
		// cannot leave the plugin pointed at nothing.
		$this->settings->set( 'cost_source', $this->is_known( $chosen ) ? $chosen : '' );

		$this->settings->set(
			'delete_data_on_uninstall',
			isset( $_POST['dfxcaaw_delete_on_uninstall'] )
		);

		return true;
	}

	/**
	 * Whether an identifier names a source this store actually has.
	 *
	 * @param string $identifier The submitted identifier.
	 */
	private function is_known( string $identifier ): bool {
		foreach ( $this->costs->available() as $source ) {
			if ( $identifier === $source->get_identifier() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The cost system chooser.
	 */
	private function render_cost_source_row(): void {
		$available = $this->costs->available();
		$current   = (string) ( $this->settings->get_string( 'cost_source' ) ?? '' );

		echo '<tr><th scope="row">';
		printf(
			'<label for="dfxcaaw_cost_source">%s</label>',
			esc_html__( 'Cost of goods source', 'coupon-audit-and-analytics-for-woocommerce' )
		);
		echo '</th><td>';

		if ( array() === $available ) {
			printf(
				'<p>%s</p>',
				esc_html__(
					'No cost-of-goods system was found. Enable WooCommerce\'s own Cost of Goods feature, or install a cost-of-goods plugin, and margins will start to appear.',
					'coupon-audit-and-analytics-for-woocommerce'
				)
			);
			echo '</td></tr>';

			return;
		}

		echo '<select name="dfxcaaw_cost_source" id="dfxcaaw_cost_source">';
		printf(
			'<option value="" %1$s>%2$s</option>',
			selected( '', $current, false ),
			esc_html__( 'Choose automatically', 'coupon-audit-and-analytics-for-woocommerce' )
		);

		foreach ( $available as $source ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $source->get_identifier() ),
				selected( $source->get_identifier(), $current, false ),
				esc_html( $source->get_label() )
			);
		}

		echo '</select>';

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Figures come from one system only, never a mixture: a margin blended from two sets of books reconciles with neither.',
				'coupon-audit-and-analytics-for-woocommerce'
			)
		);

		$this->render_estimate_warning();

		echo '</td></tr>';
	}

	/**
	 * Warn where the system in use records only a product's current cost.
	 *
	 * Asked of the registry rather than of the submitted value, because the
	 * warning has to describe the source actually being read — which, when the
	 * choice is automatic or names something since deactivated, is not the one
	 * the form field holds.
	 */
	private function render_estimate_warning(): void {
		$active = $this->costs->active();

		if ( null === $active || $active->records_cost_at_sale() ) {
			return;
		}

		printf(
			'<p class="description"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: the name of the cost system in use. */
					__(
						'%s records what a product costs today, not what it cost when each order was placed, so margins built from it are estimates.',
						'coupon-audit-and-analytics-for-woocommerce'
					),
					$active->get_label()
				)
			)
		);
	}

	/**
	 * The data-removal opt-in.
	 */
	private function render_uninstall_row(): void {
		$enabled = (bool) $this->settings->get( 'delete_data_on_uninstall', false );

		echo '<tr><th scope="row">';
		esc_html_e( 'On uninstall', 'coupon-audit-and-analytics-for-woocommerce' );
		echo '</th><td><label for="dfxcaaw_delete_on_uninstall">';

		printf(
			'<input type="checkbox" name="dfxcaaw_delete_on_uninstall" id="dfxcaaw_delete_on_uninstall" value="1" %s /> ',
			checked( $enabled, true, false )
		);

		esc_html_e(
			'Delete this plugin\'s data when it is uninstalled',
			'coupon-audit-and-analytics-for-woocommerce'
		);

		echo '</label>';
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Off by default. The aggregated figures take a while to rebuild, and deactivating a plugin is not the same as agreeing to lose them.',
				'coupon-audit-and-analytics-for-woocommerce'
			)
		);
		echo '</td></tr>';
	}
}
