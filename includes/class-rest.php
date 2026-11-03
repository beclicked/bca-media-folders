<?php
/**
 * API REST interne (bcamf/v1) utilisée par l'interface de la médiathèque.
 *
 * Chaque réponse renvoie l'arborescence et les compteurs à jour,
 * pour que l'interface se resynchronise en un seul appel.
 *
 * @package BCA_Media_Folders
 */

defined( 'ABSPATH' ) || exit;

final class BCAMF_Rest {

	const NS = 'bcamf/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can(): bool {
		return BCAMF_Folders::user_can();
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/folders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( __CLASS__, 'can' ),
					'args'                => array(
						'name'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'parent' => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/folders/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => array( __CLASS__, 'can' ),
					'args'                => array(
						'name'   => array( 'type' => 'string' ),
						'parent' => array( 'type' => 'integer' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/assign',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'assign' ),
					'permission_callback' => array( __CLASS__, 'can' ),
					'args'                => array(
						'ids'    => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'required' => true,
						),
						'folder' => array(
							'type'    => 'integer',
							'default' => 0,
						),
					),
				),
			)
		);
	}

	private static function respond( array $extra = array() ): WP_REST_Response {
		return rest_ensure_response( array_merge( BCAMF_Folders::payload(), $extra ) );
	}

	public static function index() {
		return self::respond();
	}

	public static function create( WP_REST_Request $request ) {
		$id = BCAMF_Folders::create( (string) $request['name'], (int) $request['parent'] );
		return is_wp_error( $id ) ? $id : self::respond( array( 'id' => $id ) );
	}

	public static function update( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( $request->has_param( 'name' ) ) {
			$result = BCAMF_Folders::rename( $id, (string) $request['name'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $request->has_param( 'parent' ) ) {
			$result = BCAMF_Folders::move( $id, (int) $request['parent'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return self::respond( array( 'id' => $id ) );
	}

	public static function delete( WP_REST_Request $request ) {
		$result = BCAMF_Folders::delete( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : self::respond();
	}

	public static function assign( WP_REST_Request $request ) {
		$result = BCAMF_Folders::assign( (array) $request['ids'], (int) $request['folder'] );
		return is_wp_error( $result ) ? $result : self::respond( $result );
	}
}
