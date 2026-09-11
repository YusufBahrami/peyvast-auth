<?php
namespace Peyvast\Auth\Domain\Security;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Persistence\SecurityStateStore as StateStore;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the general security settings to each authentication scope.
 * Scope-specific factors are internal to SCOPES below, never settings.
 */
final class SecurityPolicy {

	/** ip_multiplier value equal to the 1x baseline. */
	public const BASE_MULTIPLIER = 3;

	public const LIMIT_MIN  = 1;
	public const LIMIT_MAX  = 1000;
	public const WINDOW_MIN = 1;
	public const WINDOW_MAX = 86400;

	public const MULTIPLIER_MIN = 1;
	public const MULTIPLIER_MAX = 10;

	/**
	 * Fixed per-scope factors: base pair, dimension multipliers,
	 * ip_only scopes (reset/Google) and algorithm per scope.
	 */
	private const SCOPES = array(
		Guard::SCOPE_OTP_SEND           => array(
			'base'    => 'request',
			'id'      => 1,
			'ip'      => 6,
			'combo'   => 2,
			'ip_only' => false,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
		Guard::SCOPE_OTP_VERIFY         => array(
			'base'    => 'verify',
			'id'      => 1,
			'ip'      => 6,
			'combo'   => 2,
			'ip_only' => false,
			'algo'    => StateStore::ALGO_FIXED_WINDOW,
		),
		Guard::SCOPE_PASSWORD_LOGIN     => array(
			'base'    => 'request',
			'id'      => 1,
			'ip'      => 4,
			'combo'   => 2,
			'ip_only' => false,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
		Guard::SCOPE_INVALID_IDENTIFIER => array(
			'base'    => 'request',
			'id'      => 1,
			'ip'      => 4,
			'combo'   => 2,
			'ip_only' => false,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
		Guard::SCOPE_REGISTRATION       => array(
			'base'    => 'request',
			'id'      => 2,
			'ip'      => 4,
			'combo'   => 1,
			'ip_only' => false,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
		Guard::SCOPE_PASSWORD_RESET     => array(
			'base'    => 'request',
			'id'      => 1,
			'ip'      => 4,
			'combo'   => 4,
			'ip_only' => true,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
		Guard::SCOPE_GOOGLE             => array(
			'base'    => 'request',
			'id'      => 1,
			'ip'      => 4,
			'combo'   => 4,
			'ip_only' => true,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
		Guard::SCOPE_PROVIDER_TEST      => array(
			'base'    => 'request',
			'id'      => 0,
			'ip'      => 2,
			'combo'   => 0,
			'ip_only' => true,
			'algo'    => StateStore::ALGO_TOKEN_BUCKET,
		),
	);

	public int $request_limit;
	public int $request_window;
	public int $verify_limit;
	public int $verify_window;
	public int $ip_multiplier;
	public int $network_factor;
	public bool $enabled;
	public bool $progressive_enabled;
	public int $initial_duration;
	public int $progressive_multiplier;
	public int $max_duration;
	public int $decay_seconds;

	private function __construct( array $sec ) {
		$this->request_limit          = self::clamp_limit( (int) ( $sec['request_limit'] ?? 5 ) );
		$this->request_window         = self::clamp_window( (int) ( $sec['request_window'] ?? 600 ) );
		$this->verify_limit           = self::clamp_limit( (int) ( $sec['verify_limit'] ?? 5 ) );
		$this->verify_window          = self::clamp_window( (int) ( $sec['verify_window'] ?? 600 ) );
		$this->ip_multiplier          = max( self::MULTIPLIER_MIN, min( self::MULTIPLIER_MAX, (int) ( $sec['ip_multiplier'] ?? self::BASE_MULTIPLIER ) ) );
		$this->network_factor         = 3; // internal, fixed aggregation ratio
		$this->enabled                = ! empty( $sec['protection_enabled'] );
		$prog                         = (array) ( $sec['progressive'] ?? array() );
		$this->progressive_enabled    = ! empty( $prog['enabled'] );
		$this->initial_duration       = max( 1, (int) ( $prog['initial_duration'] ?? 60 ) );
		$this->progressive_multiplier = max( 1, (int) ( $prog['multiplier'] ?? 5 ) );
		$this->max_duration           = max( $this->initial_duration, (int) ( $prog['max_duration'] ?? 3600 ) );
		$this->decay_seconds          = max( 1, min( self::WINDOW_MAX, (int) ( $prog['decay_seconds'] ?? 3600 ) ) );
	}

	/** Memoized per-request instance (settings are immutable within a request). */
	public static function current(): self {
		static $memo = null;
		if ( $memo === null ) {
			$memo = new self( (array) ( Settings::all()['security'] ?? array() ) );
		}
		return $memo;
	}

	public function supports( string $scope ): bool {
		return isset( self::SCOPES[ $scope ] );
	}

	public function algo( string $scope ): int {
		return self::SCOPES[ $scope ]['algo'] ?? StateStore::ALGO_TOKEN_BUCKET;
	}

	/** Base [limit, window] pair for a scope. */
	public function base_pair( string $scope ): array {
		$config = self::SCOPES[ $scope ] ?? null;
		if ( ! $config ) {
			return array( 0, 0 );
		}
		return $config['base'] === 'verify'
			? array( $this->verify_limit, $this->verify_window )
			: array( $this->request_limit, $this->request_window );
	}

	/** Per-dimension limits for a scope, or null when unused. */
	public function bucket_limits( string $scope, string $dim ): ?array {
		$config = self::SCOPES[ $scope ] ?? null;
		if ( ! $config ) {
			return null;
		}
		if ( $config['ip_only'] && $dim !== Guard::DIM_IP && $dim !== Guard::DIM_NETWORK ) {
			return null;
		}
		[ $base_limit, $base_window ] = $this->base_pair( $scope );

		if ( $dim === Guard::DIM_IP ) {
			if ( $config['ip'] <= 0 ) {
				return null;
			}
			return array(
				'limit'  => self::scaled( $base_limit, $config['ip'], $this->ip_multiplier ),
				'window' => $base_window,
			);
		}

		if ( $dim === Guard::DIM_NETWORK ) {
			if ( $config['ip'] <= 0 ) {
				return null;
			}
			$ip_limit = self::scaled( $base_limit, $config['ip'], $this->ip_multiplier );
			return array(
				'limit'  => (int) round( $ip_limit * $this->network_factor ),
				'window' => $base_window,
			);
		}

		if ( $dim === Guard::DIM_IDENTIFIER ) {
			if ( $config['id'] <= 0 ) {
				return null;
			}
			return array(
				'limit'  => max( 1, (int) round( $base_limit * $config['id'] ) ),
				'window' => $base_window,
			);
		}

		if ( $dim === Guard::DIM_COMBINATION ) {
			if ( $config['id'] <= 0 || $config['combo'] <= 0 ) {
				return null;
			}
			$id_limit = max( 1, (int) round( $base_limit * $config['id'] ) );
			return array(
				'limit'  => max( 1, (int) round( $id_limit * $config['combo'] ) ),
				'window' => $base_window,
			);
		}

		return null;
	}

	/** scale = round( base x factor x multiplier / BASE_MULTIPLIER ), min 1. */
	private static function scaled( int $base, int $factor, int $multiplier ): int {
		return max( 1, (int) round( $base * $factor * $multiplier / self::BASE_MULTIPLIER ) );
	}

	public static function clamp_limit( int $value ): int {
		return max( self::LIMIT_MIN, min( self::LIMIT_MAX, $value ) );
	}

	public static function clamp_window( int $value ): int {
		return max( self::WINDOW_MIN, min( self::WINDOW_MAX, $value ) );
	}
}
