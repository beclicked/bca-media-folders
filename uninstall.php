<?php
/**
 * Désinstallation (suppression de l'extension depuis Extensions).
 *
 * Supprime les dossiers et leurs liens avec les médias.
 * Les médias eux-mêmes ne sont jamais touchés.
 * Une simple désactivation, elle, conserve tout.
 *
 * @package BCA_Media_Folders
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$bcamf_taxonomy = 'bcamf_folder';

$bcamf_rows = $wpdb->get_results(
	$wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $bcamf_taxonomy )
);

if ( $bcamf_rows ) {
	$bcamf_tt_ids   = array_map( 'intval', wp_list_pluck( $bcamf_rows, 'term_taxonomy_id' ) );
	$bcamf_term_ids = array_map( 'intval', wp_list_pluck( $bcamf_rows, 'term_id' ) );

	// Identifiants entiers déjà assainis.
	$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', $bcamf_tt_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $bcamf_taxonomy ) );

	foreach ( $bcamf_term_ids as $bcamf_term_id ) {
		$bcamf_still_used = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $bcamf_term_id )
		);
		if ( ! $bcamf_still_used ) {
			$wpdb->delete( $wpdb->terms, array( 'term_id' => $bcamf_term_id ) );
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => $bcamf_term_id ) );
		}
	}
}

delete_site_transient( 'bcamf_release' );
wp_cache_flush();
