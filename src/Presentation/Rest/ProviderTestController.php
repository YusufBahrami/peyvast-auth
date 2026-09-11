<?php
namespace Peyvast\Auth\Presentation\Rest;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Domain\Security\Guard;
use Peyvast\Auth\Infrastructure\Providers\ProviderManager as Providers;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Presentation\Privacy\EmailMasker;
defined( 'ABSPATH' ) || exit;
final class ProviderTestController {
	public static function test( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth_settings' ) ) {
			return $response;
		}
		$guard = Guard::evaluate( Guard::SCOPE_PROVIDER_TEST );
		if ( ! $guard['allowed'] ) {
			return RestResponse::from_guard( $guard );
		}
		$channel = sanitize_key( (string) $request->get_param( 'channel' ) );
		if ( $channel === 'email' ) {
			$email = sanitize_email( (string) $request->get_param( 'email' ) );
			if ( ! $email || ! is_email( $email ) ) {
				return RestResponse::error( 'invalid_email', __( 'Please enter a valid email address.', 'peyvast-auth' ) );
			}
			Logger::notice( 'provider', 'provider_test_started', 'Provider test initiated.', array( 'provider' => 'WordPress', 'recipient_masked' => EmailMasker::mask( $email ) ), get_current_user_id() );
			$length     = max( 4, min( 8, (int) Settings::get( 'otp.length', 6 ) ) );
			$otp        = str_pad( (string) random_int( 0, ( 10 ** $length ) - 1 ), $length, '0', STR_PAD_LEFT );
			$request_id = Logger::request_id();
			$result     = Providers::send_email( $email, $otp, array( 'request_id' => $request_id, 'purpose' => 'admin_test', 'user_id' => get_current_user_id() ) );
			if ( ! $result->success ) {
				return RestResponse::error( 'provider_test_failed', __( 'Email provider test failed. Check Operational Logs for diagnostic details.', 'peyvast-auth' ) );
			}
			return RestResponse::success( array( 'message' => __( 'Test email sent successfully.', 'peyvast-auth' ) ), 'admin' );
		}
		$phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		if ( ! PhoneNumber::valid_input( $phone ) ) {
			return RestResponse::error( 'invalid_phone', __( 'Please enter a valid Iranian mobile number.', 'peyvast-auth' ) );
		}
		$active  = sanitize_key( (string) $request->get_param( 'provider' ) );
		$allowed = array( 'kavenegar', 'melipayamak', 'msgway', 'smsir', 'ippanel', 'ippanel_username_password', 'none' );
		if ( ! in_array( $active, $allowed, true ) ) {
			$active = (string) Settings::get( 'providers.sms.active', 'none' );
		}
		if ( $active === 'none' ) {
			return RestResponse::error( 'provider_not_configured', __( 'No SMS provider is selected.', 'peyvast-auth' ) );
		}
		Logger::notice( 'provider', 'provider_test_started', 'Provider test initiated.', array( 'provider' => $active, 'recipient_masked' => PhoneNumber::mask_value( $phone ) ), get_current_user_id() );
		$length     = max( 4, min( 8, (int) Settings::get( 'otp.length', 6 ) ) );
		$otp        = str_pad( (string) random_int( 0, ( 10 ** $length ) - 1 ), $length, '0', STR_PAD_LEFT );
		$request_id = Logger::request_id();
		$result     = Providers::send_sms_with_provider(
			$active,
			PhoneNumber::canonical_value( $phone ),
			$otp,
			array(
				'request_id' => $request_id,
				'purpose'    => 'admin_test',
				'user_id'    => get_current_user_id(),
			)
		);
		if ( ! $result->success ) {
			return RestResponse::error( 'provider_test_failed', __( 'Provider test failed. Check Operational Logs for diagnostic details.', 'peyvast-auth' ) );
		}
		return RestResponse::success( array( 'message' => __( 'Test message sent successfully.', 'peyvast-auth' ) ), 'admin' );
	}
}