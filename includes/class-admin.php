<?php
/**
 * Intégration dans l'administration : scripts, page Médias, fenêtre de sélection,
 * upload direct dans un dossier, champ « Dossier » dans le détail d'un média.
 *
 * @package BCA_Media_Folders
 */

defined( 'ABSPATH' ) || exit;

final class BCAMF_Admin {

	private static bool $modal_enqueued = false;

	public static function init(): void {
		// Toute fenêtre de sélection de médias (Gutenberg, Elementor, Elementor Pro, image mise en avant...).
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_modal' ) );
		// Page Médias (grille et liste).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_library' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_hidden_field' ) );
		// Upload direct dans le dossier sélectionné (upload classique et API REST).
		add_action( 'add_attachment', array( __CLASS__, 'assign_on_upload' ) );
		add_action( 'rest_after_insert_attachment', array( __CLASS__, 'assign_on_rest_upload' ), 10, 3 );
		// Champ « Dossier » dans le détail d'un média.
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'edit_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_field' ), 10, 2 );
	}

	private static function is_library_screen(): bool {
		global $pagenow;
		return is_admin() && 'upload.php' === $pagenow;
	}

	/**
	 * Même logique que upload.php pour déterminer le mode d'affichage.
	 */
	private static function mode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mode'] ) && in_array( $_GET['mode'], array( 'grid', 'list' ), true ) ) {
			return sanitize_key( $_GET['mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$mode = get_user_option( 'media_library_mode', get_current_user_id() );
		return 'list' === $mode ? 'list' : 'grid';
	}

	private static function i18n(): array {
		return array(
			'folders'       => __( 'Dossiers', 'bca-media-folders' ),
			'newFolder'     => __( 'Nouveau', 'bca-media-folders' ),
			'newFolderName' => __( 'Nom du dossier', 'bca-media-folders' ),
			'search'        => __( 'Rechercher un dossier', 'bca-media-folders' ),
			'all'           => __( 'Tous les médias', 'bca-media-folders' ),
			'uncategorized' => __( 'Non classés', 'bca-media-folders' ),
			'allFolders'    => __( 'Tous les dossiers', 'bca-media-folders' ),
			'filterLabel'   => __( 'Filtrer par dossier', 'bca-media-folders' ),
			'hint'          => __( 'Glissez des médias sur un dossier pour les ranger.', 'bca-media-folders' ),
			'empty'         => __( 'Aucun dossier pour l\'instant.', 'bca-media-folders' ),
			'noResult'      => __( 'Aucun dossier trouvé.', 'bca-media-folders' ),
			'expand'        => __( 'Déplier', 'bca-media-folders' ),
			'collapse'      => __( 'Replier', 'bca-media-folders' ),
			'addSub'        => __( 'Nouveau sous-dossier', 'bca-media-folders' ),
			'rename'        => __( 'Renommer', 'bca-media-folders' ),
			'remove'        => __( 'Supprimer', 'bca-media-folders' ),
			/* translators: %s: nom du dossier */
			'confirmDelete' => __( "Supprimer le dossier « %s » ?\n\nLes médias qu'il contient ne sont pas supprimés : ils redeviennent non classés. Ses sous-dossiers remontent d'un niveau.", 'bca-media-folders' ),
			/* translators: %s: nom du dossier */
			'created'       => __( 'Dossier « %s » créé.', 'bca-media-folders' ),
			/* translators: %s: nom du dossier */
			'renamed'       => __( 'Dossier renommé en « %s ».', 'bca-media-folders' ),
			/* translators: %s: nom du dossier */
			'deleted'       => __( 'Dossier « %s » supprimé.', 'bca-media-folders' ),
			/* translators: %s: nom du dossier */
			'moved1'        => __( '1 média rangé dans « %s ».', 'bca-media-folders' ),
			/* translators: 1: nombre de médias, 2: nom du dossier */
			'movedN'        => __( '%d médias rangés dans « %s ».', 'bca-media-folders' ),
			'unfiled1'      => __( '1 média retiré de son dossier.', 'bca-media-folders' ),
			/* translators: %d: nombre de médias */
			'unfiledN'      => __( '%d médias retirés de leur dossier.', 'bca-media-folders' ),
			/* translators: %d: nombre de médias */
			'skipped'       => __( '%d média(s) ignoré(s) : droits insuffisants.', 'bca-media-folders' ),
			/* translators: 1: dossier déplacé, 2: dossier de destination */
			'folderMoved'   => __( '« %s » déplacé dans « %s ».', 'bca-media-folders' ),
			'oneMedia'      => __( '1 média', 'bca-media-folders' ),
			/* translators: %d: nombre de médias */
			'nMedia'        => __( '%d médias', 'bca-media-folders' ),
			'error'         => __( 'Une erreur est survenue. Réessayez.', 'bca-media-folders' ),
		);
	}

	/**
	 * Données partagées par les deux scripts (déclarées une seule fois côté navigateur).
	 */
	private static function data_js(): string {
		$data = array_merge( BCAMF_Folders::payload(), array( 'i18n' => self::i18n() ) );
		return 'window.BCAMF_DATA = window.BCAMF_DATA || ' . wp_json_encode( $data ) . ';';
	}

	public static function enqueue_modal(): void {
		if ( self::$modal_enqueued || ! BCAMF_Folders::user_can() ) {
			return;
		}
		self::$modal_enqueued = true;

		wp_enqueue_style( 'bcamf-admin', BCAMF_URL . 'assets/css/admin.css', array( 'dashicons' ), BCAMF_VERSION );
		wp_enqueue_script( 'bcamf-modal', BCAMF_URL . 'assets/js/modal.js', array( 'jquery', 'media-views', 'wp-api-fetch' ), BCAMF_VERSION, true );
		wp_add_inline_script( 'bcamf-modal', self::data_js(), 'before' );
	}

	public static function enqueue_library( string $hook ): void {
		if ( 'upload.php' !== $hook || ! BCAMF_Folders::user_can() ) {
			return;
		}

		$mode = self::mode();
		$deps = array( 'jquery', 'wp-api-fetch' );

		wp_enqueue_style( 'bcamf-admin', BCAMF_URL . 'assets/css/admin.css', array( 'dashicons' ), BCAMF_VERSION );

		if ( 'grid' === $mode ) {
			self::enqueue_modal();
			$deps[] = 'media-grid';
			$deps[] = 'bcamf-modal';
		}

		wp_enqueue_script( 'bcamf-library', BCAMF_URL . 'assets/js/library.js', $deps, BCAMF_VERSION, true );
		wp_add_inline_script( 'bcamf-library', self::data_js(), 'before' );
		wp_localize_script(
			'bcamf-library',
			'BCAMF_LIB',
			array(
				'mode'    => $mode,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'current' => isset( $_GET['bcamf_folder'] ) ? (int) $_GET['bcamf_folder'] : 0,
				'siteKey' => md5( home_url() ),
			)
		);
	}

	public static function body_class( $classes ) {
		if ( self::is_library_screen() && BCAMF_Folders::user_can() ) {
			$classes .= ' bcamf-active';
		}
		return $classes;
	}

	/**
	 * Vue liste : conserve le dossier courant lors d'une recherche ou d'un filtre.
	 */
	public static function list_hidden_field( $post_type ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'attachment' === $post_type && isset( $_GET['bcamf_folder'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<input type="hidden" name="bcamf_folder" value="%d" />', (int) $_GET['bcamf_folder'] );
		}
	}

	/**
	 * Le script ajoute bcamf_folder aux paramètres de chaque upload.
	 */
	public static function assign_on_upload( int $post_id ): void {
		global $pagenow;

		if ( ! BCAMF_Folders::user_can() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce vérifié par async-upload.php.
		if ( isset( $_REQUEST['bcamf_folder'] ) ) {
			// Paramètre envoyé par le navigateur : il fait foi.
			$folder = (int) $_REQUEST['bcamf_folder']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( 'async-upload.php' === $pagenow ) {
			// Paramètre absent (une autre extension a remplacé l'uploader de WordPress) :
			// on utilise le dossier que l'utilisateur a ouvert en dernier dans la médiathèque.
			$folder = BCAMF_Folders::upload_folder_for_user();
		} else {
			return;
		}

		if ( $folder > 0 && BCAMF_Folders::exists( $folder ) ) {
			wp_set_object_terms( $post_id, array( $folder ), BCAMF_TAXONOMY, false );
		}
	}

	/**
	 * Upload par l'API REST (WordPress 7.1+) quand le paramètre arrive en JSON.
	 */
	public static function assign_on_rest_upload( $attachment, $request, $creating ): void {
		if ( ! $creating || ! $request instanceof WP_REST_Request || ! BCAMF_Folders::user_can() ) {
			return;
		}
		$folder = (int) $request->get_param( 'bcamf_folder' );
		if ( $folder > 0 && BCAMF_Folders::exists( $folder ) && BCAMF_Folders::folder_of( $attachment->ID ) !== $folder ) {
			wp_set_object_terms( $attachment->ID, array( $folder ), BCAMF_TAXONOMY, false );
		}
	}

	public static function edit_field( array $fields, WP_Post $post ): array {
		if ( ! BCAMF_Folders::user_can() ) {
			return $fields;
		}

		$current = BCAMF_Folders::folder_of( $post->ID );
		$name    = sprintf( 'attachments[%d][bcamf_folder]', $post->ID );
		$id      = sprintf( 'attachments-%d-bcamf_folder', $post->ID );

		$html  = sprintf( '<select name="%s" id="%s" class="bcamf-field">', esc_attr( $name ), esc_attr( $id ) );
		$html .= sprintf( '<option value="0"%s>%s</option>', selected( $current, 0, false ), esc_html__( 'Non classé', 'bca-media-folders' ) );
		foreach ( BCAMF_Folders::get_all() as $folder ) {
			$html .= sprintf(
				'<option value="%d"%s>%s%s</option>',
				$folder['id'],
				selected( $current, $folder['id'], false ),
				str_repeat( '&nbsp;&nbsp;&nbsp;', $folder['depth'] ),
				esc_html( $folder['name'] )
			);
		}
		$html .= '</select>';

		$fields['bcamf_folder'] = array(
			'label' => __( 'Dossier', 'bca-media-folders' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $fields;
	}

	public static function save_field( array $post, array $attachment ): array {
		if ( ! isset( $attachment['bcamf_folder'] ) || ! BCAMF_Folders::user_can() || empty( $post['ID'] ) ) {
			return $post;
		}

		$id     = (int) $post['ID'];
		$folder = (int) $attachment['bcamf_folder'];

		if ( BCAMF_Folders::folder_of( $id ) !== $folder ) {
			BCAMF_Folders::assign( array( $id ), $folder );
		}

		return $post;
	}
}
