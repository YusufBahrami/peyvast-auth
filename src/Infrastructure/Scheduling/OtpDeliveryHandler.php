<?php
namespace Peyvast\Auth\Infrastructure\Scheduling;

use Peyvast\Auth\Infrastructure\Logging\Logger;
use Peyvast\Auth\Infrastructure\Persistence\OtpChallengeStore;
use Peyvast\Auth\Infrastructure\Persistence\OtpDeliveryStore;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Providers\ProviderManager;

defined( 'ABSPATH' ) || exit;

/** Asynchronous OTP provider delivery with challenge re-validation and bounded retries. */
final class OtpDeliveryHandler {

	private const MAX_ATTEMPTS   = 3;
	private const RETRY_BASE_SEC = 30;
	private const RETRY_CAP_SEC  = 10 * MINUTE_IN_SECONDS;

	/**
	 * @param string $challenge_id Challenge to deliver.
	 * @param int    $attempt      Zero-based delivery attempt.
	 */
	public static function deliver( string $challenge_id = '', int $attempt = 0 ): void {
		if ( $challenge_id === '' ) {
			return;
		}
		$attempt  = max( 0, (int) $attempt );
		$store = new OtpChallengeStore();
		$lock  = DatabaseLock::acquire( 'otp-challenge:' . hash( 'sha256', $challenge_id ), 5 );
		if ( ! $lock ) return;
		try {
			$challenge = $store->find_challenge( $challenge_id );
			$delivery  = OtpDeliveryStore::row( $challenge_id );
			// No queued payload: already delivered or cleaned. Duplicate executions
			// must be no-ops (idempotency).
			if ( ! $delivery ) return;

		// Never deliver for a challenge that is no longer the live one.
		if ( ! $challenge || ! empty( $challenge->consumed_at ) || ! empty( $challenge->verified_at ) || strtotime( (string) $challenge->expires_at ) <= time() ) {
			OtpDeliveryStore::delete( $challenge_id );
			Logger::notice(
				'provider',
				'otp_delivery_cancelled',
				'A queued OTP delivery was cancelled because the challenge is no longer valid.',
				array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ),
				(int) ( $challenge->user_id ?? 0 )
			);
			return;
		}

		$payload = OtpDeliveryStore::payload_from_row( $delivery );
		if ( null === $payload || $payload['otp'] === '' ) {
			// Undecryptable payload: never resend blindly; drop it and let the
			// user request a fresh code.
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error(
				'provider',
				'otp_delivery_payload_invalid',
				'Queued OTP delivery payload could not be decrypted; the delivery was discarded.',
				array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ),
				(int) ( $challenge->user_id ?? 0 )
			);
			return;
		}

		$channels = array_values( array_filter( array_map( 'strval', array_keys( $payload['channels'] ) ) ) );
		if ( ! $channels ) {
			OtpDeliveryStore::delete( $challenge_id );
			return;
		}

		$context = array(
			'purpose' => (string) $challenge->purpose,
			'user_id' => (int) $challenge->user_id,
			'async'   => true,
		);

		// Every configured channel is mandatory; failed channels are retried
		// independently on the next attempt until all confirm delivery.
		$failed = array();
		foreach ( $channels as $channel ) {
			$recipient = (string) ( $payload['channels'][ $channel ] ?? '' );
			if ( $recipient === '' ) {
				$failed[] = $channel;
				continue;
			}
			$result = $channel === 'sms'
				? ProviderManager::send_sms( $recipient, $payload['otp'], $context )
				: ProviderManager::send_email( $recipient, $payload['otp'], $context );
			if ( ! $result->success ) {
				$failed[] = $channel;
			}
		}

		if ( ! $failed ) {
			// All channels confirmed delivery.
			OtpDeliveryStore::delete( $challenge_id );
			Logger::notice(
				'provider',
				'otp_delivery_completed',
				'Queued OTP delivery completed.',
				array(
					'channels'     => $channels,
					'challenge_id' => substr( $challenge_id, 0, 8 ),
				),
				(int) $challenge->user_id
			);
			return;
		}

		OtpDeliveryStore::mark_attempt( $challenge_id );
		if ( $attempt >= self::MAX_ATTEMPTS - 1 ) {
			// Final attempt: a code that never reached every channel must not
			// remain usable, so the UI can never restore an undelivered session.
			$store->invalidate_locked( $challenge_id );
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error(
				'provider',
				'otp_delivery_failed',
				'Queued OTP delivery permanently failed; the challenge was invalidated.',
				array(
					'challenge_id' => substr( $challenge_id, 0, 8 ),
					'attempts'     => $attempt + 1,
					'failed'       => $failed,
				),
				(int) $challenge->user_id
			);
			return;
		}

		// Partial success: keep the payload for the failed channels only and
		// schedule the next attempt, so delivered channels are never resent.
		$remaining = array();
		foreach ( $failed as $channel ) {
			$remaining[ $channel ] = (string) ( $payload['channels'][ $channel ] ?? '' );
		}
		if ( ! OtpDeliveryStore::rewrite( $challenge_id, $payload['otp'], $remaining ) ) {
			$store->invalidate_locked( $challenge_id );
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error( 'provider', 'otp_delivery_state_update_failed', 'OTP delivery state could not be persisted after partial delivery; the challenge was invalidated.', array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ), (int) $challenge->user_id );
			return;
		}
		if ( Scheduler::schedule_single( Scheduler::HOOK_DELIVER_OTP, array( $challenge_id, $attempt + 1 ), self::backoff( $attempt ), true ) <= 0 ) {
			$store->invalidate_locked( $challenge_id );
			OtpDeliveryStore::delete( $challenge_id );
			Logger::error( 'provider', 'otp_delivery_retry_schedule_failed', 'OTP delivery retry could not be scheduled; the challenge was invalidated.', array( 'challenge_id' => substr( $challenge_id, 0, 8 ) ), (int) $challenge->user_id );
			return;
		}
		Logger::notice(
			'provider',
			'otp_delivery_partial_retry',
			'Some OTP delivery channels failed; a retry for the remaining channels was scheduled.',
			array(
				'channels'     => $failed,
				'challenge_id' => substr( $challenge_id, 0, 8 ),
			),
			(int) $challenge->user_id
		);
		} finally {
			$lock->release();
		}
	}

	/** Exponential backoff capped at 10 minutes: 30s, 1m, 2m, 4m, ... */
	private static function backoff( int $attempt ): int {
		return min( self::RETRY_CAP_SEC, self::RETRY_BASE_SEC * ( 2 ** max( 0, $attempt ) ) );
	}
}