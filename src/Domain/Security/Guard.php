<?php
namespace Peyvast\Auth\Domain\Security;

use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\SecurityStateStore as StateStore;

defined( 'ABSPATH' ) || exit;

/** Server-side security policy engine; buckets locked in fixed order per request. */
final class Guard {

	public const SCOPE_OTP_SEND           = 'otp_send';
	public const SCOPE_OTP_VERIFY         = 'otp_verify';
	public const SCOPE_PASSWORD_LOGIN     = 'password_login';
	public const SCOPE_INVALID_IDENTIFIER = 'invalid_identifier';
	public const SCOPE_REGISTRATION       = 'registration';
	public const SCOPE_PASSWORD_RESET     = 'password_reset';
	public const SCOPE_GOOGLE             = 'google';
	public const SCOPE_PROVIDER_TEST      = 'provider_test';

	public const DIM_IP          = 'ip';
	public const DIM_NETWORK     = 'network';
	public const DIM_IDENTIFIER  = 'identifier';
	public const DIM_COMBINATION = 'combination';

	/** All guarded scopes (used for the status fast path). */
	private const SCOPES = array(
		self::SCOPE_OTP_SEND,
		self::SCOPE_OTP_VERIFY,
		self::SCOPE_PASSWORD_LOGIN,
		self::SCOPE_INVALID_IDENTIFIER,
		self::SCOPE_REGISTRATION,
		self::SCOPE_PASSWORD_RESET,
		self::SCOPE_GOOGLE,
		self::SCOPE_PROVIDER_TEST,
	);

	/* Public API */

	/** Outcome-independent evaluation in one short transaction. */
	public static function evaluate( string $scope, array $context = array() ): array {
		$policy = SecurityPolicy::current();
		if ( ! $policy->enabled || ! $policy->supports( $scope ) ) {
			return self::empty_result( true );
		}

		$ip      = self::remote_ip( $context['ip'] ?? null );
		$buckets = self::buckets_for( $scope, $policy, (string) ( $context['identifier'] ?? '' ), $ip );
		$result  = StateStore::evaluate( $scope, $buckets );

		if ( isset( $result['code'] ) && $result['code'] === 'storage_unavailable' ) {
			Logger::error(
				'security',
				'security_state_unavailable',
				'Security state storage could not complete the request.',
				array(
					'operation'      => (string) ( $result['operation'] ?? 'unknown' ),
					'error_category' => (string) ( $result['error_category'] ?? 'unknown' ),
				),
				0,
				'',
				''
			);
			return array(
				'allowed'      => false,
				'blocked'      => false,
				'token'        => null,
				'retry_after'  => 0,
				'client_block' => false,
				'code'         => 'security_unavailable',
				'snapshots'    => array(),
			);
		}

		if ( $result['blocked'] ) {
			// Pre-existing block: no new token is issued.
			return array(
				'allowed'      => false,
				'blocked'      => true,
				'token'        => null,
				'retry_after'  => self::retry_after_from_rows( $result['rows'] ),
				'client_block' => self::rows_have_global_block( $result['rows'] ),
				'code'         => 'security_blocked',
				'snapshots'    => array(),
			);
		}

		$failed_dims = array();
		foreach ( $result['verdicts'] as $dim => $allowed ) {
			if ( ! $allowed ) {
				$failed_dims[] = (string) $dim;
			}
		}

		if ( ! $result['allowed'] ) {
			$block = self::maybe_progressive_block( $scope, $result['rows'], $failed_dims, $policy );
			if ( $block ) {
				return array(
					'allowed'      => false,
					'blocked'      => true,
					'token'        => $block['token'],
					'retry_after'  => (int) ( $block['retry_after'] ?? 0 ),
					'client_block' => ! empty( $block['client_block'] ),
					'code'         => 'security_blocked',
					'snapshots'    => array(),
				);
			}
			return array(
				'allowed'      => false,
				'blocked'      => false,
				'token'        => null,
				'retry_after'  => 0,
				'client_block' => false,
				'code'         => 'security_rate_limited',
				'snapshots'    => array(),
			);
		}

		// On success, snapshot identifier + combination only; version is post-commit.
		$snapshots = array();
		foreach ( $result['rows'] as $dim => $row ) {
			if ( ! in_array( (string) $dim, array( self::DIM_IDENTIFIER, self::DIM_COMBINATION ), true ) ) {
				continue;
			}
			if ( ! empty( $row['bucket_key'] ) ) {
				$snapshots[] = array(
					'bucket'  => (string) $row['bucket_key'],
					'version' => (int) ( $row['version'] ?? 0 ) + 1,
				);
			}
		}
		return array(
			'allowed'   => true,
			'blocked'   => false,
			'token'     => null,
			'code'      => '',
			'snapshots' => $snapshots,
		);
	}

