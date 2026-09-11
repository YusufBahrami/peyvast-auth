<?php
namespace Peyvast\Auth\Infrastructure\WordPress;

defined( 'ABSPATH' ) || exit;

/** Anonymous browser session used to harden guest authentication CSRF and bind challenges. */
final class GuestSession {
	private const COOKIE = 'peyvast_auth_guest';
	private const TTL    = 2 * DAY_IN_SECONDS;
	private static ?string $secret   = null;
	private static ?string $resolved = null;

	public static function boot(): void {
		add_filter( 'nonce_user_logged_out', array( self::class, 'nonce_user' ), 10, 2 );
	}

	public static function ensure(): string {
		// Resolve once per request; a cookie is only written when missing or invalid.
		if ( self::$resolved !== null ) {
			return self::$resolved;
		}
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? trim( (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( ! self::valid_secret( $cookie ) ) {
			$cookie = bin2hex( random_bytes( 32 ) );
			self::set_cookie( $cookie );
		}
		self::$resolved = $cookie;
		return self::$resolved;
	}

	public static function hash(): string {
		$secret = self::ensure();
		return hash_hmac( 'sha256', $secret, self::site_secret() );
	}

	public static function is_bound( string $hash ): bool {
		return $hash !== '' && hash_equals( $hash, self::hash() );
	}

	public static function rotate(): void {
		self::$resolved = null;
		self::set_cookie( bin2hex( random_bytes( 32 ) ) );
	}

	/** nonce_user_logged_out needs an integer; session check stays authoritative. */
	public static function nonce_user( $uid, $action = '' ): int {
		if ( (int) $uid !== 0 || ! in_array( (string) $action, array( 'peyvast_auth', 'wp_rest' ), true ) ) {
			return (int) $uid;
		}
		$hash = hash_hmac( 'sha256', self::ensure() . '|' . (string) $action, self::site_secret() );
		// Keep guest nonce users outside WordPress's positive user-ID space.
		return -1 - (int) ( hexdec( substr( $hash, 0, 8 ) ) & 0x3fffffff );
	}

	private static function valid_secret( string $secret ): bool {
		return (bool) preg_match( '/\A[0-9a-f]{64}\z/D', $secret );
	}

	private static function site_secret(): string {
		if ( self::$secret === null ) {
			self::$secret = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		}
		return self::$secret;
	}

	private static function set_cookie( string $value ): void {
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => time() + self::TTL,
					'path'     => $path,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $value;
	}
}
