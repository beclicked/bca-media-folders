<?php
/**
 * Dossiers : taxonomie et opérations métier.
 *
 * Un dossier est un terme d'une taxonomie hiérarchique attachée aux médias.
 * Les fichiers ne bougent jamais sur le disque : les URLs restent identiques.
 * Un média appartient à un seul dossier à la fois (ou à aucun : « Non classés »).
 *
 * @package BCA_Media_Folders
 */

defined( 'ABSPATH' ) || exit;

final class BCAMF_Folders {

	/**
	 * Capacité requise pour voir et gérer les dossiers.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'bcamf_capability', 'upload_files' );
	}

	public static function user_can(): bool {
		return current_user_can( self::capability() );
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			BCAMF_TAXONOMY,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Dossiers', 'bca-media-folders' ),
					'singular_name' => __( 'Dossier', 'bca-media-folders' ),
				),
				'hierarchical'          => true,
				'public'                => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_tagcloud'         => false,
				'show_admin_column'     => false,
				'show_in_quick_edit'    => false,
				'show_in_rest'          => false,
				'query_var'             => false,
				'rewrite'               => false,
				// Compte tous les médias, quel que soit le statut de leur page parente.
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}

	/**
	 * Liste à plat des dossiers, dans l'ordre de l'arborescence (parents puis enfants),
	 * triés par nom à chaque niveau.
	 *
	 * @return array<int, array{id:int,parent:int,name:string,count:int,depth:int}>
	 */
	public static function get_all(): array {
		$terms = get_terms(
			array(
				'taxonomy'               => BCAMF_TAXONOMY,
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}
		foreach ( $by_parent as &$siblings ) {
			usort(
				$siblings,
				static function ( $a, $b ) {
					return strnatcasecmp( $a->name, $b->name );
				}
			);
		}
		unset( $siblings );

		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $by_parent ): void {
			if ( $depth > 50 || empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $term ) {
				$out[] = array(
					'id'     => (int) $term->term_id,
					'parent' => (int) $term->parent,
					'name'   => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'count'  => (int) $term->count,
					'depth'  => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * Compteurs globaux : tous les médias et médias non classés.
	 */
	public static function counts(): array {
		global $wpdb;

		$all = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'"
		);

		$uncategorized = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = %s
				)",
				BCAMF_TAXONOMY
			)
		);

		return array(
			'all'           => $all,
			'uncategorized' => $uncategorized,
		);
	}

	/**
	 * Données envoyées à l'interface après chaque opération.
	 */
	public static function payload(): array {
		return array(
			'folders' => self::get_all(),
			'counts'  => self::counts(),
		);
	}

	public static function exists( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$term = get_term( $id, BCAMF_TAXONOMY );
		return $term instanceof WP_Term;
	}

	/**
	 * Crée un dossier.
	 *
	 * @return int|WP_Error Identifiant du dossier créé.
	 */
	public static function create( string $name, int $parent = 0 ) {
		$name = self::clean_name( $name );

		if ( '' === $name ) {
			return self::error( __( 'Le nom du dossier est vide.', 'bca-media-folders' ) );
		}
		if ( $parent < 0 || ( $parent > 0 && ! self::exists( $parent ) ) ) {
			return self::error( __( 'Le dossier parent est introuvable.', 'bca-media-folders' ), 404 );
		}
		if ( self::name_taken( $name, $parent ) ) {
			return self::error( __( 'Un dossier porte déjà ce nom à cet endroit.', 'bca-media-folders' ) );
		}

		$result = wp_insert_term( $name, BCAMF_TAXONOMY, array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message() );
		}

