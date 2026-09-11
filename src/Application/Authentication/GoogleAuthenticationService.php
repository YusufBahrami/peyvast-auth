<?php
namespace Peyvast\Auth\Application\Authentication;

use Peyvast\Auth\Application\Authentication\AuthenticationSessionService;
use Peyvast\Auth\Core\Config\Settings;
use Peyvast\Auth\Domain\Identity\IdentityResolver;
use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\WordPress\GuestSession;

defined( 'ABSPATH' ) || exit;

/**
 * Local verification of Google ID tokens: RS256 signature checked with
 * OpenSSL against Google's published JWKS keys, cached in a transient.
 */
final class GoogleAuthenticationService {
	private const JWKS_URL       = 'https://www.googleapis.com/oauth2/v3/certs';
	private const JWKS_TRANSIENT = 'peyvast_auth_google_jwks';

	private $identities;
	private $session;

	public function __construct( ?IdentityResolver $identities = null, ?AuthenticationSessionService $session = null ) {
		$this->identities = $identities ?: new IdentityResolver();
		$this->session    = $session ?: new AuthenticationSessionService( $this->identities );
	}

	public static function issue_nonce(): string {
		GuestSession::ensure();
		$nonce = wp_generate_password( 48, false, false );
		set_transient( 'peyvast_auth_google_nonce_' . hash( 'sha256', $nonce ), array( 'guest_session' => GuestSession::hash(), 'expires_at' => time() + 300 ), 5 * MINUTE_IN_SECONDS );
		return $nonce;
	}

	private static function consume_nonce( string $nonce ): bool {
		$lock = DatabaseLock::acquire( 'google-nonce:' . hash( 'sha256', $nonce ), 5 );
		if ( ! $lock ) return false;
		try {
			$key = 'peyvast_auth_google_nonce_' . hash( 'sha256', $nonce );
			$state = get_transient( $key );
			if ( ! is_array( $state ) || (int) ( $state['expires_at'] ?? 0 ) < time() ) { delete_transient( $key ); return false; }
			if ( ! hash_equals( (string) ( $state['guest_session'] ?? '' ), GuestSession::hash() ) ) return false;
			delete_transient( $key );
			return true;
		} finally { $lock->release(); }
	}

