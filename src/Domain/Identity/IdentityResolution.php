<?php
namespace Peyvast\Auth\Domain\Identity;

defined( 'ABSPATH' ) || exit;

final class IdentityResolution {
	public const EXISTING  = 'existing';
	public const NOT_FOUND = 'not_found';
	public const INVALID   = 'invalid';
	public const CONFLICT  = 'conflict';
	public const DENIED    = 'denied';
	public const NOT_READY = 'not_ready';

	private $status;
	private $user;
	private $identifier_type;
	private $canonical;
	private $sources;

	private function __construct( $status, $user, $identifier_type, $canonical, array $sources = array() ) {
		$this->status          = (string) $status;
		$this->user            = $user;
		$this->identifier_type = (string) $identifier_type;
		$this->canonical       = (string) $canonical;
		$this->sources         = $sources;
	}

	public static function existing( \WP_User $user, $type, $canonical = '', array $sources = array() ) {
		return new self( self::EXISTING, $user, $type, $canonical, $sources );
	}

	public static function not_found( $type, $canonical = '' ) {
		return new self( self::NOT_FOUND, null, $type, $canonical );
	}

	public static function invalid( $type = '' ) {
		return new self( self::INVALID, null, $type, '' );
	}

	public static function conflict( $type, $canonical = '', array $sources = array() ) {
		return new self( self::CONFLICT, null, $type, $canonical, $sources );
	}

	public static function denied( $type, $canonical = '', array $sources = array() ) {
		return new self( self::DENIED, null, $type, $canonical, $sources );
	}

	public static function not_ready( $type, $canonical = '' ) {
		return new self( self::NOT_READY, null, $type, $canonical );
	}

	public function status() {
		return $this->status; }
	public function user() {
		return $this->user; }
	public function identifier_type() {
		return $this->identifier_type; }
	public function canonical() {
		return $this->canonical; }
	public function is_existing() {
		return $this->status === self::EXISTING && $this->user instanceof \WP_User; }
	public function is_conflict() {
		return $this->status === self::CONFLICT; }
	public function is_denied() {
		return $this->status === self::DENIED; }
	public function is_not_ready() {
		return $this->status === self::NOT_READY; }
}
