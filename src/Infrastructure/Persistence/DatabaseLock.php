<?php
namespace Peyvast\Auth\Infrastructure\Persistence;

use Peyvast\Auth\Infrastructure\WordPress\Installer;

defined( 'ABSPATH' ) || exit;

/** Short-lived DB advisory locks; HMAC-derived names keep identifiers in PHP. */
final class DatabaseLock {
	private string $name;
	private bool $released = false;
	private bool $local;

	/** @var array<string,int> in-process lock expiry map (SQLite fallback). */
	private static array $local_locks = array();
	/** @var array<string,int> reentrancy counter per request-held lock. */
	private static array $held = array();

	private function __construct( string $name, bool $local ) {
		$this->name  = $name;
		$this->local = $local;
	}

	public static function acquire( string $context, int $timeout = 5 ): ?self {
		$timeout = max( 0, min( 30, $timeout ) );
		if ( ! Installer::database_supported() ) {
			return self::acquire_local( $context, $timeout );
		}
		global $wpdb;
		$secret  = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		$name    = 'peyvast_auth_' . substr( hash_hmac( 'sha256', $context, $secret ), 0, 48 );
		$locked  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		if ( (int) $locked !== 1 ) {
			return null;
		}
		return new self( $name, false );
	}

	/** In-process mutex so Studio/SQLite dev sites keep working without GET_LOCK. */
	private static function acquire_local( string $context, int $timeout ): ?self {
		$name     = 'peyvast_auth_' . substr( hash( 'sha256', $context ), 0, 48 );
		$deadline = microtime( true ) + $timeout;
		if ( isset( self::$held[ $name ] ) ) {
			self::$held[ $name ]++;
			return new self( $name, true );
		}
		while ( true ) {
			$now = microtime( true );
			if ( ( self::$local_locks[ $name ] ?? 0 ) <= $now ) {
				self::$local_locks[ $name ] = $now + 60;
				self::$held[ $name ]        = 1;
				return new self( $name, true );
			}
			if ( $now >= $deadline ) {
				return null;
			}
			usleep( 20000 );
		}
	}

	public function release(): void {
		if ( $this->released ) {
			return;
		}
		$this->released = true;
		if ( $this->local ) {
			if ( isset( self::$held[ $this->name ] ) && --self::$held[ $this->name ] <= 0 ) {
				unset( self::$held[ $this->name ], self::$local_locks[ $this->name ] );
			}
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
	}

	public function __destruct() {
		$this->release();
	}
}