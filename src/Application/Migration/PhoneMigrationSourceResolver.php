<?php
namespace Peyvast\Auth\Application\Migration;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Phone\PhoneNumber;

defined( 'ABSPATH' ) || exit;

final class PhoneMigrationSourceResolver {
	public static function snapshot(): array {
		$source = Settings::phone_source();
		return array(
			'legacy_type' => (string) ( $source['type'] ?? 'off' ),
			'legacy_key'  => (string) ( $source['key'] ?? '' ),
		);
	}

	public function resolve( \WP_User $user, array $snapshot ): array {
		$primary_raw = trim( (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true ) );
		$primary = PhoneNumber::canonical_value( $primary_raw );
		if ( $primary !== '' ) {
			return array( 'status' => 'valid', 'source' => 'primary', 'canonical_phone' => $primary, 'reason' => 'peyvast_phone_valid' );
		}

		$legacy_type = (string) ( $snapshot['legacy_type'] ?? 'off' );
		$legacy_key  = (string) ( $snapshot['legacy_key'] ?? '' );
		$legacy_raw  = '';
		if ( $legacy_type === 'user_meta' && $legacy_key !== '' ) {
			$legacy_raw = trim( (string) get_user_meta( $user->ID, $legacy_key, true ) );
			$legacy = PhoneNumber::canonical_value( $legacy_raw );
			if ( $legacy !== '' ) {
				return array( 'status' => 'valid', 'source' => 'legacy_meta', 'canonical_phone' => $legacy, 'reason' => 'legacy_meta_valid' );
			}
		}

		$login_raw = trim( (string) $user->user_login );
		$login = PhoneNumber::canonical_value( $login_raw );
		if ( $login !== '' ) {
			return array( 'status' => 'valid', 'source' => 'user_login_fallback', 'canonical_phone' => $login, 'reason' => 'user_login_fallback_valid' );
		}

		$had_candidate = $primary_raw !== '' || $legacy_raw !== '' || $login_raw !== '';
		return array(
			'status'          => $had_candidate ? 'invalid' : 'missing',
			'source'          => 'none',
			'canonical_phone' => '',
			'reason'          => $had_candidate ? 'all_sources_invalid' : 'all_sources_missing',
		);
	}
}
