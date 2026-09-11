<?php
namespace Peyvast\Auth\Presentation\Rest;

use Peyvast\Auth\Application\Authentication\AuthenticationSessionService;
use Peyvast\Auth\Application\Authentication\GoogleAuthenticationService;
use Peyvast\Auth\Application\OTP\OtpService;
use Peyvast\Auth\Application\Password\PasswordService;
use Peyvast\Auth\Application\Registration\RegistrationService;
use Peyvast\Auth\Domain\Identity\IdentityResolver;
use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Domain\Security\Guard;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Authentication\AuthenticationCapabilities;
use Peyvast\Auth\Infrastructure\WordPress\GuestSession;

defined( 'ABSPATH' ) || exit;

final class AuthController {
	private static function input( \WP_REST_Request $request, string $key, string $default = '' ): string {
		$value = $request->get_param( $key );
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
	}
	private static function password( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}
	private static function services(): array {
		static $services;
		if ( $services ) {
			return $services;
		}
		$identities      = new IdentityResolver();
		$session         = new AuthenticationSessionService( $identities );
		return $services = array(
			'identities'   => $identities,
			'session'      => $session,
			'otp'          => new OtpService( $identities, new OtpChallengeStore() ),
			'password'     => new PasswordService( $identities, $session ),
			'registration' => new RegistrationService( $identities, $session ),
			'google'       => new GoogleAuthenticationService( $identities, $session ),
		);
	}
	private static function enabled(): ?\WP_REST_Response {
		return Settings::get( 'general.enabled', true ) ? null : RestResponse::error( 'auth_disabled', __( 'Authentication is currently unavailable. Please try again later.', 'peyvast-auth' ), 503 );
	}
	private static function guard( array $result ): ?\WP_REST_Response {
		return $result['allowed'] ? null : RestResponse::from_guard( $result ); }
	private static function identifier( string $value, bool $email = true, bool $user_login = false ): array {
		$value = trim( $value );
		if ( $value === '' ) {
			return array(
				'type'  => 'invalid',
				'value' => '',
			);
		}
		if ( strpos( $value, '@' ) !== false ) {
			$email_value = strtolower( sanitize_email( $value ) );
			return $email && is_email( $email_value ) ? array(
				'type'  => 'email',
				'value' => $email_value,
			) : array(
				'type'  => 'invalid_email',
				'value' => $email_value,
			); }
		if ( PhoneNumber::valid_input( $value ) ) {
			return array(
				'type'  => 'phone',
				'value' => PhoneNumber::canonical_value( $value ),
			);
		}
		if ( $user_login ) {
			$login = sanitize_user( $value, true );
			if ( $login !== '' && strlen( $login ) <= 60 ) {
				return array(
					'type'  => 'user_login',
					'value' => $login,
				);
			}
		}
		return array(
			'type'  => 'invalid_phone',
			'value' => '',
		);
	}
	public static function send_otp( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$purpose = sanitize_key( self::input( $request, 'purpose', 'otp_login' ) );
		if ( ! in_array( $purpose, array( 'otp_login', 'password_reset' ), true ) ) {
			return RestResponse::error( 'invalid_request', __( 'This authentication request is not available.', 'peyvast-auth' ) );
		}
		$settings     = Settings::all();
		$identifier   = self::input( $request, 'identifier' );
		$capabilities = AuthenticationCapabilities::resolve( $settings );
		$path         = $purpose === 'password_reset' ? ( $settings['authentication']['password_reset'] ?? array() ) : ( $settings['authentication']['otp_login'] ?? array() );
		$allowed      = $capabilities['available_identifiers'][ $purpose === 'password_reset' ? 'password_reset' : 'otp_login' ];
		$resolved     = self::identifier( $identifier, in_array( 'email', $allowed, true ), $purpose === 'otp_login' && in_array( 'user_login', $allowed, true ) );
		if ( $purpose === 'password_reset' && $resolved['type'] === 'user_login' ) {
			return RestResponse::error( 'invalid_identifier', __( 'Unable to process the password reset request.', 'peyvast-auth' ), 400, array( 'field' => 'identifier' ) );
		}
		if ( in_array( $resolved['type'], array( 'invalid', 'invalid_email', 'invalid_phone' ), true ) ) {
			$guard = Guard::evaluate( 'invalid_identifier', array( 'identifier' => $identifier ) );
			if ( ! $guard['allowed'] ) {
				return self::guard( $guard );
			}
			return RestResponse::error( 'invalid_identifier', ! empty( $path['email'] ) ? __( 'Please enter a valid mobile number or email address.', 'peyvast-auth' ) : __( 'Please enter a valid Iranian mobile number.', 'peyvast-auth' ), 400, array( 'field' => 'identifier' ) );
		}
		// Canonical value drives identity resolution; the original request value is kept for safe presentation.
		$result = self::services()['otp']->send( self::input( $request, 'identifier' ), $purpose, self::input( $request, 'force_resend' ) === '1' );
		if ( is_wp_error( $result ) ) {
			return RestResponse::from_wp_error( $result, 400, in_array( $result->get_error_code(), array( 'invalid_identifier' ), true ) ? array( 'field' => 'identifier' ) : array() );
		}
		return RestResponse::success( $result );
	}
	public static function delivery_status( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$result = self::services()['otp']->delivery_status( self::input( $request, 'challenge_id' ) );
		return is_wp_error( $result ) ? RestResponse::from_wp_error( $result ) : RestResponse::success( $result );
	}

