/**
 * BCA Media Folders : fenêtre de sélection des médias et uploads.
 *
 * 1. Chaque upload part dans le dossier courant (window.BCAMF.currentFolder).
 * 2. Un filtre « Dossier » est ajouté à la barre d'outils de toute fenêtre de
 *    sélection de médias (Gutenberg, Elementor, Elementor Pro, image mise en avant...).
 */
/* global jQuery, wp, _ */
( function ( $, wp ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view ) {
		return;
	}

	var B = ( window.BCAMF = window.BCAMF || {} );
	var t = ( window.BCAMF_DATA && window.BCAMF_DATA.i18n ) || {};

	B.currentFolder = B.currentFolder || 0;
	B.uploaders = B.uploaders || [];
	B.getFolders = function () {
		return ( window.BCAMF_DATA && window.BCAMF_DATA.folders ) || [];
	};

	/* 1. Uploads dans le dossier courant ---------------------------------- */

	function writeParam( plupload ) {
		if ( plupload && plupload.settings ) {
			plupload.settings.multipart_params = plupload.settings.multipart_params || {};
			plupload.settings.multipart_params.bcamf_folder = B.currentFolder > 0 ? B.currentFolder : 0;
		}
	}

	/**
	 * Change le dossier qui reçoit les prochains uploads.
	 * Le paramètre est écrit en permanence dans chaque uploader : la chaîne d'upload
	 * de WordPress 7.1+ (traitement des images dans le navigateur, envoi par l'API REST)
	 * le lit dès l'ajout des fichiers, avant l'étape BeforeUpload.
	 */
	var syncTimer = null;
	var lastSynced = null;

	// Le serveur mémorise aussi le dossier ouvert : si une autre extension remplace
	// l'uploader de WordPress et que le paramètre se perd, le fichier est quand même rangé.
	function syncServer() {
		if ( ! wp.apiFetch || lastSynced === B.currentFolder ) {
			return;
		}
		clearTimeout( syncTimer );
		syncTimer = setTimeout( function () {
			var folder = B.currentFolder;
			wp.apiFetch( { path: '/bcamf/v1/current', method: 'POST', data: { folder: folder } } ).then( function () {
				lastSynced = folder;
			}, function () {} );
		}, 300 );
	}

	B.setFolder = function ( id ) {
		B.currentFolder = id > 0 ? id : 0;
		B.uploaders.forEach( writeParam );
		syncServer();
	};

	if ( wp.Uploader && wp.Uploader.prototype ) {
		var originalInit = wp.Uploader.prototype.init;

		wp.Uploader.prototype.init = function () {
			if ( typeof originalInit === 'function' ) {
				originalInit.apply( this, arguments );
			}

			var uploader = this.uploader;
			if ( ! uploader || typeof uploader.bind !== 'function' ) {
				return;
			}

			B.uploaders.push( uploader );
			writeParam( uploader );

			// Filet de sécurité pour l'upload classique (admin-ajax / async-upload.php).
			uploader.bind( 'BeforeUpload', function ( up ) {
				writeParam( up );
			} );

			uploader.bind( 'UploadComplete', function () {
				$( document ).trigger( 'bcamf:uploaded' );
			} );
		};
	}

	// Fin d'une série d'uploads, quel que soit l'uploader utilisé (WordPress vide alors sa file d'attente).
	if ( wp.Uploader && wp.Uploader.queue && typeof wp.Uploader.queue.on === 'function' ) {
		wp.Uploader.queue.on( 'reset', function () {
			$( document ).trigger( 'bcamf:uploaded' );
		} );
	}

	// Upload par l'API REST (WordPress 7.1+) : rafraîchir les compteurs une fois le média créé.
	if ( wp.apiFetch && typeof wp.apiFetch.use === 'function' ) {
		wp.apiFetch.use( function ( options, next ) {
			var result = next( options );
			var method = String( options.method || 'GET' ).toUpperCase();
			var target = '';
			try {
				target = decodeURIComponent( String( options.path || options.url || '' ) );
			} catch ( e ) {}

			if ( method === 'POST' && target.indexOf( '/wp/v2/media' ) > -1 && result && typeof result.then === 'function' ) {
				result.then( function () {
					$( document ).trigger( 'bcamf:uploaded' );
				}, function () {} );
			}
			return result;
		} );
	}

	/* 2. Filtre « Dossier » dans la fenêtre de sélection ------------------- */

	var esc = ( typeof _ !== 'undefined' && _.escape ) ? _.escape : function ( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	};

	var FolderFilter = wp.media.view.AttachmentFilters.extend( {
		id: 'bcamf-folder-filter',
		className: 'attachment-filters bcamf-folder-filter',

		// Le texte des options est interprété comme du HTML : les noms sont échappés.
		createFilters: function () {
			var filters = {
				all: { text: esc( t.allFolders || 'Tous les dossiers' ), props: { bcamf_folder: null }, priority: 10 },
				none: { text: esc( t.uncategorized || 'Non classés' ), props: { bcamf_folder: -1 }, priority: 20 }
			};

			B.getFolders().forEach( function ( f, i ) {
				filters[ 'f' + f.id ] = {
					text: new Array( f.depth + 1 ).join( '&nbsp;&nbsp;&nbsp;' ) + esc( f.name ) + ' (' + f.count + ')',
					props: { bcamf_folder: f.id },
					priority: 30 + i
				};
			} );

			this.filters = filters;
		},

		change: function () {
			var filter = this.filters[ this.el.value ];
			if ( ! filter ) {
				return;
			}
			var value = filter.props.bcamf_folder;
			B.setFolder( value );
			// bcamf_ts force une nouvelle requête (évite un résultat mis en cache périmé).
			this.model.set( { bcamf_folder: value, bcamf_ts: Date.now() } );
		}
	} );

	var Browser = wp.media.view.AttachmentsBrowser;
	var originalCreateToolbar = Browser.prototype.createToolbar;

	Browser.prototype.createToolbar = function () {
		originalCreateToolbar.apply( this, arguments );

		// La page Médias a sa propre arborescence latérale.
		if ( wp.media.view.MediaFrame.Manage && this.controller instanceof wp.media.view.MediaFrame.Manage ) {
			return;
		}
		if ( ! this.toolbar || ! this.collection || ! this.collection.props ) {
			return;
		}

		this.toolbar.set( 'bcamfFolderLabel', new wp.media.view.Label( {
			value: t.filterLabel || 'Filtrer par dossier',
			attributes: { 'for': 'bcamf-folder-filter' },
			priority: -70
		} ).render() );

		this.toolbar.set( 'bcamfFolderFilter', new FolderFilter( {
			controller: this.controller,
			model: this.collection.props,
			priority: -70
		} ).render() );
	};
}( jQuery, window.wp ) );
