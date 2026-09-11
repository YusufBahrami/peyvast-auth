<?php
namespace Peyvast\Auth\Domain\Authentication;

defined( 'ABSPATH' ) || exit;

/** Central account eligibility boundary for every custom authentication method. */
final class AuthenticationAccountPolicy {
	/** True only when the account may use custom sign-in; filters can veto via peyvast_auth_can_login. */
	public static function can_login( \WP_User $user, string $method ): bool {
		if ( (int) $user->ID <= 0 ) {
			return false;
		}
		if ( ! empty( $user->deleted ) || ! empty( $user->spam ) ) {
			return false;
		}
		if ( is_multisite() ) {
			if ( get_user_option( 'deleted', $user->ID ) || get_user_option( 'spam', $user->ID ) ) {
				return false;
			}
			if ( ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
				return false;
			}
		}
		if ( ! empty( $user->user_status ) && (int) $user->user_status !== 0 ) {
			return false;
		}
		$allowed = apply_filters( 'peyvast_auth_can_login', true, $user, $method );
		return (bool) $allowed;
	}
}
