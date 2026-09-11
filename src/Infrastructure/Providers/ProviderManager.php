<?php
namespace Peyvast\Auth\Infrastructure\Providers;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Providers\Email\WordPressMail;
use Peyvast\Auth\Infrastructure\Providers\SMS\IpPanel;
use Peyvast\Auth\Infrastructure\Providers\SMS\IpPanelUsernamePassword;
use Peyvast\Auth\Infrastructure\Providers\SMS\Kavenegar;
use Peyvast\Auth\Infrastructure\Providers\SMS\Melipayamak;
use Peyvast\Auth\Infrastructure\Providers\SMS\Msgway;
use Peyvast\Auth\Infrastructure\Providers\SMS\SmsIr;

defined( 'ABSPATH' ) || exit;

/** Selects adapters and normalizes all provider outcomes. */
final class ProviderManager {
	private static function sms_provider( $id ) {
		switch ( (string) $id ) {
			case 'kavenegar':
				return new Kavenegar();
			case 'melipayamak':
				return new Melipayamak();
			case 'msgway':
				return new Msgway();
			case 'smsir':
				return new SmsIr();
			case 'ippanel':
				return new IpPanel();
			case 'ippanel_username_password':
				return new IpPanelUsernamePassword();
			default:
				return null;
		}
	}

	public static function send_sms( $phone, $otp, array $context = array() ) {
		$id = isset( $context['provider'] ) ? (string) $context['provider'] : (string) Settings::get( 'providers.sms.active', 'none' );
		unset( $context['provider'] );
		return self::send_sms_with_provider( $id, (string) $phone, (string) $otp, $context );
	}

	public static function send_sms_with_provider( $id, $phone, $otp, array $context = array() ) {
		Logger::add_secret( $otp );
		$provider = self::sms_provider( $id );
		if ( ! $provider ) {
			Logger::error( 'provider', 'provider_not_configured', 'No active SMS provider is configured.', array( 'provider' => $id ), (int) ( $context['user_id'] ?? 0 ), $id, (string) ( $context['request_id'] ?? '' ) );
			return new ProviderResult( false, $id, 'Provider not configured' );
		}
		$started = microtime( true );
		try {
			$result                      = $provider->send( (string) $phone, (string) $otp, $context );
			$result                      = self::normalize_result( $result, $id );
			$result->meta                = self::prepare_diagnostics( $result->meta );
			$result->meta['duration_ms'] = round( ( microtime( true ) - $started ) * 1000, 2 );
			self::log_result( $result, $context );
			return $result;
		} catch ( \Throwable $e ) {
			$result = new ProviderResult(
				false,
				$id,
				'Provider exception',
				array(
					'duration_ms'       => round( ( microtime( true ) - $started ) * 1000, 2 ),
					'exception_class'   => get_class( $e ),
					'exception' => get_class( $e ),
				)
			);
			self::log_result( $result, $context );
			return $result;
		}
	}

	public static function send_email( $email, $otp, array $context = array() ) {
		Logger::add_secret( $otp );
		$started = microtime( true );
		try {
			$provider                    = new WordPressMail();
			$result                      = self::normalize_result( $provider->send( (string) $email, (string) $otp, $context ), 'WordPress' );
			$result->meta                = self::prepare_diagnostics( $result->meta );
			$result->meta['duration_ms'] = round( ( microtime( true ) - $started ) * 1000, 2 );
			self::log_result( $result, $context );
			return $result;
		} catch ( \Throwable $e ) {
			$result = new ProviderResult(
				false,
				'WordPress',
				'Email provider exception',
				array(
					'duration_ms'       => round( ( microtime( true ) - $started ) * 1000, 2 ),
					'exception_class'   => get_class( $e ),
					'exception' => get_class( $e ),
				)
			);
			self::log_result( $result, $context );
			return $result;
		}
	}

	private static function normalize_result( $result, $provider ) {
		if ( $result instanceof ProviderResult ) {
			return $result;
		}
		return new ProviderResult( false, $provider, 'Invalid provider result' );
	}

	private static function prepare_diagnostics( $value ) {
		$hidden = array( 'body', 'request_body', 'response_body', 'soap_request', 'soap_response', 'raw_request_body', 'raw_response_body' );
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$normalized = strtolower( str_replace( array( '-', ' ' ), '_', (string) $key ) );
				if ( in_array( $normalized, $hidden, true ) ) {
					$out[ $key ] = '[omitted]';
					continue;
				}
				$out[ $key ] = self::prepare_diagnostics( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return self::prepare_diagnostics( get_object_vars( $value ) );
		}
		return $value;
	}

	private static function log_result( ProviderResult $result, array $context ) {
		Logger::log(
			$result->success ? 'info' : 'error',
			'provider',
			$result->success ? 'delivery_success' : 'delivery_failed',
			$result->success ? 'Provider delivery succeeded.' : 'Provider delivery failed.',
			array(
				'provider' => $result->provider,
				'success'  => $result->success,
				'message'  => $result->message,
				'meta'     => $result->meta,
				'purpose'  => $context['purpose'] ?? '',
			),
			(int) ( $context['user_id'] ?? 0 ),
			$result->provider,
			(string) ( $context['request_id'] ?? '' )
		);
	}
}
