<?php
namespace Peyvast\Auth\Presentation\Privacy;

use Peyvast\Auth\Domain\Phone\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/** Mask phone numbers for non-subscribers (server-derived destinations). */
final class PhoneMasker {
	public static function mask( string $phone ): string {
		return PhoneNumber::mask_value( $phone );
	}
}