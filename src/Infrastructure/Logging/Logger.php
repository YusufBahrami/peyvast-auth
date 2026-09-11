<?php
namespace Peyvast\Auth\Infrastructure\Logging;

use Peyvast\Auth\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

/** Request-oriented logger: one row per request; only authentication secrets are redacted. */
final class Logger {
	private const PURGE_BATCH_SIZE = 200;
	private const PURGE_ITERATIONS = 50;

	private static $request_id          = '';
	private static $enabled             = null;
	private static $secrets             = array();
	private static $levels              = array(
		'debug'    => 10,
		'info'     => 20,
		'notice'   => 30,
		'warning'  => 40,
		'error'    => 50,
		'critical' => 60,
	);
	private static $hidden_payload_keys = array( 'body', 'request_body', 'response_body', 'soap_request', 'soap_response', 'raw_request_body', 'raw_response_body' );
	private static $secret_keys         = array(
		'otp',
		'password',
		'passwd',
		'secret',
		'api_key',
		'apikey',
		'x_api_key',
		'api_token',
		'api_secret',
		'authorization',
		'cookie',
		'set_cookie',
		'proxy_authorization',
		'client_secret',
		'credential',
		'username',
		'private_key',
		'access_token',
		'refresh_token',
		'bearer',
		'smtp_password',
		'auth_header',
		'webhook_secret',
	);

