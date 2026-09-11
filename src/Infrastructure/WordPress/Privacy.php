<?php
namespace Peyvast\Auth\Infrastructure\WordPress;

use Peyvast\Auth\Domain\Phone\PhoneNumber;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}
	public static function register_exporter( array $exporters ): array {
		$exporters['peyvast-auth'] = array(
			'exporter_friendly_name' => __( 'Peyvast Auth', 'peyvast-auth' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}
	public static function register_eraser( array $erasers ): array {
		$erasers['peyvast-auth'] = array(
			'eraser_friendly_name' => __( 'Peyvast Auth', 'peyvast-auth' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}
	public static function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $user instanceof \WP_User || $page > 1 ) {
			return array( 'data' => array(), 'done' => true );
		}
		global $wpdb;
		$user_id = (int) $user->ID;
		$items   = array();
		$meta_keys = array(
			'_peyvast_auth_phone',
			'_peyvast_auth_phone_canonical',
			'_peyvast_auth_phone_recovery',
			'_peyvast_auth_migration_recovery',
			'_peyvast_auth_registration_recovery',
			'_peyvast_auth_google_identity',
		);
		foreach ( $meta_keys as $key ) {
			$value = get_user_meta( $user_id, $key, true );
			if ( $value === '' || $value === null || $value === array() ) {
				continue;
			}
			$items[] = array( 'name' => $key, 'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}

		$challenges = $wpdb->get_results( $wpdb->prepare(
			'SELECT challenge_id, identifier_type, user_id, purpose, channels, expires_at, resend_available_at, attempts, verified_at, consumed_at, created_at, updated_at FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE user_id=%d ORDER BY id ASC LIMIT 500',
			$user_id
		) );
		foreach ( (array) $challenges as $row ) {
			$items[] = array(
				'name'  => __( 'OTP challenge', 'peyvast-auth' ),
				'value' => wp_json_encode( array(
					'challenge_id' => (string) $row->challenge_id,
					'identifier_type' => (string) $row->identifier_type,
					'purpose' => (string) $row->purpose,
					'channels' => (string) $row->channels,
					'attempts' => (int) $row->attempts,
					'expires_at' => (string) $row->expires_at,
					'resend_available_at' => (string) $row->resend_available_at,
					'verified_at' => (string) $row->verified_at,
					'consumed_at' => (string) $row->consumed_at,
					'created_at' => (string) $row->created_at,
					'updated_at' => (string) $row->updated_at,
				) ),
			);
		}

		$logs = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, level, channel, event, provider, request_id, message, context, created_at FROM ' . PEYVAST_AUTH_LOG_TABLE . ' WHERE user_id=%d ORDER BY id ASC LIMIT 500',
			$user_id
		) );
		foreach ( (array) $logs as $row ) {
			$items[] = array(
				'name'  => __( 'Peyvast Auth log', 'peyvast-auth' ),
				'value' => wp_json_encode( array(
					'id' => (int) $row->id,
					'level' => (string) $row->level,
					'channel' => (string) $row->channel,
					'event' => (string) $row->event,
					'provider' => (string) $row->provider,
					'request_id' => (string) $row->request_id,
					'message' => (string) $row->message,
					'context' => (string) $row->context,
					'created_at' => (string) $row->created_at,
				) ),
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'peyvast-auth',
					'group_label' => __( 'Peyvast Auth', 'peyvast-auth' ),
					'item_id'     => 'peyvast-auth-' . $user_id,
					'data'        => $items,
				),
			),
			'done' => true,
		);
	}

	public static function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $user instanceof \WP_User ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		global $wpdb;
		$user_id = (int) $user->ID;
		$phone   = (string) get_user_meta( $user_id, '_peyvast_auth_phone', true );
		$email_value = strtolower( (string) $user->user_email );
		$key = (string) get_option( 'peyvast_auth_identifier_key', wp_salt( 'auth' ) );
		$identifier_hashes = array();
		if ( $email_value !== '' ) {
			$identifier_hashes[] = hash_hmac( 'sha256', 'email:' . $email_value, $key );
		}
		$canonical_phone = PhoneNumber::canonical_value( $phone );
		if ( $canonical_phone !== '' ) {
			$identifier_hashes[] = hash_hmac( 'sha256', 'phone:' . $canonical_phone, $key );
		}

		$challenge_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT challenge_id FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE user_id=%d', $user_id ) );
		$challenge_ids = array_values( array_filter( array_map( 'strval', (array) $challenge_ids ) ) );
		foreach ( $identifier_hashes as $identifier_hash ) {
			$matching = $wpdb->get_col( $wpdb->prepare( 'SELECT challenge_id FROM ' . PEYVAST_AUTH_OTP_TABLE . ' WHERE identifier_hash=%s', $identifier_hash ) );
			$challenge_ids = array_merge( $challenge_ids, array_values( array_filter( array_map( 'strval', (array) $matching ) ) ) );
		}
		$challenge_ids = array_values( array_unique( $challenge_ids ) );
		if ( $challenge_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $challenge_ids ), '%s' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PEYVAST_AUTH_OTP_DELIVERY_TABLE . ' WHERE challenge_id IN (' . $placeholders . ')', $challenge_ids ) );
		}
		$wpdb->delete( PEYVAST_AUTH_OTP_TABLE, array( 'user_id' => $user_id ), array( '%d' ) );
		foreach ( $identifier_hashes as $identifier_hash ) {
			$wpdb->delete( PEYVAST_AUTH_OTP_TABLE, array( 'identifier_hash' => $identifier_hash ), array( '%s' ) );
		}
		$wpdb->delete( PEYVAST_AUTH_PHONE_IDENTITY_TABLE, array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( PEYVAST_AUTH_LOG_TABLE, array( 'user_id' => $user_id ), array( '%d' ) );

		foreach ( array(
			'_peyvast_auth_phone',
			'_peyvast_auth_phone_canonical',
			'_peyvast_auth_phone_recovery',
			'_peyvast_auth_migration_recovery',
			'_peyvast_auth_registration_recovery',
			'_peyvast_auth_google_identity',
		) as $key_name ) {
			delete_user_meta( $user_id, $key_name );
		}

		return array( 'items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

}