	public function execute( $credential, bool $remember = false, string $nonce = '' ) {
		$client_id = (string) Settings::get( 'google.client_id', '' );
		$token     = (string) $credential;

		// Structural validation before any network or crypto work.
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 || $client_id === '' || strlen( $token ) > 16384 || $nonce === '' ) {
			return self::google_error();
		}
		[$header_b64, $payload_b64, $signature_b64] = $parts;
		$header                                     = json_decode( self::base64url_decode( $header_b64 ), true );
		$payload                                    = json_decode( self::base64url_decode( $payload_b64 ), true );
		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			return self::google_error();
		}

		// Only RS256-signed tokens are accepted; the kid selects the key.
		if ( ( $header['alg'] ?? '' ) !== 'RS256' || empty( $header['kid'] ) ) {
			return self::google_error();
		}

		$claims = self::claims( $payload, $client_id );
		if ( $claims === null || ! hash_equals( $nonce, (string) $claims['nonce'] ) ) return self::google_error();

		$signature = self::base64url_decode( $signature_b64 );
		if ( $signature === '' || ! function_exists( 'openssl_verify' ) ) {
			return self::google_error();
		}

		$pem = self::public_key_for( (string) $header['kid'] );
		if ( $pem === '' ) {
			Logger::error( 'google', 'google_verify_key_unavailable', 'No matching Google signing key was available for the ID token.', array( 'kid' => sanitize_text_field( (string) $header['kid'] ) ) );
			return self::google_error();
		}

		$verified = openssl_verify( $header_b64 . '.' . $payload_b64, $signature, $pem, OPENSSL_ALGO_SHA256 );
		if ( $verified !== 1 ) {
			Logger::error( 'google', 'google_verify_signature_failed', 'Google ID token signature could not be verified.', array() );
			return self::google_error();
		}

		$email   = $claims['email'];
		$subject = $claims['subject'];
		if ( ! self::consume_nonce( $nonce ) ) return self::google_error();

		$identity_hash = hash_hmac( 'sha256', $claims['issuer'] . '|' . $subject, (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) ) );
		$linked_user_id = self::find_linked_user( $identity_hash );
		if ( $linked_user_id > 0 ) {
			$user = get_user_by( 'id', $linked_user_id );
			if ( ! $user instanceof \WP_User ) return self::google_error();
			$login = $this->session->login( $user, $remember, 'google' );
			return is_wp_error( $login ) ? $login : $user;
		}

		$resolution = $this->identities->email( $email );
		if ( ! $resolution->is_existing() ) return new \WP_Error( 'google_account_not_found', __( 'No existing account matches this Google account. Please register with your mobile number first.', 'peyvast-auth' ) );
		$user = $resolution->user();
		if ( ! is_user_logged_in() || get_current_user_id() !== (int) $user->ID ) {
			return new \WP_Error( 'google_identity_unlinked', __( 'This Google account is not linked to this account yet. Sign in with your existing method first, then link Google from your account settings.', 'peyvast-auth' ) );
		}
		if ( ! self::store_link( (int) $user->ID, $identity_hash ) ) return self::google_error();
		$login = $this->session->login( $user, $remember, 'google' );
		return is_wp_error( $login ) ? $login : $user;
	}

	/** Validate the claims that gate sign-in. Returns normalized claims or null. */
	private static function claims( array $payload, string $client_id ): ?array {
		$issuer = (string) ( $payload['iss'] ?? '' );
		$aud_claim = $payload['aud'] ?? '';
		$audiences = is_array( $aud_claim ) ? array_values( array_map( 'strval', $aud_claim ) ) : array( (string) $aud_claim );
		$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$verified_email = filter_var( $payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$expires = absint( $payload['exp'] ?? 0 );
		$issued = absint( $payload['iat'] ?? 0 );
		$not_before = absint( $payload['nbf'] ?? 0 );
		$subject = sanitize_text_field( (string) ( $payload['sub'] ?? '' ) );
		$nonce = sanitize_text_field( (string) ( $payload['nonce'] ?? '' ) );
		$azp = sanitize_text_field( (string) ( $payload['azp'] ?? '' ) );
		$now = time();
		if ( ! in_array( $issuer, array( 'accounts.google.com', 'https://accounts.google.com' ), true ) ) return null;
		if ( $client_id === '' || ! in_array( $client_id, $audiences, true ) ) return null;
		if ( count( $audiences ) > 1 && ! hash_equals( $client_id, $azp ) ) return null;
		if ( $email === '' || ! $verified_email || $subject === '' || strlen( $subject ) > 256 || $nonce === '' || $expires <= $now || $issued <= 0 || $issued > $now + 60 || ( $not_before > 0 && $not_before > $now + 60 ) ) return null;
		return array( 'email' => $email, 'subject' => $subject, 'issuer' => $issuer, 'nonce' => $nonce );
	}

	/** Resolve the PEM public key for a kid; refresh the JWKS once on a miss. */
	private static function public_key_for( string $kid ): string {
		$pem = self::find_key( self::jwks(), $kid );
		if ( $pem !== '' ) {
			return $pem;
		}
		// On key miss, refresh into the cache first; a failed refresh must not
		// discard a still-valid cached key set.
		return self::find_key( self::jwks( true ), $kid );
	}

	private static function find_key( array $keys, string $kid ): string {
		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) ) {
				continue;
			}
			if ( ( $key['kty'] ?? '' ) !== 'RSA' ) {
				continue;
			}
			if ( ! hash_equals( (string) ( $key['kid'] ?? '' ), $kid ) ) {
				continue;
			}
			$pem = self::jwk_to_pem( (string) ( $key['n'] ?? '' ), (string) ( $key['e'] ?? '' ) );
			if ( $pem !== '' ) {
				return $pem;
			}
		}
		return '';
	}

	/** Google's public signing keys, cached in a transient. */
	private static function jwks( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::JWKS_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = wp_remote_get(
			self::JWKS_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			Logger::error( 'google', 'google_jwks_fetch_error', 'Google JWKS request failed.' );
			return array();
		}
		$status       = (int) wp_remote_retrieve_response_code( $response );
		$raw_body     = wp_remote_retrieve_body( $response );
		if ( strlen( (string) $raw_body ) > 262144 ) {
			Logger::error( 'google', 'google_jwks_response_too_large', 'Google JWKS response exceeded the permitted size.' );
			return array();
		}
		$body = json_decode( (string) $raw_body, true );
		$keys   = is_array( $body['keys'] ?? null ) ? array_values( array_filter( $body['keys'], 'is_array' ) ) : array();
		if ( $status !== 200 || ! $keys ) {
			Logger::error( 'google', 'google_jwks_fetch_error', 'Google JWKS endpoint returned an unexpected response.', array( 'status' => $status ) );
			return array();
		}

		// Respect Google's cache hints, bounded to [1h, 24h].
		$ttl           = HOUR_IN_SECONDS * 12;
		$cache_control = (string) wp_remote_retrieve_header( $response, 'cache-control' );
		if ( preg_match( '/max-age=(\d+)/i', $cache_control, $m ) ) {
			$ttl = max( HOUR_IN_SECONDS, min( DAY_IN_SECONDS, (int) $m[1] ) );
		}
		set_transient( self::JWKS_TRANSIENT, $keys, $ttl );
		return $keys;
	}

	/** Build an SPKI PEM public key (BEGIN PUBLIC KEY) from an RSA JWK (n, e). */
	private static function jwk_to_pem( string $n, string $e ): string {
		$modulus  = ltrim( self::base64url_decode( $n ), "\x00" );
		$exponent = ltrim( self::base64url_decode( $e ), "\x00" );
		if ( $modulus === '' || $exponent === '' ) {
			return '';
		}
		// PKCS#1 RSAPublicKey: SEQUENCE { INTEGER n, INTEGER e }
		$rsa = "\x30" . self::der_length( strlen( self::der_integer( $modulus ) ) + strlen( self::der_integer( $exponent ) ) ) . self::der_integer( $modulus ) . self::der_integer( $exponent );
		// SubjectPublicKeyInfo: SEQUENCE { SEQUENCE { OID rsaEncryption, NULL }, BIT STRING { RSAPublicKey } }
		$algorithm  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$bit_string = "\x03" . self::der_length( strlen( $rsa ) + 1 ) . "\x00" . $rsa;
		$spki       = "\x30" . self::der_length( strlen( $algorithm ) + strlen( $bit_string ) ) . $algorithm . $bit_string;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private static function der_integer( string $bytes ): string {
		if ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . self::der_length( strlen( $bytes ) ) . $bytes;
	}

	private static function der_length( int $length ): string {
		if ( $length < 0x80 ) {
			return chr( $length );
		}
		$bytes = '';
		while ( $length > 0 ) {
			$bytes    = chr( $length & 0xff ) . $bytes;
			$length >>= 8;
		}
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	private static function base64url_decode( string $value ): string {
		$value = strtr( $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( $pad > 0 ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $value, true );
		return $decoded === false ? '' : $decoded;
	}

	private static function find_linked_user( string $identity_hash ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s ORDER BY umeta_id ASC LIMIT 1", '_peyvast_auth_google_identity', $identity_hash ) );
	}

	private static function store_link( int $user_id, string $identity_hash ): bool {
		$lock = DatabaseLock::acquire( 'google-identity:' . $identity_hash, 5 );
		if ( ! $lock ) return false;
		try {
			$owner = self::find_linked_user( $identity_hash );
			if ( $owner > 0 && $owner !== $user_id ) return false;
			$current = (string) get_user_meta( $user_id, '_peyvast_auth_google_identity', true );
			if ( $current !== '' && ! hash_equals( $current, $identity_hash ) ) return false;
			if ( $current === $identity_hash ) return true;
			$result = update_user_meta( $user_id, '_peyvast_auth_google_identity', $identity_hash );
			return false !== $result && hash_equals( (string) get_user_meta( $user_id, '_peyvast_auth_google_identity', true ), $identity_hash );
		} finally { $lock->release(); }
	}

	private static function google_error() {
		return new \WP_Error( 'google_error', __( 'Google sign-in could not be verified. Please try again.', 'peyvast-auth' ) );
	}
}
