/**
 * [bmlt_minutes_by_month]: load a closed month's PDF previews when it is opened.
 *
 * Chrome doesn't fetch an <object> whose data attribute is set after creation, so
 * each embed is swapped for a copy carrying the address. Embeds core's File block
 * script has hidden (no PDF viewer, e.g. phones) are left alone: nothing to show,
 * nothing to download.
 */
document.addEventListener(
	'toggle',
	function ( event ) {
		var month = event.target;
		if ( ! month.open || ! month.classList || ! month.classList.contains( 'bmlt-minutes-archive__month' ) ) {
			return;
		}
		month.querySelectorAll( 'object[data-bmlt-minutes-src]' ).forEach( function ( embed ) {
			if ( embed.hidden ) {
				return;
			}
			var copy = embed.cloneNode( true );
			copy.setAttribute( 'data', embed.getAttribute( 'data-bmlt-minutes-src' ) );
			copy.removeAttribute( 'data-bmlt-minutes-src' );
			embed.parentNode.replaceChild( copy, embed );
		} );
	},
	true // toggle doesn't bubble.
);