	public static function verify_otp( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$challenge = self::input( $request, 'challenge_id' );
		$code      = self::input( $request, 'code' );
		if ( $challenge === '' || $code === '' ) {
			return RestResponse::error( 'required', __( 'Please enter the complete verification code.', 'peyvast-auth' ), 400, array( 'field' => 'otp' ) );
		}
		$result = self::services()['otp']->verify( $challenge, $code );
		return is_wp_error( $result ) ? RestResponse::from_wp_error( $result, 400, in_array( $result->get_error_code(), array( 'invalid_otp', 'otp_expired', 'otp_used', 'required' ), true ) ? array( 'field' => 'otp' ) : array() ) : RestResponse::success( $result );
	}
	public static function otp_login( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$remember = filter_var( $request->get_param( 'remember' ), FILTER_VALIDATE_BOOLEAN );
		$result = self::services()['session']->consume_otp_login( self::input( $request, 'verification_token' ), $remember );
		return is_wp_error( $result ) ? RestResponse::from_wp_error( $result ) : RestResponse::success( array( 'redirect' => self::services()['session']->redirect( self::input( $request, 'return_to' ) ) ) );
	}
	public static function register( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$phone = self::input( $request, 'phone' );
		$guard = Guard::evaluate( Guard::SCOPE_REGISTRATION, array( 'identifier' => $phone ) );
		if ( ! $guard['allowed'] ) {
			return self::guard( $guard );
		}
		$data   = array(
			'first_name' => self::input( $request, 'first_name' ),
			'last_name'  => self::input( $request, 'last_name' ),
			'email'     => sanitize_email( self::input( $request, 'email' ) ),
			'password'  => self::password( $request, 'password' ),
		);
		$result = self::services()['registration']->execute( $data, $phone, self::input( $request, 'verification_token' ) );
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_data( $result->get_error_code() );
			$field = is_array( $error ) ? ( $error['field'] ?? '' ) : '';
			if ( ! $field && $result->get_error_code() === 'weak_password' ) {
				$field = 'password';
			} return RestResponse::from_wp_error( $result, 400, $field ? array( 'field' => $field ) : array() ); }
		return RestResponse::success( array( 'redirect' => self::services()['session']->redirect( self::input( $request, 'return_to' ) ) ) );
	}
	public static function password_login( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$result = self::services()['password']->login( self::input( $request, 'identifier' ), self::password( $request, 'password' ), filter_var( $request->get_param( 'remember' ), FILTER_VALIDATE_BOOLEAN ) );
		return is_wp_error( $result ) ? RestResponse::from_wp_error( $result ) : RestResponse::success( array( 'redirect' => self::services()['session']->redirect( self::input( $request, 'return_to' ) ) ) );
	}
	public static function reset_password( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$result = self::services()['otp']->preview( self::input( $request, 'verification_token' ), 'password_reset' );
		if ( ! $result || ! (int) $result->user_id ) {
			return RestResponse::error( 'invalid_token', __( 'The password reset session is no longer valid. Please start again.', 'peyvast-auth' ) );
		}
		$user = get_user_by( 'id', (int) $result->user_id );
		if ( ! $user instanceof \WP_User ) {
			return RestResponse::error( 'invalid_token', __( 'The password reset session is no longer valid. Please start again.', 'peyvast-auth' ) );
		}
		$password = self::password( $request, 'password' );
		$out      = self::services()['password']->reset( $user, $password, self::input( $request, 'verification_token' ) );
		if ( is_wp_error( $out ) ) {
			return RestResponse::from_wp_error( $out, 400, $out->get_error_code() === 'weak_password' ? array( 'field' => 'new_password' ) : array() );
		}
		// Password reset must not authenticate; return to the password stage to sign in.
		return RestResponse::success(
			array(
				'redirect'   => '',
				'next_stage' => 'password',
			)
		);
	}
	public static function google_login( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		}
		if ( $response = self::enabled() ) {
			return $response;
		}
		$guard = Guard::evaluate( Guard::SCOPE_GOOGLE );
		if ( ! $guard['allowed'] ) {
			return self::guard( $guard );
		}
		$result = self::services()['google']->execute(
			self::input( $request, 'credential' ),
			filter_var( $request->get_param( 'remember' ), FILTER_VALIDATE_BOOLEAN ),
			self::input( $request, 'google_nonce' )
		);
		return is_wp_error( $result ) ? RestResponse::from_wp_error( $result ) : RestResponse::success( array( 'redirect' => self::services()['session']->redirect( self::input( $request, 'return_to' ) ) ) );
	}
	public static function logout( \WP_REST_Request $request ) {
		if ( $response = RestResponse::require_nonce( $request, 'peyvast_auth' ) ) {
			return $response;
		} if ( $response = self::enabled() ) {
			return $response;
		} if ( is_user_logged_in() ) {
			wp_logout();
		} GuestSession::rotate(); return RestResponse::success( array( 'redirect' => home_url( '/' ) ) ); }
}
