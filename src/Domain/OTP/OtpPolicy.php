<?php
namespace Peyvast\Auth\Domain\OTP;

use Peyvast\Auth\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class OtpPolicy {
	public function length(): int {
		return max( 4, min( 8, (int) Settings::get( 'otp.length', 6 ) ) );
	}

	public function expiration_seconds(): int {
		return max( 30, min( 86400, (int) Settings::get( 'otp.expiration_seconds', 300 ) ) );
	}

	public function resend_cooldown(): int {
		return max( 10, min( 3600, (int) Settings::get( 'otp.resend_cooldown', 120 ) ) );
	}

	/** Key the OTP digest so a DB snapshot cannot brute-force active codes. */
	public static function hash_code( string $otp ): string {
		$secret = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		return hash_hmac( 'sha256', trim( $otp ), $secret );
	}

	public function generate(): string {
		$length  = $this->length();
		$maximum = ( 10 ** $length ) - 1;
		return str_pad( (string) random_int( 0, $maximum ), $length, '0', STR_PAD_LEFT );
	}
}
