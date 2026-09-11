<?php
namespace Peyvast\Auth\Presentation\Rest;
use Peyvast\Auth\Domain\Security\Guard;
defined( 'ABSPATH' ) || exit;
final class SecurityController {
	public static function status( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		// Status is derived from server/IP state only; any client-supplied token is ignored.
		return RestResponse::success( Guard::status_details() );
	}
}
