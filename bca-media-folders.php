<?php
/**
 * Plugin Name:       BCA Media Folders
 * Plugin URI:        https://beclicked.agency
 * Description:       Rangez votre médiathèque dans des dossiers, simplement. Aucun fichier n'est déplacé sur le serveur, aucun lien n'est cassé.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Be Clicked Agency
 * Author URI:        https://beclicked.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bca-media-folders
 * Update URI:        https://github.com/beclicked/bca-media-folders
 *
 * @package BCA_Media_Folders
 */

defined( 'ABSPATH' ) || exit;

define( 'BCAMF_VERSION', '1.0.1' );
define( 'BCAMF_FILE', __FILE__ );
define( 'BCAMF_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCAMF_URL', plugin_dir_url( __FILE__ ) );
define( 'BCAMF_TAXONOMY', 'bcamf_folder' );

// Dépôt GitHub utilisé pour les mises à jour (format propriétaire/dépôt).
if ( ! defined( 'BCAMF_REPO' ) ) {
	define( 'BCAMF_REPO', 'beclicked/bca-media-folders' );
}

require_once BCAMF_DIR . 'includes/class-folders.php';
require_once BCAMF_DIR . 'includes/class-query.php';
require_once BCAMF_DIR . 'includes/class-rest.php';
require_once BCAMF_DIR . 'includes/class-admin.php';
require_once BCAMF_DIR . 'includes/class-updater.php';

add_action( 'init', array( 'BCAMF_Folders', 'register_taxonomy' ) );

BCAMF_Query::init();
BCAMF_Rest::init();
BCAMF_Admin::init();
BCAMF_Updater::init();
