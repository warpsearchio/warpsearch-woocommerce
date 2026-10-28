<?php
/**
 * Field naming, mirroring the Umbraco provider: no dynamic mapping, the type comes from the suffix.
 *
 * @package WarpSearch
 */

defined( 'ABSPATH' ) || exit;

class WarpSearch_WC_Field_Names {
	const MAX_LENGTH = 127;

	// Suffixes copied from the Umbraco provider (WarpSearchConstants.FieldKinds).
	const KEYWORDS         = 'kw_ss';
	const INTEGERS         = 'in_is';
	const DECIMALS         = 'dc_ds';
	const DECIMAL_KEYWORDS = 'dk_ss';
	const DATES            = 'dt_dts';
	const TEXT_SORT        = 'so_s';

	// System fields (own prefix; types follow the umb_* examples: _s keyword, _ss multi keyword).
	const F_INDEX      = 'wc_index_s';
	const F_ID         = 'wc_id_s';
	const F_RUN        = 'wc_run_s';
	const F_VISIBILITY = 'wc_visibility_ss';

	/** @var string 'un' (standard analysis) or 't' (English analysis). */
	private $text_suffix;

	public function __construct( $english = false ) {
		$this->text_suffix = $english ? 't' : 'un';
	}

	/** Text field for a relevance tier: tx, r1, r2, r3. */
	public function text( $name, $relevance ) {
		return self::field( $name, $relevance . '_' . $this->text_suffix );
	}

	public function all_texts( $relevance ) {
		return self::limit( 'all_' . $relevance . '_' . $this->text_suffix );
	}

	public static function field( $name, $kind ) {
		return self::limit( 'f_' . self::sanitize( $name ) . '_' . $kind );
	}

	public static function sanitize( $value ) {
		$s = trim( preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $value ), '_' );
		return '' === $s ? 'x' : $s;
	}

	private static function limit( $name ) {
		if ( strlen( $name ) <= self::MAX_LENGTH ) {
			return $name;
		}
		$hash   = substr( hash( 'sha256', $name ), 0, 12 );
		$last   = strrpos( $name, '_' );
		$start  = strrpos( substr( $name, 0, $last ), '_' );
		$suffix = substr( $name, $start );
		return rtrim( substr( $name, 0, self::MAX_LENGTH - strlen( $suffix ) - strlen( $hash ) - 1 ), '_' ) . '_' . $hash . $suffix;
	}
}
