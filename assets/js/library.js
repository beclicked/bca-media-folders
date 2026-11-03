/**
 * BCA Media Folders : arborescence latérale de la page Médias.
 *
 * Fonctionne en vue grille (filtrage instantané) et en vue liste (rechargement de page).
 * Convention : 0 = tous les médias, -1 = non classés, >0 = un dossier.
 */
/* global jQuery, wp */
( function ( $ ) {
	'use strict';

	var D = ( window.BCAMF_DATA = window.BCAMF_DATA || { folders: [], counts: { all: 0, uncategorized: 0 }, i18n: {} } );
	var L = window.BCAMF_LIB || { mode: 'grid', current: 0 };
	var t = D.i18n || {};
	var B = ( window.BCAMF = window.BCAMF || {} );
	var KEY = 'bcamf:' + ( L.siteKey || window.location.host );

	var state = { current: 0, open: {}, query: '', drag: null };
	var $side, $tree, $toast;
	var toastTimer = null;
	var expandTimer = null;

	/* Utilitaires --------------------------------------------------------- */

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str || '' ).replace( /%[sd]/g, function () {
			return args[ i++ ];
		} );
	}

	function store( key, value ) {
		try {
			window.localStorage.setItem( KEY + ':' + key, JSON.stringify( value ) );
		} catch ( e ) {}
	}

	function load( key, fallback ) {
		try {
			var raw = window.localStorage.getItem( KEY + ':' + key );
			return raw === null ? fallback : JSON.parse( raw );
		} catch ( e ) {
			return fallback;
		}
	}

	function debounce( fn, wait ) {
		var timer;
		return function () {
			clearTimeout( timer );
			timer = setTimeout( fn, wait );
		};
	}

	function api( path, options ) {
		return wp.apiFetch( $.extend( { path: '/bcamf/v1' + path }, options || {} ) );
	}

	function byId( id ) {
		for ( var i = 0; i < D.folders.length; i++ ) {
			if ( D.folders[ i ].id === id ) {
				return D.folders[ i ];
			}
		}
		return null;
	}

	function childrenOf( id ) {
		return D.folders.filter( function ( f ) {
			return f.parent === id;
		} );
	}

	function isDescendant( id, ancestor ) {
		var f = byId( id );
		var guard = 0;
		while ( f && f.parent && guard++ < 100 ) {
			if ( f.parent === ancestor ) {
				return true;
			}
			f = byId( f.parent );
		}
		return false;
	}

	function folderName( id ) {
		if ( id === 0 ) {
			return t.all;
		}
		if ( id === -1 ) {
			return t.uncategorized;
		}
		var f = byId( id );
		return f ? f.name : '';
	}

	function errorMessage( err ) {
		return ( err && err.message ) ? err.message : t.error;
	}

	/* Accès à la grille WordPress ----------------------------------------- */

	function gridState() {
		var frame = wp.media && wp.media.frame;
		return ( frame && typeof frame.state === 'function' ) ? frame.state() : null;
	}

	function gridLibrary() {
		var s = gridState();
		return ( s && s.get ) ? s.get( 'library' ) : null;
	}

	function gridSelection() {
		var s = gridState();
		return ( s && s.get ) ? s.get( 'selection' ) : null;
	}

	// La grille est créée par WordPress au chargement : on attend qu'elle soit prête.
	function whenGrid( callback ) {
		var tries = 0;
		( function check() {
			var lib = gridLibrary();
			if ( lib && lib.props ) {
				callback( lib );
			} else if ( tries++ < 50 ) {
				setTimeout( check, 100 );
			}
		}() );
	}

	function applyGridFilter() {
		var lib = gridLibrary();
		if ( lib && lib.props ) {
			lib.props.set( {
				bcamf_folder: state.current === 0 ? null : state.current,
				bcamf_ts: Date.now()
			} );
		}
	}

	/* Construction de l'interface ----------------------------------------- */

	function fixedItem( id, icon, label ) {
		var $row = $( '<div class="bcamf-row" tabindex="0"/>' ).attr( 'data-folder', id )
			.append( '<span class="bcamf-toggle-spacer"></span>' )
			.append( $( '<span class="dashicons bcamf-icon" aria-hidden="true"/>' ).addClass( icon ) )
			.append( $( '<span class="bcamf-name"/>' ).text( label ) )
			.append( '<span class="bcamf-count"></span>' );
		return $( '<li class="bcamf-node"/>' ).append( $row );
	}

	function build() {
		$side = $( '<div id="bcamf-sidebar" class="bcamf-sidebar" role="navigation"/>' ).attr( 'aria-label', t.folders );

		var $newButton = $( '<button type="button" class="button bcamf-new"/>' )
			.append( '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' )
			.append( $( '<span/>' ).text( t.newFolder ) );

		var $head = $( '<div class="bcamf-head"/>' )
			.append( $( '<span class="bcamf-title"/>' ).text( t.folders ) )
			.append( $newButton );

		var $search = $( '<div class="bcamf-search"/>' ).append(
			$( '<input type="search" class="bcamf-search-input"/>' ).attr( { placeholder: t.search, 'aria-label': t.search } )
		);

		var $fixed = $( '<ul class="bcamf-fixed"/>' )
			.append( fixedItem( 0, 'dashicons-admin-media', t.all ) )
			.append( fixedItem( -1, 'dashicons-portfolio', t.uncategorized ) );

		$tree = $( '<ul class="bcamf-tree" role="tree"/>' ).attr( 'aria-label', t.folders );
		$toast = $( '<div class="bcamf-toast" role="status" aria-live="polite"/>' );

		$side.append(
			$head,
			$search,
			$fixed,
			$( '<div class="bcamf-tree-wrap"/>' ).append( $tree ),
			$( '<p class="bcamf-hint"/>' ).text( t.hint ),
			$toast
		);

		$( '#wpbody-content' ).prepend( $side );
	}

	function actionButtons() {
		var $actions = $( '<span class="bcamf-actions"/>' );
		[
			[ 'add', 'dashicons-plus-alt2', t.addSub ],
			[ 'rename', 'dashicons-edit', t.rename ],
			[ 'delete', 'dashicons-trash', t.remove ]
		].forEach( function ( a ) {
			$actions.append(
				$( '<button type="button" class="bcamf-act"/>' )
					.attr( { 'data-act': a[ 0 ], title: a[ 2 ], 'aria-label': a[ 2 ] } )
					.append( $( '<span class="dashicons" aria-hidden="true"/>' ).addClass( a[ 1 ] ) )
			);
		} );
		return $actions;
	}

	function buildNode( f, visible, forceOpen ) {
		if ( visible && ! visible[ f.id ] ) {
			return null;
		}

		var kids = childrenOf( f.id );
		var open = forceOpen || !! state.open[ f.id ];
		var $li = $( '<li class="bcamf-node" role="treeitem"/>' ).attr( 'data-id', f.id );
		var $row = $( '<div class="bcamf-row" tabindex="0" draggable="true"/>' ).attr( 'data-folder', f.id );

		if ( kids.length ) {
			$li.attr( 'aria-expanded', String( open ) ).toggleClass( 'is-open', open );
			$row.append(
				$( '<button type="button" class="bcamf-toggle"/>' )
					.attr( 'aria-label', open ? t.collapse : t.expand )
					.append( '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>' )
			);
		} else {
			$row.append( '<span class="bcamf-toggle-spacer"></span>' );
		}

		$row.append( '<span class="dashicons dashicons-category bcamf-icon" aria-hidden="true"></span>' )
			.append( $( '<span class="bcamf-name"/>' ).text( f.name ) )
			.append( $( '<span class="bcamf-count"/>' ).text( f.count ) )
			.append( actionButtons() );

		$li.append( $row );

		if ( kids.length ) {
			var $ul = $( '<ul class="bcamf-children" role="group"/>' );
			kids.forEach( function ( k ) {
				var $k = buildNode( k, visible, forceOpen );
				if ( $k ) {
					$ul.append( $k );
				}
			} );
			$li.append( $ul );
		}

		return $li;
	}

	function render() {
		var q = $.trim( state.query ).toLowerCase();
		var visible = null;

		if ( q ) {
			visible = {};
			D.folders.forEach( function ( f ) {
				if ( f.name.toLowerCase().indexOf( q ) > -1 ) {
					visible[ f.id ] = true;
					var p = byId( f.parent );
					var guard = 0;
					while ( p && guard++ < 100 ) {
						visible[ p.id ] = true;
						p = byId( p.parent );
					}
				}
			} );
		}

		$tree.empty();
		childrenOf( 0 ).forEach( function ( f ) {
			var $n = buildNode( f, visible, !! q );
			if ( $n ) {
				$tree.append( $n );
			}
		} );

		if ( ! D.folders.length ) {
			$tree.append( $( '<li class="bcamf-empty"/>' ).text( t.empty ) );
		} else if ( q && ! $tree.children().length ) {
			$tree.append( $( '<li class="bcamf-empty"/>' ).text( t.noResult ) );
		}

		$side.find( '.bcamf-row[data-folder="0"] .bcamf-count' ).text( D.counts.all );
		$side.find( '.bcamf-row[data-folder="-1"] .bcamf-count' ).text( D.counts.uncategorized );
		highlight();
	}

	function highlight() {
		$side.find( '.bcamf-row' ).removeClass( 'is-current' ).removeAttr( 'aria-current' );
		$side.find( '.bcamf-row[data-folder="' + state.current + '"]' ).addClass( 'is-current' ).attr( 'aria-current', 'true' );
	}

	function applyPayload( res ) {
		if ( res && res.folders ) {
			D.folders = res.folders;
		}
		if ( res && res.counts ) {
			D.counts = res.counts;
		}
		render();
	}

	function refresh() {
		api( '/folders' ).then( applyPayload ).catch( function () {} );
	}

	function toast( message, isError ) {
		clearTimeout( toastTimer );
		$toast.text( message ).toggleClass( 'is-error', !! isError ).addClass( 'is-visible' );
		toastTimer = setTimeout( function () {
			$toast.removeClass( 'is-visible' );
		}, isError ? 6000 : 3500 );
	}

	function focusRow( id ) {
		$side.find( '.bcamf-row[data-folder="' + id + '"]' ).trigger( 'focus' );
	}

	function setOpen( id, open ) {
		if ( open ) {
			state.open[ id ] = true;
		} else {
			delete state.open[ id ];
		}
		store( 'open', state.open );

		var $li = $tree.find( '.bcamf-node[data-id="' + id + '"]' );
		if ( $li.children( '.bcamf-children' ).length ) {
			$li.toggleClass( 'is-open', open ).attr( 'aria-expanded', String( open ) );
			$li.children( '.bcamf-row' ).find( '.bcamf-toggle' ).attr( 'aria-label', open ? t.collapse : t.expand );
		}
	}

	/* Navigation ----------------------------------------------------------- */

	function setUploadFolder( id ) {
		if ( typeof B.setFolder === 'function' ) {
			B.setFolder( id );
		} else {
			B.currentFolder = id > 0 ? id : 0;
		}
	}

	function select( id ) {
		state.current = id;
		setUploadFolder( id );

		if ( L.mode === 'grid' ) {
			store( 'current', id );
			highlight();
			applyGridFilter();
			return;
		}

		var url = new URL( window.location.href );
		if ( id === 0 ) {
			url.searchParams.delete( 'bcamf_folder' );
		} else {
			url.searchParams.set( 'bcamf_folder', id );
		}
		url.searchParams.delete( 'paged' );
		window.location.href = url.toString();
	}

	function refreshView() {
		if ( L.mode === 'grid' ) {
			applyGridFilter();
		} else if ( state.current !== 0 ) {
			window.location.reload();
		}
	}

	/* Création, renommage, suppression ------------------------------------- */

	function editInput( $input, initial, submit ) {
		var done = false;

		function cancel() {
			if ( done ) {
				return;
			}
			done = true;
			render();
		}

		function commit() {
			if ( done ) {
				return;
			}
			var value = $.trim( $input.val() );
			if ( ! value || value === initial ) {
				cancel();
				return;
			}
			done = true;
			$input.prop( 'disabled', true );
			submit( value ).catch( function ( err ) {
				toast( errorMessage( err ), true );
				render();
			} );
		}

		$input.val( initial ).on( 'keydown', function ( e ) {
			e.stopPropagation();
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				commit();
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				cancel();
			}
		} ).on( 'blur', commit ).on( 'click mousedown', function ( e ) {
			e.stopPropagation();
		} );

		$input.trigger( 'focus' );
		$input[ 0 ].select();
	}

	function createInline( parent ) {
		if ( state.query ) {
			state.query = '';
			$side.find( '.bcamf-search-input' ).val( '' );
		}
		if ( parent > 0 ) {
			state.open[ parent ] = true;
			store( 'open', state.open );
		}
		render();

		var $container = $tree;
		if ( parent > 0 ) {
			var $parentLi = $tree.find( '.bcamf-node[data-id="' + parent + '"]' );
			$container = $parentLi.children( '.bcamf-children' );
			if ( ! $container.length ) {
				$container = $( '<ul class="bcamf-children" role="group"/>' ).appendTo( $parentLi );
			}
			$parentLi.addClass( 'is-open' );
		}
		$tree.children( '.bcamf-empty' ).remove();

		var $input = $( '<input type="text" class="bcamf-input" maxlength="190"/>' )
			.attr( { placeholder: t.newFolderName, 'aria-label': t.newFolderName } );

		$container.prepend(
			$( '<li class="bcamf-node bcamf-editing"/>' ).append(
				$( '<div class="bcamf-row"/>' )
					.append( '<span class="bcamf-toggle-spacer"></span><span class="dashicons dashicons-category bcamf-icon" aria-hidden="true"></span>' )
					.append( $input )
			)
		);

		editInput( $input, '', function ( name ) {
			return api( '/folders', { method: 'POST', data: { name: name, parent: parent } } ).then( function ( res ) {
				applyPayload( res );
				toast( fmt( t.created, name ) );
				if ( res.id ) {
					focusRow( res.id );
				}
			} );
		} );
	}

	function renameInline( id ) {
		var f = byId( id );
		var $row = $tree.find( '.bcamf-row[data-folder="' + id + '"]' );
		if ( ! f || ! $row.length ) {
			return;
		}

		$row.attr( 'draggable', 'false' ).addClass( 'bcamf-editing' );
		$row.find( '.bcamf-count, .bcamf-actions' ).remove();

		var $input = $( '<input type="text" class="bcamf-input" maxlength="190"/>' ).attr( 'aria-label', t.rename );
		$row.find( '.bcamf-name' ).replaceWith( $input );

		editInput( $input, f.name, function ( name ) {
			return api( '/folders/' + id, { method: 'POST', data: { name: name } } ).then( function ( res ) {
				applyPayload( res );
				toast( fmt( t.renamed, name ) );
				focusRow( id );
			} );
		} );
	}

	function removeFolder( id ) {
		var f = byId( id );
		if ( ! f || ! window.confirm( fmt( t.confirmDelete, f.name ) ) ) {
			return;
		}

		api( '/folders/' + id, { method: 'DELETE' } ).then( function ( res ) {
			applyPayload( res );
			toast( fmt( t.deleted, f.name ) );
			if ( state.current === id || ( state.current > 0 && ! byId( state.current ) ) ) {
				select( 0 );
			} else if ( state.current === -1 ) {
				refreshView();
			}
		} ).catch( function ( err ) {
			toast( errorMessage( err ), true );
		} );
	}

	/* Rangement ------------------------------------------------------------ */

	function removeFromView( ids, target ) {
		if ( state.current === 0 || state.current === target ) {
			return;
		}

		if ( L.mode === 'grid' ) {
			var lib = gridLibrary();
			if ( lib ) {
				lib.remove( ids.map( function ( id ) {
					return lib.get( id );
				} ).filter( Boolean ) );
			}
			var selection = gridSelection();
			if ( selection ) {
				selection.reset();
			}
			return;
		}

		ids.forEach( function ( id ) {
			$( '#post-' + id ).fadeOut( 200, function () {
				$( this ).remove();
			} );
		} );
	}

	function moveMedia( ids, target ) {
		api( '/assign', { method: 'POST', data: { ids: ids, folder: target > 0 ? target : 0 } } ).then( function ( res ) {
			applyPayload( res );

			var n = res.moved || 0;
			var message;
			if ( target === -1 ) {
				message = n > 1 ? fmt( t.unfiledN, n ) : t.unfiled1;
			} else {
				message = n > 1 ? fmt( t.movedN, n, folderName( target ) ) : fmt( t.moved1, folderName( target ) );
			}
			if ( res.skipped ) {
				message += ' ' + fmt( t.skipped, res.skipped );
			}
			toast( message, n === 0 );

			removeFromView( res.moved_ids || [], target );
		} ).catch( function ( err ) {
			toast( errorMessage( err ), true );
		} );
	}

	function moveFolder( id, target ) {
		var name = folderName( id );
		api( '/folders/' + id, { method: 'POST', data: { parent: target > 0 ? target : 0 } } ).then( function ( res ) {
			if ( target > 0 ) {
				state.open[ target ] = true;
				store( 'open', state.open );
			}
			applyPayload( res );
			toast( fmt( t.folderMoved, name, folderName( target ) ) );
		} ).catch( function ( err ) {
			toast( errorMessage( err ), true );
		} );
	}

	/* Glisser-déposer ------------------------------------------------------ */

	function startDrag( e, drag ) {
		var dt = e.originalEvent && e.originalEvent.dataTransfer;
		if ( ! dt ) {
			return;
		}

		state.drag = drag;
		dt.effectAllowed = 'move';
		try {
			dt.setData( 'application/x-bcamf', drag.type );
		} catch ( err ) {}

		var label = drag.type === 'media'
			? ( drag.ids.length > 1 ? fmt( t.nMedia, drag.ids.length ) : t.oneMedia )
			: folderName( drag.id );
		var ghost = $( '<div class="bcamf-ghost"/>' ).text( label ).appendTo( document.body )[ 0 ];
		try {
			dt.setDragImage( ghost, 14, 14 );
		} catch ( err ) {}
		setTimeout( function () {
			$( ghost ).remove();
		}, 0 );

		$( 'body' ).addClass( 'bcamf-dragging' );
	}

	function endDrag() {
		state.drag = null;
		clearTimeout( expandTimer );
		$( 'body' ).removeClass( 'bcamf-dragging' );
		if ( $side ) {
			$side.find( '.is-drop' ).removeClass( 'is-drop' );
		}
	}

	function canDrop( drag, target ) {
		if ( ! drag || isNaN( target ) ) {
			return false;
		}
		if ( drag.type === 'media' ) {
			return target !== 0 && ! ( state.current !== 0 && target === state.current );
		}

		var f = byId( drag.id );
		if ( ! f || target === -1 || target === drag.id || target === f.parent ) {
			return false;
		}
		return ! ( target > 0 && isDescendant( target, drag.id ) );
	}

	function scheduleExpand( target ) {
		clearTimeout( expandTimer );
		if ( target > 0 && ! state.open[ target ] && childrenOf( target ).length ) {
			expandTimer = setTimeout( function () {
				setOpen( target, true );
			}, 700 );
		}
	}

	function bindDragAndDrop() {
		// Sources : médias de la grille.
		if ( L.mode === 'grid' ) {
			$( document ).on( 'mouseenter', '#wp-media-grid li.attachment', function () {
				this.setAttribute( 'draggable', 'true' );
			} );
			$( document ).on( 'dragstart', '#wp-media-grid li.attachment', function ( e ) {
				var id = parseInt( $( this ).attr( 'data-id' ), 10 );
				if ( ! id ) {
					return;
				}
				var ids = [ id ];
				var selection = gridSelection();
				if ( selection && selection.get( id ) && selection.length > 1 ) {
					ids = selection.pluck( 'id' );
				}
				startDrag( e, { type: 'media', ids: ids } );
			} );
		} else {
			// Sources : lignes de la vue liste (avec les cases cochées).
			$( document ).on( 'mouseenter', '#the-list > tr', function () {
				this.setAttribute( 'draggable', 'true' );
			} );
			$( document ).on( 'dragstart', '#the-list > tr', function ( e ) {
				var id = parseInt( String( this.id ).replace( 'post-', '' ), 10 );
				if ( ! id ) {
					return;
				}
				var checked = $( '#the-list input[name="media[]"]:checked' ).map( function () {
					return parseInt( this.value, 10 );
				} ).get();
				startDrag( e, { type: 'media', ids: checked.indexOf( id ) > -1 ? checked : [ id ] } );
			} );
		}

		// Sources : dossiers de l'arborescence.
		$side.on( 'dragstart', '.bcamf-tree .bcamf-row[draggable="true"]', function ( e ) {
			startDrag( e, { type: 'folder', id: parseInt( $( this ).attr( 'data-folder' ), 10 ) } );
		} );

		$( document ).on( 'dragend', endDrag );

		// Cibles : dossiers, « Tous les médias » (racine pour un dossier), « Non classés ».
		$side.on( 'dragover', '.bcamf-row[data-folder]', function ( e ) {
			var target = parseInt( $( this ).attr( 'data-folder' ), 10 );
			if ( ! canDrop( state.drag, target ) ) {
				return;
			}
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'move';
			if ( ! $( this ).hasClass( 'is-drop' ) ) {
				$side.find( '.is-drop' ).removeClass( 'is-drop' );
				$( this ).addClass( 'is-drop' );
				scheduleExpand( target );
			}
		} );

		$side.on( 'dragleave', '.bcamf-row[data-folder]', function ( e ) {
			var related = e.originalEvent && e.originalEvent.relatedTarget;
			if ( related && this.contains( related ) ) {
				return;
			}
			$( this ).removeClass( 'is-drop' );
			clearTimeout( expandTimer );
		} );

		$side.on( 'drop', '.bcamf-row[data-folder]', function ( e ) {
			e.preventDefault();
			var drag = state.drag;
			var target = parseInt( $( this ).attr( 'data-folder' ), 10 );
			endDrag();
			if ( ! canDrop( drag, target ) ) {
				return;
			}
			if ( drag.type === 'media' ) {
				moveMedia( drag.ids, target );
			} else {
				moveFolder( drag.id, target );
			}
		} );

		// Un glisser interne ne doit pas déclencher la zone d'upload de WordPress
		// (« Déposez des fichiers pour les téléverser »), qui recouvrirait tout l'écran.
		// Les vrais fichiers venant de l'ordinateur ne sont pas concernés (state.drag reste vide).
		[ 'dragenter', 'dragover', 'dragleave', 'drop' ].forEach( function ( type ) {
			window.addEventListener( type, function ( e ) {
				if ( state.drag && ! $side[ 0 ].contains( e.target ) ) {
					e.stopPropagation();
				}
			}, true );
			// Enregistré après les gestionnaires de l'arborescence : ils s'exécutent d'abord.
			$side[ 0 ].addEventListener( type, function ( e ) {
				if ( state.drag ) {
					e.stopPropagation();
				}
			} );
		} );
	}

	/* Événements ----------------------------------------------------------- */

	function idOf( el ) {
		return parseInt( $( el ).closest( '.bcamf-row' ).attr( 'data-folder' ), 10 );
	}

	function bind() {
		$side.on( 'click', '.bcamf-new', function () {
			createInline( 0 );
		} );

		$side.on( 'input', '.bcamf-search-input', function () {
			state.query = this.value;
			render();
		} );

		$side.on( 'click', '.bcamf-toggle', function ( e ) {
			e.stopPropagation();
			var id = idOf( this );
			setOpen( id, ! state.open[ id ] );
		} );

		$side.on( 'click', '.bcamf-act', function ( e ) {
			e.stopPropagation();
			var id = idOf( this );
			var act = $( this ).attr( 'data-act' );
			if ( act === 'add' ) {
				createInline( id );
			} else if ( act === 'rename' ) {
				renameInline( id );
			} else if ( act === 'delete' ) {
				removeFolder( id );
			}
		} );

		$side.on( 'click', '.bcamf-row[data-folder]', function ( e ) {
			if ( $( e.target ).closest( 'input, button' ).length ) {
				return;
			}
			select( parseInt( $( this ).attr( 'data-folder' ), 10 ) );
		} );

		$side.on( 'dblclick', '.bcamf-tree .bcamf-name', function () {
			renameInline( idOf( this ) );
		} );

		$side.on( 'keydown', '.bcamf-row[data-folder]', function ( e ) {
			if ( $( e.target ).is( 'input' ) ) {
				return;
			}
			var id = parseInt( $( this ).attr( 'data-folder' ), 10 );
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				select( id );
			} else if ( e.key === 'F2' && id > 0 ) {
				e.preventDefault();
				renameInline( id );
			} else if ( e.key === 'ArrowRight' && id > 0 && childrenOf( id ).length ) {
				setOpen( id, true );
			} else if ( e.key === 'ArrowLeft' && id > 0 ) {
				setOpen( id, false );
			}
		} );

		bindDragAndDrop();

		// Compteurs à jour après un upload, une suppression ou un changement de dossier depuis le détail d'un média.
		var refreshSoon = debounce( refresh, 400 );
		$( document ).on( 'bcamf:uploaded', refreshSoon );
		$( document ).ajaxComplete( function ( e, xhr, settings ) {
			var data = settings && settings.data ? String( settings.data ) : '';
			if ( data.indexOf( 'action=save-attachment-compat' ) > -1 || data.indexOf( 'action=delete-post' ) > -1 ) {
				refreshSoon();
			}
		} );
		if ( L.mode === 'grid' ) {
			whenGrid( function ( lib ) {
				lib.on( 'remove', refreshSoon );
			} );
		}
	}

	/* Démarrage ------------------------------------------------------------ */

	$( function () {
		if ( ! $( '#wpbody-content' ).length || ! wp.apiFetch ) {
			return;
		}

		state.open = load( 'open', {} ) || {};
		state.current = L.mode === 'list'
			? ( parseInt( L.current, 10 ) || 0 )
			: ( parseInt( load( 'current', 0 ), 10 ) || 0 );
		if ( state.current > 0 && ! byId( state.current ) ) {
			state.current = 0;
		}
		setUploadFolder( state.current );

		build();
		render();
		bind();

		if ( L.mode === 'grid' && state.current !== 0 ) {
			whenGrid( applyGridFilter );
		}
	} );
}( jQuery ) );
