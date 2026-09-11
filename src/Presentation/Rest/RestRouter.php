<?php
namespace Peyvast\Auth\Presentation\Rest;

use Peyvast\Auth\Infrastructure\WordPress\GuestSession;

defined( 'ABSPATH' ) || exit;

final class RestRouter {

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/** Route-level nonce + origin gate; controllers keep their own checks. */
	public static function public_permission( \WP_REST_Request $request ) {
		return self::guard_permission( $request, 'peyvast_auth' );
	}

	public static function admin_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'default' ), array( 'status' => 403 ) );
		}
		return self::guard_permission( $request, 'peyvast_auth_settings' );
	}

	private static function guard_permission( \WP_REST_Request $request, string $action ) {
		if ( 'peyvast_auth' === $action && ! RestResponse::same_origin( $request ) ) {
			return new \WP_Error( 'invalid_request', __( 'Invalid request.', 'peyvast-auth' ), array( 'status' => 403 ) );
		}
		GuestSession::ensure();
		$nonce = $request->get_param( 'nonce' );
		$nonce = is_scalar( $nonce ) ? sanitize_text_field( wp_unslash( (string) $nonce ) ) : '';
		return ( $nonce === '' || ! wp_verify_nonce( $nonce, $action ) )
			? new \WP_Error( 'invalid_request', __( 'Invalid request.', 'peyvast-auth' ), array( 'status' => 403 ) )
			: true;
	}

	private static function text(): array {
		return array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	private static function secret(): array {
		return array( 'type' => 'string' );
	}

	public static function register(): void {
		$nonce = self::text();

		$public = array(
			'send-otp' => array(
				'callback' => array( AuthController::class, 'send_otp' ),
				'args'     => array(
					'nonce'        => $nonce,
					'identifier'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'purpose'      => array(
						'type'    => 'string',
						'enum'    => array( 'otp_login', 'password_reset' ),
						'default' => 'otp_login',
					),
					'force_resend' => array( 'type' => 'string', 'enum' => array( '0', '1' ) ),
				),
			),
			'delivery-status' => array(
				'callback' => array( AuthController::class, 'delivery_status' ),
				'args'     => array(
					'nonce'        => $nonce,
					'challenge_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			'verify-otp' => array(
				'callback' => array( AuthController::class, 'verify_otp' ),
				'args'     => array(
					'nonce'        => $nonce,
					'challenge_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'code'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			'otp-login' => array(
				'callback' => array( AuthController::class, 'otp_login' ),
				'args'     => array(
					'nonce'              => $nonce,
					'verification_token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'remember'           => array( 'type' => 'boolean' ),
					'return_to'          => self::text(),
				),
			),
			'register' => array(
				'callback' => array( AuthController::class, 'register' ),
				'args'     => array(
					'nonce'              => $nonce,
					'phone'              => self::text(),
					'verification_token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'first_name'         => self::text(),
					'last_name'          => self::text(),
					'email'              => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
					'password'           => self::secret(),
					'return_to'          => self::text(),
				),
			),
			'password-login' => array(
				'callback' => array( AuthController::class, 'password_login' ),
				'args'     => array(
					'nonce'       => $nonce,
					'identifier'  => self::text(),
					'password'    => array( 'type' => 'string', 'required' => true ),
					'remember'    => array( 'type' => 'boolean' ),
					'return_to'   => self::text(),
				),
			),
			'reset-password' => array(
				'callback' => array( AuthController::class, 'reset_password' ),
				'args'     => array(
					'nonce'              => $nonce,
					'verification_token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'password'           => array( 'type' => 'string', 'required' => true ),
					'return_to'          => self::text(),
				),
			),
			'google-login' => array(
				'callback' => array( AuthController::class, 'google_login' ),
				'args'     => array(
					'nonce'        => $nonce,
					'credential'   => array( 'type' => 'string', 'required' => true ),
					'google_nonce' => self::text(),
					'remember'     => array( 'type' => 'boolean' ),
					'return_to'    => self::text(),
				),
			),
			'logout' => array(
				'callback' => array( AuthController::class, 'logout' ),
				'args'     => array(
					'nonce'     => $nonce,
					'return_to' => self::text(),
				),
			),
			'security-status' => array(
				'callback' => array( SecurityController::class, 'status' ),
				'args'     => array( 'nonce' => $nonce ),
			),
		);

		foreach ( $public as $route => $config ) {
			register_rest_route(
				'peyvast-auth/v1',
				'/' . $route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $config['callback'],
					'permission_callback' => array( self::class, 'public_permission' ),
					'args'                => $config['args'],
				)
			);
		}

		register_rest_route(
			'peyvast-auth/v1',
			'/test-provider',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( ProviderTestController::class, 'test' ),
				'permission_callback' => array( self::class, 'admin_permission' ),
				'args'                => array(
					'nonce'    => $nonce,
					'channel'  => array( 'type' => 'string', 'enum' => array( 'sms', 'email' ), 'default' => 'sms' ),
					'provider' => array( 'type' => 'string', 'enum' => array( 'kavenegar', 'melipayamak', 'msgway', 'smsir', 'ippanel', 'ippanel_username_password', 'none' ) ),
					'phone'    => self::text(),
					'email'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
				),
			)
		);

		$admin = array(
			'settings'            => array(
				'callback' => array( AdminSettingsController::class, 'settings' ),
				'args'     => array(
					'nonce'               => $nonce,
					'peyvast_auth_settings' => array(
						'type'                 => 'object',
						'required'             => true,
						'additionalProperties' => false,
						'properties'           => array(
							// Nested settings are validated authoritatively by Settings::sanitize().
							'general'        => array( 'type' => 'object' ),
							'registration'   => array( 'type' => 'object' ),
							'authentication' => array( 'type' => 'object' ),
							'otp'            => array( 'type' => 'object' ),
							'phone'          => array( 'type' => 'object' ),
							'woocommerce'    => array( 'type' => 'object' ),
							'providers'      => array( 'type' => 'object' ),
							'migration'      => array( 'type' => 'object' ),
							'security'       => array( 'type' => 'object' ),
							'logging'        => array( 'type' => 'object' ),
							'integrations'   => array( 'type' => 'object' ),
							'redirect'       => array( 'type' => 'object' ),
							'google'         => array( 'type' => 'object' ),
						),
					),
				),
			),
			'security-blocks'     => array(
				'callback' => array( AdminSettingsController::class, 'security_blocks' ),
				'args'     => array( 'nonce' => $nonce ),
			),
			'migrate-user-phone'  => array(
				'callback' => array( AdminSettingsController::class, 'migrate' ),
				'args'     => array( 'nonce' => $nonce ),
			),
			'migration-status'    => array(
				'callback' => array( AdminSettingsController::class, 'migration_status' ),
				'args'     => array( 'nonce' => $nonce ),
			),
			'migration-notice'    => array(
				'callback' => array( AdminSettingsController::class, 'migration_notice' ),
				'args'     => array( 'nonce' => $nonce ),
			),
			'validate-phone-meta' => array(
				'callback' => array( AdminSettingsController::class, 'validate_phone_meta' ),
				'args'     => array(
					'nonce'    => $nonce,
					'meta_key' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		);

		foreach ( $admin as $route => $config ) {
			register_rest_route(
				'peyvast-auth/v1',
				'/' . $route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $config['callback'],
					'permission_callback' => array( self::class, 'admin_permission' ),
					'args'                => $config['args'],
				)
			);
		}
	}
}