	/** Outcome-dependent failure recording (password failures, invalid identifiers). */
	public static function record_failure( string $scope, array $context = array() ): bool {
		$policy = SecurityPolicy::current();
		if ( ! $policy->enabled || ! $policy->supports( $scope ) ) {
			return true;
		}
		$ip      = self::remote_ip( $context['ip'] ?? null );
		$buckets = self::buckets_for( $scope, $policy, (string) ( $context['identifier'] ?? '' ), $ip );
		return StateStore::record_failure( $scope, $buckets, array(
			'enabled'          => $policy->progressive_enabled,
			'initial_duration' => $policy->initial_duration,
			'multiplier'       => $policy->progressive_multiplier,
			'max_duration'     => $policy->max_duration,
			'decay_seconds'    => $policy->decay_seconds,
		) );
	}

	/** Version-safe success reset (OTP login / password login / registration). */
	public static function record_success( string $scope, array $snapshots = array() ): bool {
		if ( ! SecurityPolicy::current()->enabled || ! $snapshots ) {
			return true;
		}
		return StateStore::reset_success( $snapshots );
	}

	/** Client blocking state derived from server/IP state only; the DB is authoritative. */
	public static function status_details(): array {
		if ( ! SecurityPolicy::current()->enabled ) {
			return array(
				'blocked'      => false,
				'retry_after'  => 0,
				'client_block' => false,
			);
		}
		$now       = time();
		$ip        = self::remote_ip();
		$max_until = 0;
		$global    = false;
		foreach ( self::SCOPES as $scope ) {
			$row = StateStore::read_block( StateStore::bucket_key( $scope, self::DIM_IP, $ip ) );
			if ( $row && ! empty( $row['blocked_until'] ) && (int) $row['blocked_until'] > $now ) {
				$max_until = max( $max_until, (int) $row['blocked_until'] );
				$global    = true; }
		}
		if ( $max_until > $now ) {
			StateStore::set_status_ip_blocked( $ip, $max_until );
		}
		return array(
			'blocked'      => ( $max_until > $now || $global ),
			'retry_after'  => max( 0, $max_until - $now ),
			'client_block' => $global,
		);
	}

	/** Admin action: clear all enforcement counters and progressive state. */
	public static function clear_all_blocks(): void {
		StateStore::clear_all_blocks();
		Logger::notice( 'security', 'security_blocks_cleared', 'All active security blocks were cleared by an administrator.', array() );
	}

