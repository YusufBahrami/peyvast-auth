<?php
namespace Peyvast\Auth\Presentation\Rest;

use Peyvast\Auth\Infrastructure\WordPress\GuestSession;

defined( 'ABSPATH' ) || exit;

final class RestResponse {
	private const ERROR_STATUS_MAP = array(
		'invalid_request'       => 403,
		'forbidden'             => 403,
		'auth_disabled'         => 503,
		'security_unavailable'  => 503,
		'security_blocked'      => 429,
		'send_rate_limited'     => 429,
		'verify_rate_limited'   => 429,
		'registration_disabled' => 403,
		'delivery_failed'       => 400,
		'security_rate_limited' => 429,
		'identity_unavailable'  => 503,
		'storage_failed'        => 503,
		'storage_busy'          => 503,
		'delivery_unavailable'  => 503,
		'invalid_token'         => 400,
		'reset_unavailable'     => 400,
	);

	/** Serialize a success payload against an explicit per-context safe schema. */
	public static function success( array $payload = array(), string $context = 'public' ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => self::sanitize_success( $payload, $context ),
			),
			200
		);
	}

	/** Safe top-level fields for public authentication responses. */
	private const PUBLIC_FIELDS = array(
		'challenge_id',
		'delivery_status',
		'identifier_type',
		'restored',
		'retry_after',
		'expires_in',
		'channels',
		'information',
		'verification_token',
		'user_exists',
		'next_stage',
		'purpose',
		'redirect',
		// security-status endpoint (Guard::status_details):
		'blocked',
		'client_block',
	);

	/** Safe top-level fields for administrator responses. */
	private const ADMIN_FIELDS = array(
		'message',
		'migration_status',
		'migration',
		'dismissed',
		'status',
		'sample_count',
		'valid_count',
		'invalid_count',
		'redirect',
	);

	/** The {@see InformationPresentation} DTO fields that are already privacy-safe. */
	private const INFORMATION_FIELDS = array(
		'type',
		'identifier_type',
		'identifier',
		'identifiers',
		'phone',
		'email',
		'channel',
		'channels',
	);

	private static function sanitize_success( array $payload, string $context ): array {
		$allowed = $context === 'admin' ? self::ADMIN_FIELDS : self::PUBLIC_FIELDS;
		$result  = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$result[ $key ] = $payload[ $key ];
			}
		}
		if ( isset( $result['information'] ) && is_array( $result['information'] ) ) {
			$info = $result['information'];
			$safe = array();
			foreach ( self::INFORMATION_FIELDS as $key ) {
				if ( array_key_exists( $key, $info ) ) {
					$safe[ $key ] = $info[ $key ];
				}
			}
			$result['information'] = $safe;
		}
		return $result;
	}

	public static function error( string $code, string $message, int $status = 400, array $extra = array() ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'data'    => array_merge(
					array(
						'code'    => $code,
						'message' => $message,
					),
					$extra
				),
			),
			$status
		);
	}
	public static function require_nonce( \WP_REST_Request $request, string $action ): ?\WP_REST_Response {
		if ( 'peyvast_auth' === $action && ! self::same_origin( $request ) ) {
			return self::error( 'invalid_request', __( 'Invalid request.', 'peyvast-auth' ), 403 );
		}
		GuestSession::ensure();
		$nonce = $request->get_param( 'nonce' );
		$nonce = is_scalar( $nonce ) ? sanitize_text_field( wp_unslash( (string) $nonce ) ) : '';
		return $nonce === '' || ! wp_verify_nonce( $nonce, $action ) ? self::error( 'invalid_request', __( 'Invalid request.', 'peyvast-auth' ), 403 ) : null;
	}

	/**
	 * Same-origin check for state-changing requests. A missing Origin and
	 * Referer is accepted by policy: the nonce remains the authoritative CSRF
	 * boundary, and some proxies strip these headers.
	 */
	public static function same_origin( \WP_REST_Request $request ): bool {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$source = $origin !== '' ? $origin : $referer;
		if ( $source === '' ) {
			return true;
		}
		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $source );
		if ( ! is_array( $expected ) || ! is_array( $actual ) || empty( $expected['host'] ) || empty( $actual['host'] ) ) {
			return false;
		}
		if ( ! hash_equals( strtolower( (string) $expected['host'] ), strtolower( (string) $actual['host'] ) ) ) {
			return false;
		}
		$expected_scheme = strtolower( (string) ( $expected['scheme'] ?? '' ) );
		$actual_scheme   = strtolower( (string) ( $actual['scheme'] ?? '' ) );
		if ( $expected_scheme !== '' && $actual_scheme !== '' && $expected_scheme !== $actual_scheme ) {
			return false;
		}
		$default_ports = array( 'http' => 80, 'https' => 443 );
		$expected_port  = isset( $expected['port'] ) ? (int) $expected['port'] : ( $default_ports[ $expected_scheme ] ?? 0 );
		$actual_port    = isset( $actual['port'] ) ? (int) $actual['port'] : ( $default_ports[ $actual_scheme ] ?? 0 );
		return $expected_port === $actual_port;
	}
	public static function from_wp_error( \WP_Error $wp_error, int $fallback_status = 400, array $extra = array() ): \WP_REST_Response {
		$data = $wp_error->get_error_data( $wp_error->get_error_code() );
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$fallback_status = (int) $data['status'];
		}
		$code    = $wp_error->get_error_code();
		$allowed = array( 'field', 'token', 'retry_after', 'client_block' );
		$safe    = array();
		if ( is_array( $data ) ) {
			foreach ( $allowed as $key ) {
				if ( array_key_exists( $key, $data ) ) {
								$safe[ $key ] = $data[ $key ];
				}
			}
		}
		return self::error( $code, $wp_error->get_error_message(), self::ERROR_STATUS_MAP[ $code ] ?? $fallback_status, array_merge( $extra, $safe ) );
	}
	public static function from_guard( array $guard ): \WP_REST_Response {
		if ( ( $guard['code'] ?? '' ) === 'security_unavailable' ) {
			return self::error( 'security_unavailable', __( 'A server error occurred. Please try again in a few minutes.', 'peyvast-auth' ), 503 );
		}
		if ( ! empty( $guard['blocked'] ) ) {
			return self::error(
				'security_blocked',
				__( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ),
				429,
				array(
					'token'        => $guard['token'] ?? null,
					'retry_after'  => max( 0, (int) ( $guard['retry_after'] ?? 0 ) ),
					'client_block' => ! empty( $guard['client_block'] ),
				)
			);
		}
		return self::error( 'security_rate_limited', __( 'The number of requests exceeds the allowed limit.', 'peyvast-auth' ), 429 );
	}
}
