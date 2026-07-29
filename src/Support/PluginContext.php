<?php
/**
 * Values that only the main plugin file knows.
 *
 * @package DFX\CouponAAW
 */

declare( strict_types=1 );

namespace DFX\CouponAAW\Support;

/**
 * Immutable carrier for the plugin's own location and identity.
 *
 * The main file is the only place allowed to call `plugin_dir_path()` and
 * friends; it hands the results here, and everything else asks the container
 * for a context. That keeps path handling out of the domain and out of the
 * global constant namespace.
 */
final class PluginContext {

	/**
	 * Constructor.
	 *
	 * Readonly properties rather than a readonly class, which is PHP 8.2 and
	 * would break the 8.1 floor set in §2.
	 *
	 * @param string $version   Plugin version, as declared in the file header.
	 * @param string $main_file Absolute path to the main plugin file.
	 * @param string $directory Absolute path to the plugin directory.
	 * @param string $base_url  Public URL of the plugin directory.
	 * @param string $slug      Plugin slug, which is also the text domain (§12).
	 */
	public function __construct(
		public readonly string $version,
		public readonly string $main_file,
		public readonly string $directory,
		public readonly string $base_url,
		public readonly string $slug
	) {}

	/**
	 * Absolute path to a file inside the plugin.
	 *
	 * @param string $relative Path relative to the plugin root. Empty for the root itself.
	 */
	public function path( string $relative = '' ): string {
		return $this->join( $this->directory, $relative );
	}

	/**
	 * Public URL of a file inside the plugin.
	 *
	 * @param string $relative Path relative to the plugin root. Empty for the root itself.
	 */
	public function url( string $relative = '' ): string {
		return $this->join( $this->base_url, $relative );
	}

	/**
	 * Join a root and a relative part with exactly one slash between them.
	 *
	 * @param string $root     Directory or URL, with or without a trailing slash.
	 * @param string $relative Relative part, with or without a leading slash.
	 */
	private function join( string $root, string $relative ): string {
		return rtrim( $root, '/' ) . '/' . ltrim( $relative, '/' );
	}
}
