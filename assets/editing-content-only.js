/**
 * TAW editing policies — content-only editing (taw/core ADR-0005).
 *
 * Enqueued by Editing\ContentLayer only for a locked user, in the post
 * editor, on a post type whose rule is `lock: contentOnly`. The server
 * already sends `templateLock: "all"`, so blocks can't be inserted, removed
 * or moved. This script puts every block in the "contentOnly" editing mode,
 * which leaves only content controls (text, links, media) and hides block
 * design tools and movers.
 *
 * Why a script: since WordPress 7.1 the block editor ignores a page-level
 * `contentOnly` template lock (it only applies inside "section" blocks), so
 * the setting alone no longer does this.
 *
 * Plain ES5, no build step. Needs wp-data and wp-block-editor.
 */
( function ( wp ) {
	if ( ! wp || ! wp.data ) {
		return;
	}

	var STORE = 'core/block-editor';
	// The editor resets editing modes while it mounts the canvas, so a mode
	// set too early is lost: re-apply whenever a block isn't contentOnly. The
	// per-block cap stops a tug-of-war if something else (zoom-out, a
	// plugin) deliberately keeps a block in another mode.
	var MAX_ATTEMPTS = 10;
	var attempts = new Map();
	var applying = false;

	function apply() {
		// setBlockEditingMode() notifies subscribers synchronously; ignore the
		// notifications this function causes itself.
		if ( applying ) {
			return;
		}

		var select = wp.data.select( STORE );
		var dispatch = wp.data.dispatch( STORE );
		if ( ! select || ! dispatch || typeof dispatch.setBlockEditingMode !== 'function' ) {
			return;
		}

		applying = true;
		try {
			select.getClientIdsWithDescendants().forEach( function ( clientId ) {
				if ( select.getBlockEditingMode( clientId ) === 'contentOnly' ) {
					return;
				}
				var tries = attempts.get( clientId ) || 0;
				if ( tries < MAX_ATTEMPTS ) {
					attempts.set( clientId, tries + 1 );
					dispatch.setBlockEditingMode( clientId, 'contentOnly' );
				}
			} );
		} finally {
			applying = false;
		}
	}

	wp.data.subscribe( apply, STORE );
	apply();
} )( window.wp );