	/** Version snapshots for identifier/combination buckets (success reset). */
	public static function snapshot( string $scope, array $context = array() ): array {
		$policy = SecurityPolicy::current();
		if ( ! $policy->enabled || ! $policy->supports( $scope ) || ! $policy->bucket_limits( $scope, self::DIM_IDENTIFIER ) ) {
			return array();
		}
		$ip = self::remote_ip( $context['ip'] ?? null );
		$id = (string) ( $context['identifier'] ?? '' );
		if ( $id === '' ) {
			return array();
		}
		$id_key = self::identifier_key( $id );
		$out    = array();
		foreach ( array( self::DIM_IDENTIFIER, self::DIM_COMBINATION ) as $dim ) {
			if ( ! $policy->bucket_limits( $scope, $dim ) ) {
				continue;
			}
			$value = $dim === self::DIM_IDENTIFIER ? $id_key : ( $ip !== 'cli' ? $ip . '|' . $id_key : '' );
			if ( $value === '' ) {
				continue;
			}
			$row = StateStore::precheck( $scope, $dim, $value );
			if ( $row && ! empty( $row['bucket_key'] ) ) {
				$out[] = array(
					'bucket'  => (string) $row['bucket_key'],
					'version' => (int) ( $row['version'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/** Returns the generic result shape used by every public method. */
	private static function empty_result( bool $allowed ): array {
		return array(
			'allowed'   => $allowed,
			'blocked'   => false,
			'token'     => null,
			'code'      => '',
			'snapshots' => array(),
		);
	}

	/** Read-only early denial for outcome-dependent policies; never mutates. */
	public static function precheck( string $scope, array $context = array() ): bool {
		$policy = SecurityPolicy::current();
		if ( ! $policy->enabled || ! $policy->supports( $scope ) ) {
			return true;
		}
		$ip      = self::remote_ip( $context['ip'] ?? null );
		$buckets = self::buckets_for( $scope, $policy, (string) ( $context['identifier'] ?? '' ), $ip );
		$now     = time();
		foreach ( $buckets as $bucket ) {
			$row = StateStore::precheck( $scope, (string) $bucket['dim'], (string) $bucket['value'] );
			if ( ! $row ) {
				continue; // no row yet: nothing limiting this dimension.
			}
			if ( ! empty( $row['blocked_until'] ) && (int) $row['blocked_until'] > $now ) {
				return false;
			}
			$limit = max( 1, (int) ( $bucket['limit'] ?? 0 ) );
			if ( (int) $row['algo'] === StateStore::ALGO_TOKEN_BUCKET ) {
				$refilled = min( (float) $row['tokens'] + max( 0, $now - (int) $row['last_refill_at'] ) * ( $limit / max( 1, (int) ( $bucket['window'] ?? 1 ) ) ), (float) $limit );
				if ( $refilled < 1 ) {
					return false;
				}
			} elseif ( (int) $row['count'] >= $limit && ( (int) $row['window_started_at'] + max( 1, (int) ( $bucket['window'] ?? 0 ) ) ) > $now ) {
				return false;
			}
		}
		return true;
	}

	/* Bucket construction */

	private static function buckets_for( string $scope, SecurityPolicy $policy, string $identifier, string $ip ): array {
		$buckets = array();
		$algo    = $policy->algo( $scope );

		// Exact IP - the primary source-level limiter.
		$ip_limits = $policy->bucket_limits( $scope, self::DIM_IP );
		if ( $ip_limits ) {
			$buckets[] = array(
				'dim'    => self::DIM_IP,
				'value'  => $ip,
				'algo'   => $algo,
				'limit'  => $ip_limits['limit'],
				'window' => $ip_limits['window'],
			);

			// Network aggregation - secondary high-risk signal only (3x threshold).
			$net = self::network_id( $ip );
			if ( $net !== '' ) {
				$net_limits = $policy->bucket_limits( $scope, self::DIM_NETWORK );
				if ( $net_limits ) {
					$buckets[] = array(
						'dim'    => self::DIM_NETWORK,
						'value'  => $net,
						'algo'   => $algo,
						'limit'  => $net_limits['limit'],
						'window' => $net_limits['window'],
					);
				}
			}
		}

		$id_limits = $policy->bucket_limits( $scope, self::DIM_IDENTIFIER );
		if ( $id_limits && $identifier !== '' ) {
			$id_key    = self::identifier_key( $identifier );
			$buckets[] = array(
				'dim'    => self::DIM_IDENTIFIER,
				'value'  => $id_key,
				'algo'   => $algo,
				'limit'  => $id_limits['limit'],
				'window' => $id_limits['window'],
			);

			$combo_limits = $policy->bucket_limits( $scope, self::DIM_COMBINATION );
			if ( $combo_limits && $ip !== 'cli' ) {
				$buckets[] = array(
					'dim'    => self::DIM_COMBINATION,
					'value'  => $ip . '|' . $id_key,
					'algo'   => $algo,
					'limit'  => $combo_limits['limit'],
					'window' => $combo_limits['window'],
				);
			}
		}
		return $buckets;
	}

	/** IPv4 -> /24, IPv6 -> /64 aggregation (secondary signal). */
	private static function network_id( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return substr( $ip, 0, strrpos( $ip, '.' ) ) . '.0/24';
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::ipv6_network64( $ip );
		}
		return '';
	}

	/** Canonical /64 bucket: compressed and expanded forms collapse to one. */
	private static function ipv6_network64( string $ip ): string {
		$binary = @inet_pton( $ip );
		if ( $binary === false || strlen( $binary ) !== 16 ) {
			return '';
		}
		$prefix8   = substr( $binary, 0, 8 );
		$hextets   = str_split( bin2hex( $prefix8 ), 4 );
		$canonical = strtolower( implode( ':', (array) $hextets ) );
		return $canonical . '::/64';
	}

	/** Keyed HMAC so raw phone/email identifiers never reach storage. */
	private static function identifier_key( string $identifier ): string {
		$secret = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		return hash_hmac( 'sha256', $identifier, $secret );
	}

	/** Actual server remote address only; never proxy headers. */
	private static function remote_ip( $provided = null ): string {
		if ( $provided !== null && $provided !== '' ) {
			$candidate = sanitize_text_field( wp_unslash( $provided ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$candidate = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : 'unknown';
	}

	/* Blocking */

	/** Progressive escalation; only failing dimensions escalate. */
	private static function maybe_progressive_block( string $scope, array $rows, array $failed_dims, SecurityPolicy $policy ) {
		if ( ! $policy->progressive_enabled ) {
			return null;
		}
		$initial    = max( 1, $policy->initial_duration );
		$multiplier = max( 1, $policy->progressive_multiplier );
		$max        = max( $initial, $policy->max_duration );
		$decay      = max( 1, $policy->decay_seconds );

		$issued_token = null;
		$max_until    = 0;
		$client_block = false;

		foreach ( $failed_dims as $dim ) {
			$row = $rows[ $dim ] ?? null;
			if ( ! $row || empty( $row['bucket_key'] ) ) {
				continue;
			}
			$now        = time();
			$prev_end   = (int) ( $row['blocked_until'] ?? 0 );
			$past_clean = $prev_end > 0 && ( $now - $prev_end ) >= $decay;

			// After a clean period the next violation returns to the base stage.
			$stage         = $past_clean ? 1 : max( 1, (int) ( $row['stage'] ?? 0 ) + 1 );
			$duration      = min( $max, (int) ( $initial * pow( $multiplier, max( 0, $stage - 1 ) ) ) );
			$blocked_until = $now + $duration;

			// One opaque token per IP block; only its SHA-256 is persisted.
			$token_hash = null;
			if ( $dim === self::DIM_IP && $issued_token === null ) {
				$issued_token = bin2hex( random_bytes( 32 ) );
				$token_hash   = hash( 'sha256', $issued_token );
			}

			StateStore::apply_block( (string) $row['bucket_key'], $blocked_until, $stage, $token_hash );
			$max_until = max( $max_until, $blocked_until );
			if ( in_array( $dim, array( self::DIM_IP, self::DIM_NETWORK ), true ) ) {
				$client_block = true;
			}

			Logger::warning(
				'security',
				'security_block_started',
				'A security block was activated.',
				array(
					'scope'    => $scope,
					'dim'      => $dim,
					'stage'    => $stage,
					'duration' => $duration,
				)
			);
		}

		return $max_until > 0 ? array(
			'token'        => $issued_token,
			'retry_after'  => max( 0, $max_until - time() ),
			'client_block' => $client_block,
		) : null;
	}
	private static function retry_after_from_rows( array $rows ): int {
		$now = time();
		$max = $now;
		foreach ( $rows as $row ) {
			$until = (int) ( $row['blocked_until'] ?? 0 );
			if ( $until > $max ) {
				$max = $until;
			}
		} return max( 0, $max - $now );
	}
	private static function rows_have_global_block( array $rows ): bool {
		foreach ( $rows as $dim => $row ) {
			if ( ! empty( $row['blocked_until'] ) && (int) $row['blocked_until'] > time() && in_array( (string) $dim, array( self::DIM_IP, self::DIM_NETWORK ), true ) ) {
				return true;
			}
		} return false;
	}
}
