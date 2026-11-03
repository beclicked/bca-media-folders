<?php
/**
 * Filtrage de la médiathèque par dossier.
 *
 * Convention des valeurs : 0 = tous les médias, -1 = non classés, >0 = un dossier.
 *
 * @package BCA_Media_Folders
 */

defined( 'ABSPATH' ) || exit;

final class BCAMF_Query {

	public static function init(): void {
		// Vue grille et fenêtres de sélection (Gutenberg, Elementor, Elementor Pro...).
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_ajax' ) );
		// Vue liste de la médiathèque.
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
	}

	/**
	 * Ajoute la condition de dossier à des arguments de WP_Query.
	 */
	public static function apply( array $args, int $folder ): array {
		if ( 0 === $folder ) {
			return $args;
		}

		$tax_query = ( isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ) ? $args['tax_query'] : array();

		if ( -1 === $folder ) {
			$tax_query[] = array(
				'taxonomy' => BCAMF_TAXONOMY,
				'operator' => 'NOT EXISTS',
			);
		} else {
			$tax_query[] = array(
				'taxonomy'         => BCAMF_TAXONOMY,
				'field'            => 'term_id',
				'terms'            => array( $folder ),
				'include_children' => false,
			);
		}

		$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		return $args;
	}

	/**
	 * WordPress filtre les clés de la requête AJAX avant ce hook :
	 * la valeur du dossier est donc lue directement dans la requête.
	 */
	public static function filter_ajax( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce et capacité vérifiés par wp_ajax_query_attachments().
		if ( ! isset( $_REQUEST['query']['bcamf_folder'] ) || ! BCAMF_Folders::user_can() ) {
			return $query;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$folder = (int) wp_unslash( $_REQUEST['query']['bcamf_folder'] );

		return self::apply( (array) $query, $folder );
	}

	public static function filter_list( WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || 'upload.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['bcamf_folder'] ) || ! BCAMF_Folders::user_can() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$folder = (int) $_GET['bcamf_folder'];
		$args   = self::apply( array( 'tax_query' => $query->get( 'tax_query' ) ?: array() ), $folder );

		if ( ! empty( $args['tax_query'] ) ) {
			$query->set( 'tax_query', $args['tax_query'] );
		}
	}
}
