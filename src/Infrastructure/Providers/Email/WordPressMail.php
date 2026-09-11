<?php
namespace Peyvast\Auth\Infrastructure\Providers\Email;

use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Infrastructure\Providers\ProviderInterface;
use Peyvast\Auth\Infrastructure\Providers\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class WordPressMail implements ProviderInterface {
	public function id(): string {
		return 'WordPress'; }
	public function send( string $recipient, string $otp, array $context = array() ): ProviderResult {
		$email = sanitize_email( $recipient );
		if ( ! $email || ! is_email( $email ) ) {
			return new ProviderResult( false, $this->id(), 'Invalid recipient' );
		}

		$expiration = max( 1, (int) ceil( ( (int) Settings::get( 'otp.expiration_seconds', 300 ) ) / 60 ) );
		$site       = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$site       = $site !== '' ? $site : __( 'Your website', 'peyvast-auth' );
		$subject    = trim( (string) Settings::get( 'providers.email.subject', '' ) );
		$body       = trim( (string) Settings::get( 'providers.email.body', '' ) );
		if ( $subject === '' ) {
			$subject = Settings::default_email_subject();
		}
		if ( $body === '' ) {
			$body = Settings::default_email_body();
		}

		$replace = array(
			// Email is intentionally not used as a Web OTP transport (origin-bound SMS format).
			'{otp}'        => esc_html( $otp ),
			'{expiration}' => esc_html( (string) $expiration ),
			'{site_name}'  => esc_html( $site ),
			'{email}'      => esc_html( $email ),
			'{identifier}' => esc_html( (string) ( $context['identifier'] ?? $email ) ),
		);
		$subject = strtr( wp_strip_all_tags( $subject ), array_map( 'wp_specialchars_decode', $replace ) );
		$body    = strtr( $body, $replace );
		$body = $this->ensure_html_document( $body );

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$fromName = trim( (string) Settings::get( 'providers.email.sender_name', '' ) ) ?: Settings::default_email_sender_name();
		$from     = sanitize_email( (string) Settings::get( 'providers.email.sender_address', '' ) ) ?: Settings::default_email_sender_address();
		if ( $from && is_email( $from ) ) {
			$headers[] = 'From: ' . sanitize_text_field( $fromName ) . ' <' . $from . '>';
			$headers[] = 'Reply-To: ' . $from;
		}
		$safe_body    = str_replace( (string) $otp, '[redacted]', $body );
		$safe_subject = str_replace( (string) $otp, '[redacted]', $subject );
		$sent         = wp_mail( $email, $subject, $body, $headers );
		return new ProviderResult(
			(bool) $sent,
			$this->id(),
			$sent ? '' : 'wp_mail returned false',
			array(
				'request'   => array(
					'method'    => 'WP Mail',
					'recipient' => $email,
					'subject'   => $safe_subject,
					'headers'   => $headers,
					'body'      => $safe_body,
				),
				'response'  => array( 'mail_sent' => (bool) $sent ),
				'mail_sent' => (bool) $sent,
			)
		);
	}
	/**
	 * Keep the stored template as the source of truth. Only add a document shell
	 * when the administrator supplied an HTML fragment; never impose a Persian
	 * locale or RTL direction on a custom template.
	 */
	private function ensure_html_document( string $body ): string {
		if ( preg_match( '/<\s*html(?:\s|>)/i', $body ) ) {
			return $body;
		}

		$locale    = get_locale();
		$language  = $locale !== '' ? str_replace( '_', '-', $locale ) : 'en-US';
		$direction = is_rtl() ? 'rtl' : 'ltr';
		$align     = is_rtl() ? 'right' : 'left';

		return '<!doctype html><html lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head><body style="margin:0;background:#f3f4f6;font-family:Arial,Tahoma,sans-serif;direction:' . esc_attr( $direction ) . ';text-align:' . esc_attr( $align ) . ';color:#111827">' . $body . '</body></html>';
	}

}
