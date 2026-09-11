<?php
namespace Peyvast\Auth\Domain\Authentication;

use Peyvast\Auth\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class AuthenticationCapabilities {
	public static function resolve( ?array $settings = null ): array {
		$settings        = $settings ?? Settings::all();
		$otp             = (array) ( $settings['authentication']['otp_login'] ?? array() );
		$password        = (array) ( $settings['authentication']['password_login'] ?? array() );
		$reset           = (array) ( $settings['authentication']['password_reset'] ?? array() );
		// Phone OTP sign-in is a hard invariant; registration depends on it.
		$otpPhone        = true;
		$smsDelivery      = ! empty( $settings['providers']['sms']['active'] ) && $settings['providers']['sms']['active'] !== 'none';
		$otpEmail        = ! empty( $otp['email'] );
		$passwordEnabled = ! empty( $password['enabled'] );
		$passwordPhone   = $passwordEnabled && ! empty( $password['phone'] );
		$passwordEmail   = $passwordEnabled && ! empty( $password['email'] );
		$resetEnabled    = ! empty( $reset['enabled'] );
		$resetPhone      = $resetEnabled && ! empty( $reset['phone'] );
		$resetEmail      = $resetEnabled && ! empty( $reset['email'] );
		return array(
			'otp_login_phone_enabled'     => $otpPhone,
			'otp_login_email_enabled'     => $otpEmail,
			'password_enabled'            => $passwordEnabled,
			'password_phone_enabled'      => $passwordPhone,
			'password_email_enabled'      => $passwordEmail,
			'password_user_login_enabled' => $passwordEnabled && ! empty( $password['user_login'] ),
			'reset_enabled'               => $resetEnabled,
			'reset_phone_enabled'         => $resetPhone,
			'reset_email_enabled'         => $resetEmail,
			'phone_otp_enabled'           => $otpPhone,
			'phone_otp_delivery_enabled'  => $smsDelivery,
			'email_otp_enabled'           => $otpEmail,
			'available_identifiers'       => array(
				'otp_login'      => self::identifiers( $otpPhone, $otpEmail ),
				'password_login' => self::identifiers( $passwordPhone, $passwordEmail, $passwordEnabled && ! empty( $password['user_login'] ) ? array( 'user_login' ) : array() ),
				'password_reset' => self::identifiers( $resetPhone, $resetEmail ),
			),
		);
	}

	public static function field( string $purpose, ?array $settings = null ): array {
		$c           = self::resolve( $settings );
		$purpose     = $purpose === 'password_reset' ? 'password_reset' : ( $purpose === 'password_login' ? 'password_login' : 'otp_login' );
		$identifiers = $c['available_identifiers'][ $purpose ];
		$hasPhone    = in_array( 'phone', $identifiers, true );
		$hasEmail    = in_array( 'email', $identifiers, true );
		$hasUsername = in_array( 'user_login', $identifiers, true );
		if ( ! $identifiers ) {
			return array(
				'enabled'      => false,
				'type'         => 'text',
				'inputmode'    => 'text',
				'autocomplete' => '',
				'label'        => __( 'Authentication identifier unavailable', 'peyvast-auth' ),
				'placeholder'  => __( 'No authentication method is currently enabled', 'peyvast-auth' ),
				'identifiers'  => array(),
			);
		}
		if ( $hasPhone && $hasEmail ) {
			$type        = 'text';
			$inputmode   = 'text';
			$label       = __( 'Mobile number or email', 'peyvast-auth' );
			$placeholder = __( 'Enter your mobile number or email address', 'peyvast-auth' ); } elseif ( $hasEmail ) {
			$type        = 'email';
			$inputmode   = 'email';
			$label       = __( 'Email address', 'peyvast-auth' );
			$placeholder = __( 'Enter your email address', 'peyvast-auth' ); } elseif ( $hasPhone ) {
				$type        = 'tel';
				$inputmode   = 'tel';
				$label       = __( 'Mobile number', 'peyvast-auth' );
				$placeholder = __( 'Enter your mobile number', 'peyvast-auth' ); } else {
				$type        = 'text';
				$inputmode   = 'text';
				$label       = __( 'Username', 'peyvast-auth' );
				$placeholder = __( 'Enter your username', 'peyvast-auth' ); }
				return array(
					'enabled'      => true,
					'type'         => $type,
					'inputmode'    => $inputmode,
					'autocomplete' => $hasUsername && ! $hasPhone && ! $hasEmail ? 'username' : '',
					'label'        => $label,
					'placeholder'  => $placeholder,
					'identifiers'  => $identifiers,
				);
	}

	/** Short translatable phrase for the flow's enabled methods ({method} placeholder). */
	public static function method_text( string $purpose, ?array $settings = null ): string {
		$field       = self::field( $purpose, $settings );
		$identifiers = is_array( $field['identifiers'] ?? null ) ? $field['identifiers'] : array();
		$hasPhone    = in_array( 'phone', $identifiers, true );
		$hasEmail    = in_array( 'email', $identifiers, true );
		if ( $hasPhone && $hasEmail ) {
			return __( 'Mobile number or email', 'peyvast-auth' );
		}
		if ( $hasPhone ) {
			return __( 'Mobile number', 'peyvast-auth' );
		}
		if ( $hasEmail ) {
			return __( 'Email address', 'peyvast-auth' );
		}
		return __( 'Automatically', 'peyvast-auth' );
	}

	/** Replace every {method} placeholder with the flow's method phrase. */
	public static function apply_method_placeholders( string $text, string $purpose, ?array $settings = null ): string {
		return str_replace( '{method}', self::method_text( $purpose, $settings ), $text );
	}

	private static function identifiers( bool $phone, bool $email, array $extra = array() ): array {
		$result = array();
		if ( $phone ) {
			$result[] = 'phone';
		}
		if ( $email ) {
			$result[] = 'email';
		}
		foreach ( $extra as $identifier ) {
			if ( ! in_array( $identifier, $result, true ) ) {
				$result[] = $identifier;
			}
		}
		return $result;
	}
}
