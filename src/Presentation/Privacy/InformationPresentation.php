<?php
namespace Peyvast\Auth\Presentation\Privacy;

use Peyvast\Auth\Domain\Phone\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/** Sole public representation of identifiers/destinations; raw values never leave it. */
final class InformationPresentation {
	public static function build( string $identifier_type, string $identifier, array $destinations ): array {
		$identifier_type = in_array( $identifier_type, array( 'phone', 'email', 'user_login' ), true ) ? $identifier_type : '';
		$destinations = self::safe_destinations( $destinations );
		$phone          = '';
		$email          = '';
		$has_sms        = false;
		$has_email      = false;
		foreach ( $destinations as $destination ) {
			$type   = (string) ( $destination['type'] ?? '' );
			$value  = (string) ( $destination['value'] ?? '' );
			$source = (string) ( $destination['source'] ?? 'server' );
			if ( $type === 'phone' && $value !== '' ) {
				// User-entered phones echo verbatim; server-derived phones are masked.
				$phone   = $source === 'user_input' ? PhoneNumber::display_value( $identifier ) : PhoneMasker::mask( $value );
				$has_sms = true;
			}
			if ( $type === 'email' && is_email( $value ) ) {
				// Account emails masked; user input echoed.
				$email     = $source === 'user_input' ? $value : EmailMasker::mask( $value );
				$has_email = true;
			}
		}
		// Single {identifier}: mobile first, then email, combined for dual delivery.
		$identifier_value = self::join_parts( array( $phone, $email ) );
		// {channel}: only channels accepted in the server response, readable list.
		$channel_types = array_values( array_unique( array_map( static function ( $destination ) { return (string) ( $destination['channel'] ?? '' ); }, $destinations ) ) );
		$channel_types = array_values( array_filter( $channel_types ) );
		$channel_display = self::join_parts( array( $has_email ? __( 'email', 'peyvast-auth' ) : '', $has_sms ? __( 'phone', 'peyvast-auth' ) : '' ) );
		return array(
			'identifier_type' => $identifier_type,
			'identifier'      => $identifier_value,
			'channel'         => $channel_display,
			'channels'        => $channel_types,
		);
	}

	/** Join non-empty parts with the localized "and" separator. */
	private static function join_parts( array $parts ): string {
		$parts = array_values( array_filter( array_map( 'trim', $parts ) ) );
		return implode( ' ' . __( 'and', 'peyvast-auth' ) . ' ', $parts );
	}

	private static function safe_destinations( array $destinations ): array {
		$out = array();
		foreach ( $destinations as $destination ) {
			if ( ! is_array( $destination ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $destination['type'] ?? '' ) );
			$channel = sanitize_key( (string) ( $destination['channel'] ?? $type ) );
			$value = trim( (string) ( $destination['value'] ?? '' ) );
			if ( ! in_array( $type, array( 'phone', 'email' ), true ) || $value === '' ) {
				continue;
			}
			$out[] = array(
				'type'    => $type,
				'channel' => $channel,
				'value'   => $value,
				'source'  => in_array( (string) ( $destination['source'] ?? 'server' ), array( 'user_input', 'server' ), true ) ? (string) $destination['source'] : 'server',
			);
		}
		return $out;
	}

}
