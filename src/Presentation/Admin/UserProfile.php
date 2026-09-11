<?php
namespace Peyvast\Auth\Presentation\Admin;

use Peyvast\Auth\Domain\Phone\PhoneNumber;
use Peyvast\Auth\Infrastructure\Persistence\DatabaseLock;
use Peyvast\Auth\Infrastructure\Persistence\PhoneIdentityIndex;
use Peyvast\Auth\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class UserProfile {
	private static bool $booted = false;
	/** @var array<int,DatabaseLock> */
	private static array $profile_locks = array();

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'show_user_profile', array( self::class, 'profile_field' ) );
		add_action( 'edit_user_profile', array( self::class, 'profile_field' ) );
		add_action( 'personal_options_update', array( self::class, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_field' ) );
		add_action( 'user_profile_update_errors', array( self::class, 'validate_profile_field' ), 10, 3 );
	}

	public static function profile_field( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$value = (string) get_user_meta( $user->ID, '_peyvast_auth_phone', true );
		?>
		<h2><?php echo esc_html__( 'Peyvast Authentication', 'peyvast-auth' ); ?></h2>
		<table class="form-table" role="presentation"><tr>
			<th><label for="peyvast_auth_phone"><?php echo esc_html__( 'Peyvast Mobile Number', 'peyvast-auth' ); ?></label></th>
			<td><input type="text" class="regular-text" id="peyvast_auth_phone" name="peyvast_auth_phone" value="<?php echo esc_attr( $value ); ?>" dir="ltr" />
			<p class="description"><?php echo esc_html__( 'Enter a supported mobile format. It is normalized and stored as country-code digits without a plus sign.', 'peyvast-auth' ); ?></p><?php wp_nonce_field( 'peyvast_auth_user_phone', 'peyvast_auth_user_phone_nonce' ); ?></td>
		</tr></table>
		<?php
	}

	public static function validate_profile_field( \WP_Error $errors, bool $update, $user ): void {
		if ( ! $update || ! $user || ! isset( $user->ID ) || ! current_user_can( 'edit_user', (int) $user->ID ) ) {
			return;
		}
		if ( ! isset( $_POST['peyvast_auth_phone'] ) ) {
			return;
		}

		$nonce = isset( $_POST['peyvast_auth_user_phone_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['peyvast_auth_user_phone_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'peyvast_auth_user_phone' ) ) {
			$errors->add( 'peyvast_auth_phone', __( 'The Peyvast mobile number could not be verified. Please reload the profile and try again.', 'peyvast-auth' ) );
			return;
		}
		if ( ! PhoneIdentityIndex::available() ) {
			$errors->add( 'peyvast_auth_phone', self::index_unavailable_message() );
			return;
		}

		$user_id = (int) $user->ID;
		$raw     = self::posted_phone();
		$canonical = $raw === '' ? '' : PhoneNumber::canonical_value( $raw );
		if ( $raw !== '' && $canonical === '' ) {
			$errors->add( 'peyvast_auth_phone', __( 'The Peyvast mobile number format is invalid. Use a supported national or international mobile format.', 'peyvast-auth' ) );
			return;
		}

		$lock_key = 'profile-phone:' . ( $canonical !== '' ? PhoneNumber::hash_value( $canonical ) : (string) $user_id );
		$lock     = DatabaseLock::acquire( $lock_key, 5 );
		if ( ! $lock ) {
			$errors->add( 'peyvast_auth_phone', __( 'The Peyvast mobile number is currently being updated. Please try again.', 'peyvast-auth' ) );
			return;
		}

		if ( $canonical !== '' ) {
			$duplicate = self::duplicate_source( $canonical, $user_id );
			if ( $duplicate === 'phone' ) {
				$errors->add( 'peyvast_auth_phone', __( 'This mobile number is already stored as another user’s Peyvast mobile number.', 'peyvast-auth' ) );
			} elseif ( $duplicate === 'login' ) {
				$errors->add( 'peyvast_auth_phone', __( 'This mobile number is already used as another user’s WordPress username.', 'peyvast-auth' ) );
			}
		}

		if ( $errors->has_errors() ) {
			$lock->release();
			return;
		}
		// Hold the same lock until the save callback so two profile requests cannot
		// pass duplicate validation for the same identity concurrently.
		self::$profile_locks[ $user_id ] = $lock;
	}

	public static function save_profile_field( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['peyvast_auth_phone'] ) ) {
			return;
		}
		$nonce = isset( $_POST['peyvast_auth_user_phone_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['peyvast_auth_user_phone_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'peyvast_auth_user_phone' ) ) {
			wp_die( esc_html__( 'The Peyvast mobile number could not be verified. Please reload the profile and try again.', 'peyvast-auth' ) );
		}

		$raw       = self::posted_phone();
		$canonical = $raw === '' ? '' : PhoneNumber::canonical_value( $raw );
		if ( $raw !== '' && $canonical === '' ) {
			wp_die( esc_html__( 'The Peyvast mobile number format is invalid.', 'peyvast-auth' ) );
		}

		$lock = self::$profile_locks[ $user_id ] ?? null;
		if ( ! $lock ) {
			$lock = DatabaseLock::acquire( 'profile-phone:' . ( $canonical !== '' ? PhoneNumber::hash_value( $canonical ) : (string) $user_id ), 5 );
		}
		if ( ! $lock ) {
			wp_die( esc_html__( 'The Peyvast mobile number is currently being updated. Please try again.', 'peyvast-auth' ) );
		}

		try {
			if ( ! PhoneIdentityIndex::available() ) {
				wp_die( esc_html( self::index_unavailable_message() ) );
			}
			if ( $canonical !== '' && self::duplicate_source( $canonical, $user_id ) !== '' ) {
				wp_die( esc_html__( 'The Peyvast mobile number is already assigned or conflicts with a WordPress username.', 'peyvast-auth' ) );
			}

			$old_phone = (string) get_user_meta( $user_id, '_peyvast_auth_phone', true );
			$old_hash  = (string) get_user_meta( $user_id, '_peyvast_auth_phone_canonical', true );
			if ( $canonical === '' ) {
				$ok1 = $old_phone === '' ? true : false !== delete_user_meta( $user_id, '_peyvast_auth_phone' );
				$ok2 = $old_hash === '' ? true : false !== delete_user_meta( $user_id, '_peyvast_auth_phone_canonical' );
				$consistent = $ok1 && $ok2 && (string) get_user_meta( $user_id, '_peyvast_auth_phone', true ) === '' && (string) get_user_meta( $user_id, '_peyvast_auth_phone_canonical', true ) === '';
				$indexed = $consistent && PhoneIdentityIndex::sync_primary( $user_id ) && ! PhoneIdentityIndex::contains( $user_id, PhoneIdentityIndex::SOURCE_PRIMARY, $canonical );
				if ( ! $indexed ) {
					self::restore_phone_meta( $user_id, $old_phone, $old_hash );
					Logger::error( 'auth', 'profile_phone_update_failed', 'Phone metadata/index deletion failed; previous identity was restored.', array(), $user_id );
					wp_die( esc_html__( 'The Peyvast mobile number could not be removed safely. No phone change was saved.', 'peyvast-auth' ) );
				}
				return;
			}

			$stored = PhoneNumber::peyvast_value( $canonical );
			$hash   = PhoneNumber::hash_value( $canonical );
			$ok1    = update_user_meta( $user_id, '_peyvast_auth_phone', $stored );
			$ok2    = update_user_meta( $user_id, '_peyvast_auth_phone_canonical', $hash );
			$indexed = PhoneIdentityIndex::sync_primary( $user_id );
			$consistent = false !== $ok1 && false !== $ok2
				&& (string) get_user_meta( $user_id, '_peyvast_auth_phone', true ) === $stored
				&& (string) get_user_meta( $user_id, '_peyvast_auth_phone_canonical', true ) === $hash
				&& $indexed
				&& PhoneIdentityIndex::contains( $user_id, PhoneIdentityIndex::SOURCE_PRIMARY, $canonical );
			if ( ! $consistent ) {
				$restored = self::restore_phone_meta( $user_id, $old_phone, $old_hash );
				if ( ! $restored ) {
					update_user_meta( $user_id, '_peyvast_auth_phone_recovery', array( 'state' => 'inconsistent', 'updated_at' => time() ) );
					Logger::error( 'auth', 'profile_phone_recovery_required', 'Phone metadata rollback failed; manual repair is required.', array( 'recovery' => true ), $user_id );
				}
				wp_die( esc_html__( 'The Peyvast mobile number could not be saved safely. No phone change was committed.', 'peyvast-auth' ) );
			}
		} finally {
			unset( self::$profile_locks[ $user_id ] );
			$lock->release();
		}
	}

	private static function restore_phone_meta( int $user_id, string $phone, string $hash ): bool {
		$ok1 = $phone === '' ? false !== delete_user_meta( $user_id, '_peyvast_auth_phone' ) || (string) get_user_meta( $user_id, '_peyvast_auth_phone', true ) === '' : false !== update_user_meta( $user_id, '_peyvast_auth_phone', $phone );
		$ok2 = $hash === '' ? false !== delete_user_meta( $user_id, '_peyvast_auth_phone_canonical' ) || (string) get_user_meta( $user_id, '_peyvast_auth_phone_canonical', true ) === '' : false !== update_user_meta( $user_id, '_peyvast_auth_phone_canonical', $hash );
		if ( $phone !== '' && (string) get_user_meta( $user_id, '_peyvast_auth_phone', true ) !== $phone ) $ok1 = false;
		if ( $hash !== '' && (string) get_user_meta( $user_id, '_peyvast_auth_phone_canonical', true ) !== $hash ) $ok2 = false;
		return $ok1 && $ok2;
	}

	private static function index_unavailable_message(): string {
		$state = PhoneIdentityIndex::availability_state();
		if ( $state === 'index_building' ) {
			return __( 'The Peyvast phone identity index is still being prepared. The phone was not changed. Please try again shortly.', 'peyvast-auth' );
		}
		if ( $state === 'scheduler_unavailable' ) {
			return __( 'The Peyvast phone identity background service is unavailable. The phone was not changed. Please try again shortly.', 'peyvast-auth' );
		}
		return __( 'The Peyvast phone identity index is unavailable because its database schema is missing or invalid. The phone was not changed.', 'peyvast-auth' );
	}

	private static function posted_phone(): string {
		return isset( $_POST['peyvast_auth_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['peyvast_auth_phone'] ) ) ) : '';
	}

	private static function duplicate_source( string $canonical, int $current_user_id ): string {
		global $wpdb;
		// Prefer the identity index: aligns with auth resolution, no migration/WC side effects.
		$hash = PhoneNumber::hash_value( $canonical );
		if ( $hash !== '' ) {
			$indexed = PhoneIdentityIndex::candidate_user_ids( $hash, array( PhoneIdentityIndex::SOURCE_PRIMARY ) );
			foreach ( $indexed as $user_id ) {
				if ( (int) $user_id !== $current_user_id ) {
					return 'phone';
				}
			}
		}

		// Index may be unavailable during rebuild; fall back to authoritative primary meta.
		$values       = PhoneNumber::lookup_values( $canonical );
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		if ( $values ) {
			$args = array_merge( array( '_peyvast_auth_phone', $current_user_id ), $values );
			$sql  = "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND user_id<>%d AND meta_value IN ($placeholders) LIMIT 1";
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			if ( $rows ) {
				return 'phone';
			}
		}

		// Phones can appear in several formats; query candidate values instead of loading all users.
		if ( $values ) {
			$args = array_merge( $values, array( $current_user_id ) );
			$sql  = "SELECT ID FROM {$wpdb->users} WHERE user_login IN ($placeholders) AND ID<>%d LIMIT 1";
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			if ( $rows ) {
				return 'login';
			}
		}
		return '';
	}
}
