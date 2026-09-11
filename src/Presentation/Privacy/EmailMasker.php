<?php
namespace Peyvast\Auth\Presentation\Privacy;

defined( 'ABSPATH' ) || exit;

final class EmailMasker {
	public static function mask( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return '';
		}
		[$local, $domain] = explode( '@', $email, 2 );
		$length           = strlen( $local );
		if ( $length <= 1 ) {
			// Single-char local part: mask it entirely.
			$masked = '***';
		} else {
			// Exactly three stars; reveal half the local part, capped at five visible chars.
			$visible = min( 5, max( 1, intdiv( $length, 2 ) ) );
			$masked  = substr( $local, 0, $visible ) . '***';
		}
		return $masked . '@' . $domain;
	}
}