	public static function reset_request_state() {
		self::$request_id = '';
		self::$secrets    = array();
		self::$enabled    = null; }
	public static function is_enabled() {
		if ( self::$enabled === null ) {
			self::$enabled = (bool) Settings::get( 'logging.enabled', false );
		} return self::$enabled; }
	public static function add_secret( $secret ) {
		if ( ! self::is_enabled() || $secret === '' ) {
			return;
		}
		$secret = (string) $secret;
		if ( strlen( $secret ) <= 4096 ) {
			self::$secrets[ $secret ] = true;
		}
	}
	public static function request_id() {
		if ( self::$request_id === '' ) {
			self::$request_id = wp_generate_uuid4();
		} return self::$request_id; }
	public static function log( $level, $channel, $event, $message, $context = array(), $user_id = 0, $provider = '', $request_id = '' ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		$minimum = (string) Settings::get( 'logging.minimum_level', 'info' );
		if ( ( self::$levels[ $level ] ?? 40 ) < ( self::$levels[ $minimum ] ?? 20 ) ) {
			return;
		}
		global $wpdb;

		$request_id = sanitize_text_field( $request_id ?: self::request_id() );
		$record     = array(
			'at'       => gmdate( 'c' ),
			'level'    => sanitize_key( $level ),
			'channel'  => sanitize_key( $channel ),
			'event'    => sanitize_key( $event ),
			'message'  => self::redact_string( $message ),
			'provider' => sanitize_key( $provider ),
			'user_id'  => absint( $user_id ),
			'context'  => self::sanitize( $context ),
		);
		$table      = PEYVAST_AUTH_LOG_TABLE;
		$existing   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id,level,channel,event,provider,user_id,message,context FROM {$table} WHERE request_id=%s ORDER BY id ASC LIMIT 1",
				$request_id
			)
		);

		if ( $existing ) {
			$stored = json_decode( (string) $existing->context, true );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			if ( ! isset( $stored['events'] ) || ! is_array( $stored['events'] ) ) {
				$stored['events'] = array();
			}
			$stored['events'][] = $record;
			$stored['events']   = array_slice( $stored['events'], -200 );
			$old_level          = (string) $existing->level;
			$max_level          = ( self::$levels[ $level ] ?? 40 ) > ( self::$levels[ $old_level ] ?? 20 ) ? $level : $old_level;
			$wpdb->update(
				$table,
				array(
					'level'      => sanitize_key( $max_level ),
					'channel'    => sanitize_key( $channel ?: $existing->channel ),
					'event'      => sanitize_key( $event ?: $existing->event ),
					'provider'   => sanitize_key( $provider ?: $existing->provider ),
					'user_id'    => absint( $user_id ?: $existing->user_id ),
					'message'    => self::redact_string( $message ),
					'context'    => wp_json_encode( $stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$stored = array(
			'events'     => array( $record ),
			'diagnostic' => array(
				'php_version' => PHP_VERSION,
				'wp_version'  => get_bloginfo( 'version' ),
			),
		);
		$now    = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'level'      => sanitize_key( $level ),
				'channel'    => sanitize_key( $channel ),
				'event'      => sanitize_key( $event ),
				'provider'   => sanitize_key( $provider ),
				'user_id'    => absint( $user_id ),
				'request_id' => $request_id,
				'message'    => self::redact_string( $message ),
				'context'    => wp_json_encode( $stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** Bounded batched retention purge; runs even while logging is disabled. */
	public static function purge() {
		global $wpdb;
		$days   = max( 1, (int) Settings::get( 'logging.retention_days', 30 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days );
		for ( $i = 0; $i < self::PURGE_ITERATIONS; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . PEYVAST_AUTH_LOG_TABLE . ' WHERE created_at < %s LIMIT ' . self::PURGE_BATCH_SIZE,
					$cutoff
				)
			);
			if ( ! $deleted || $deleted < self::PURGE_BATCH_SIZE ) {
				break;
			}
		}
	}

	private static function normalize_key( $key ): string {
		return strtolower( str_replace( array( '-', ' ' ), '_', (string) $key ) );
	}

	private static function sanitize( $value, $key = '' ) {
		if ( $key === 'recipient' && is_string( $value ) && is_email( $value ) ) {
			return \Peyvast\Auth\Presentation\Privacy\EmailMasker::mask( $value );
		}
		if ( self::is_hidden_payload_key( $key ) ) {
			return '[omitted]';
		}
		if ( self::is_secret_key( $key ) ) {
			return '[redacted]';
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::sanitize( $v, (string) $k );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::redact_string( $value, $key );
		}
		return is_scalar( $value ) || $value === null ? $value : '[unsupported]';
	}

	private static function is_hidden_payload_key( $key ): bool {
		$key = self::normalize_key( $key );
		return in_array( $key, self::$hidden_payload_keys, true );
	}

	private static function is_secret_key( $key ) {
		$key = self::normalize_key( $key );
		foreach ( self::$secret_keys as $needle ) {
			if ( $key === $needle || substr( $key, -strlen( '_' . $needle ) ) === '_' . $needle ) {
				return true;
			}
		}
		return false;
	}

	/** Redact common JSON/query/XML credential fields while preserving bodies. */
	private static function redact_string( $value, $key = '' ) {
		$value = (string) $value;
		foreach ( self::$secrets as $secret => $unused ) {
			if ( $secret !== '' ) {
				$value = str_replace( $secret, '[redacted]', $value );
			}
		}
		if ( self::is_secret_key( $key ) ) {
			return '[redacted]';
		}

		$value = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $value ) ?: $value;

		// JSON-like key/value pairs.
		$jsonKeys = '(?:password|passwd|username|api[-_ ]?key|api[-_ ]?token|api[-_ ]?secret|x-api-key|authorization|proxy[-_ ]?authorization|secret|token|access[-_]?token|refresh[-_]?token|client[-_ ]?secret|credential|private[-_ ]?key|webhook[-_ ]?secret)';
		$value    = preg_replace( '/(["\']' . $jsonKeys . '["\']\s*:\s*["\'])(.*?)(["\'])/is', '$1[redacted]$3', $value ) ?: $value;
		$value    = preg_replace( '/(\b' . $jsonKeys . '\b\s*[:=]\s*["\']?)([^,\s&"\']+)(?=[,\s&"\']|$)/i', '$1[redacted]', $value ) ?: $value;

		// XML/SOAP credential elements.
		$xmlKeys = '(?:password|passwd|username|apiKey|apiToken|apiSecret|x-api-key|authorization|proxyAuthorization|secret|token|accessToken|refreshToken|clientSecret|credential|privateKey|webhookSecret)';
		$value   = preg_replace( '/(<(?:[A-Za-z0-9_.-]+:)?' . $xmlKeys . '\b[^>]*>).*?(<\/(?:[A-Za-z0-9_.-]+:)?' . $xmlKeys . '>)/is', '$1[redacted]$2', $value ) ?: $value;

		// Userinfo credentials embedded in an endpoint URL.
		$value = preg_replace( '/(https?:\/\/[^\s\/?#:]+:)[^@\s]+(@)/i', '$1[redacted]$2', $value ) ?: $value;

		return $value;
	}

	public static function notice( $channel, $event, $message, $context = array(), $user_id = 0, $provider = '', $request_id = '' ) {
		self::log( 'notice', $channel, $event, $message, $context, $user_id, $provider, $request_id ); }
	public static function warning( $channel, $event, $message, $context = array(), $user_id = 0, $provider = '', $request_id = '' ) {
		self::log( 'warning', $channel, $event, $message, $context, $user_id, $provider, $request_id ); }
	public static function error( $channel, $event, $message, $context = array(), $user_id = 0, $provider = '', $request_id = '' ) {
		self::log( 'error', $channel, $event, $message, $context, $user_id, $provider, $request_id ); }
}