		return (int) $result['term_id'];
	}

	/**
	 * Renomme un dossier.
	 *
	 * @return true|WP_Error
	 */
	public static function rename( int $id, string $name ) {
		$name = self::clean_name( $name );
		$term = get_term( $id, BCAMF_TAXONOMY );

		if ( ! $term instanceof WP_Term ) {
			return self::error( __( 'Dossier introuvable.', 'bca-media-folders' ), 404 );
		}
		if ( '' === $name ) {
			return self::error( __( 'Le nom du dossier est vide.', 'bca-media-folders' ) );
		}
		if ( self::name_taken( $name, (int) $term->parent, $id ) ) {
			return self::error( __( 'Un dossier porte déjà ce nom à cet endroit.', 'bca-media-folders' ) );
		}

		$result = wp_update_term( $id, BCAMF_TAXONOMY, array( 'name' => $name ) );
		return is_wp_error( $result ) ? self::error( $result->get_error_message() ) : true;
	}

	/**
	 * Déplace un dossier sous un autre parent (0 = racine).
	 *
	 * @return true|WP_Error
	 */
	public static function move( int $id, int $parent ) {
		$term = get_term( $id, BCAMF_TAXONOMY );

		if ( ! $term instanceof WP_Term ) {
			return self::error( __( 'Dossier introuvable.', 'bca-media-folders' ), 404 );
		}
		if ( $parent < 0 || $parent === $id ) {
			return self::error( __( 'Déplacement impossible.', 'bca-media-folders' ) );
		}
		if ( $parent > 0 && ! self::exists( $parent ) ) {
			return self::error( __( 'Le dossier de destination est introuvable.', 'bca-media-folders' ), 404 );
		}
		if ( $parent > 0 ) {
			$ancestors = array_map( 'intval', get_ancestors( $parent, BCAMF_TAXONOMY, 'taxonomy' ) );
			if ( in_array( $id, $ancestors, true ) ) {
				return self::error( __( 'Impossible de placer un dossier dans l\'un de ses propres sous-dossiers.', 'bca-media-folders' ) );
			}
		}
		if ( (int) $term->parent === $parent ) {
			return true;
		}
		if ( self::name_taken( $term->name, $parent, $id ) ) {
			return self::error( __( 'Un dossier porte déjà ce nom à cet endroit.', 'bca-media-folders' ) );
		}

		$result = wp_update_term( $id, BCAMF_TAXONOMY, array( 'parent' => $parent ) );
		return is_wp_error( $result ) ? self::error( $result->get_error_message() ) : true;
	}

	/**
	 * Supprime un dossier. Les médias ne sont pas supprimés (ils redeviennent non classés)
	 * et les sous-dossiers remontent d'un niveau (comportement natif de WordPress).
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		if ( ! self::exists( $id ) ) {
			return self::error( __( 'Dossier introuvable.', 'bca-media-folders' ), 404 );
		}

		$result = wp_delete_term( $id, BCAMF_TAXONOMY );
		if ( is_wp_error( $result ) || ! $result ) {
			return self::error( __( 'La suppression a échoué.', 'bca-media-folders' ), 500 );
		}

		return true;
	}

	/**
	 * Range des médias dans un dossier (0 = les retirer de tout dossier).
	 *
	 * @param int[] $ids Identifiants des médias.
	 * @return array|WP_Error {moved:int, moved_ids:int[], skipped:int}
	 */
	public static function assign( array $ids, int $folder ) {
		if ( $folder > 0 && ! self::exists( $folder ) ) {
			return self::error( __( 'Le dossier de destination est introuvable.', 'bca-media-folders' ), 404 );
		}

		$ids     = array_slice( array_unique( array_filter( array_map( 'intval', $ids ) ) ), 0, 1000 );
		$moved   = array();
		$skipped = 0;

		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				++$skipped;
				continue;
			}

			if ( $folder > 0 ) {
				$result = wp_set_object_terms( $id, array( $folder ), BCAMF_TAXONOMY, false );
				if ( is_wp_error( $result ) ) {
					++$skipped;
					continue;
				}
			} else {
				wp_delete_object_term_relationships( $id, BCAMF_TAXONOMY );
			}

			$moved[] = $id;
		}

		return array(
			'moved'     => count( $moved ),
			'moved_ids' => $moved,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Dossier d'un média (0 si non classé).
	 */
	public static function folder_of( int $attachment_id ): int {
		$ids = wp_get_object_terms( $attachment_id, BCAMF_TAXONOMY, array( 'fields' => 'ids' ) );
		return ( is_wp_error( $ids ) || empty( $ids ) ) ? 0 : (int) $ids[0];
	}

	/**
	 * Dossier ouvert par l'utilisateur, mémorisé côté serveur pour les uploads.
	 * Sert de solution de repli quand le navigateur n'a pas pu transmettre le dossier.
	 */
	const UPLOAD_META = 'bcamf_upload_folder';
	const UPLOAD_TTL  = 2 * HOUR_IN_SECONDS;

	public static function set_upload_folder_for_user( int $folder ): void {
		$folder = ( $folder > 0 && self::exists( $folder ) ) ? $folder : 0;
		update_user_meta(
			get_current_user_id(),
			self::UPLOAD_META,
			array(
				'id'   => $folder,
				'time' => time(),
			)
		);
	}

	public static function upload_folder_for_user(): int {
		$data = get_user_meta( get_current_user_id(), self::UPLOAD_META, true );
		if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['time'] ) ) {
			return 0;
		}
		if ( time() - (int) $data['time'] > self::UPLOAD_TTL ) {
			return 0;
		}
		$folder = (int) $data['id'];
		return self::exists( $folder ) ? $folder : 0;
	}

	private static function name_taken( string $name, int $parent, int $exclude = 0 ): bool {
		foreach ( self::get_all() as $folder ) {
			if ( $folder['parent'] === $parent && $folder['id'] !== $exclude && 0 === strcasecmp( $folder['name'], $name ) ) {
				return true;
			}
		}
		return false;
	}

	private static function clean_name( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, 190 );
	}

	private static function error( string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'bcamf_error', $message, array( 'status' => $status ) );
	}
}
