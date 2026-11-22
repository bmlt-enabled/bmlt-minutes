/**
 * BMLT Minutes, [bmlt_minutes_latest]: shows a PDF whole in the page with PDF.js (pdfjs/, Apache-2.0).
 *
 * Every page gets its place at once, at its own shape, so the page does not jump while it loads. Pages are drawn as
 * they come near the screen and let go again far away, so a long document does not fill a phone's memory. Each
 * page's text is laid over it, so it can be selected, copied and found with the browser's Find, and the PDF's own
 * links work. Pages are redrawn sharp when the column changes width.
 *
 * If the PDF cannot be read here (another website that does not allow it, a password-protected PDF, an old browser),
 * the browser's own PDF viewer shows it instead, with a link.
 */
( function () {
	'use strict';

	var views = document.querySelectorAll( '.bmlt-minutes-latest__pdf[data-bmlt-minutes-pdf]' );
	if ( ! views.length || typeof window.IntersectionObserver !== 'function' ) {
		views.forEach( fallback );
		return;
	}
	var base = views[ 0 ].getAttribute( 'data-bmlt-minutes-pdfjs' );
	var ver = encodeURIComponent( views[ 0 ].getAttribute( 'data-bmlt-minutes-ver' ) || '' );

	views.forEach( function ( view ) {
		message( view, view.getAttribute( 'data-bmlt-minutes-loading' ), 'bmlt-minutes-latest__loading' );
	} );

	import( base + 'pdf.min.js?ver=' + ver )
		.then( function ( pdfjs ) {
			pdfjs.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.min.js?ver=' + ver;
			views.forEach( function ( view ) {
				show( pdfjs, view ).catch( function () {
					fallback( view );
				} );
			} );
		} )
		.catch( function () {
			views.forEach( fallback );
		} );

	function message( view, text, className ) {
		var p = document.createElement( 'p' );
		p.className = className;
		p.textContent = text || '';
		view.replaceChildren( p );
	}

	// The browser's own PDF viewer (none on most phones: then the link inside it shows), and a link.
	function fallback( view ) {
		var url = view.getAttribute( 'data-bmlt-minutes-pdf' );
		message( view, view.getAttribute( 'data-bmlt-minutes-error' ), 'bmlt-minutes-latest__error' );
		var embed = document.createElement( 'object' );
		embed.className = 'bmlt-minutes-latest__embed';
		embed.type = 'application/pdf';
		embed.data = url;
		var link = document.createElement( 'a' );
		link.href = url;
		link.textContent = url.split( '/' ).pop() || url;
		embed.appendChild( link );
		view.appendChild( embed );
	}

	async function show( pdfjs, view ) {
		var doc = await pdfjs.getDocument( {
			url: view.getAttribute( 'data-bmlt-minutes-pdf' ),
			cMapUrl: base + 'cmaps/',
			cMapPacked: true,
			standardFontDataUrl: base + 'standard_fonts/',
			wasmUrl: base + 'wasm/',
			iccUrl: base + 'iccs/',
			isEvalSupported: false,
			enableXfa: false,
		} ).promise;

		var label = view.getAttribute( 'data-bmlt-minutes-page' ) || '%1$s / %2$s';
		var pages = document.createElement( 'div' );
		pages.className = 'bmlt-minutes-latest__pages';
		var items = [];

		for ( var n = 1; n <= doc.numPages; n++ ) {
			var page = await doc.getPage( n );
			var vp = page.getViewport( { scale: 1 } );
			var div = document.createElement( 'div' );
			div.className = 'bmlt-minutes-latest__page';
			div.style.aspectRatio = vp.width + ' / ' + vp.height;
			div.setAttribute( 'role', 'region' );
			div.setAttribute( 'aria-label', label.replace( '%1$s', n ).replace( '%2$s', doc.numPages ) );
			pages.appendChild( div );
			items.push( { page: page, vp: vp, div: div, canvas: null, task: null, width: 0, near: false, text: false } );
			if ( 1 === n ) {
				view.replaceChildren( pages );
			}
		}

		var byDiv = new Map( items.map( function ( item ) {
			return [ item.div, item ];
		} ) );

		// Near the screen: draw. Far away: let the picture go (the text stays).
		var nearby = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				var item = byDiv.get( entry.target );
				item.near = entry.isIntersecting;
				if ( item.near ) {
					draw( item );
				}
			} );
		}, { rootMargin: '1200px 0px' } );
		var faraway = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					release( byDiv.get( entry.target ) );
				}
			} );
		}, { rootMargin: '5000px 0px' } );
		items.forEach( function ( item ) {
			nearby.observe( item.div );
			faraway.observe( item.div );
		} );

		// The column changed width (a phone turned, a window resized): scale the text, redraw what is drawn.
		if ( typeof window.ResizeObserver === 'function' ) {
			var last = pages.clientWidth;
			new ResizeObserver( function () {
				var width = pages.clientWidth;
				if ( ! width || width === last ) {
					return;
				}
				last = width;
				items.forEach( function ( item ) {
					scaleText( item );
					if ( item.canvas && Math.abs( item.div.clientWidth - item.width ) > item.width * 0.1 ) {
						draw( item, true );
					}
				} );
			} ).observe( pages );
		}

		// Every page's text, one after another, so Find searches the whole document.
		for ( var i = 0; i < items.length; i++ ) {
			await text( pdfjs, items[ i ] );
		}
	}

	function scaleText( item ) {
		var width = item.div.clientWidth;
		if ( width ) {
			item.div.style.setProperty( '--total-scale-factor', width / item.vp.width );
		}
	}

	async function draw( item, again ) {
		var width = item.div.clientWidth;
		if ( ! width || ( item.canvas && ! again ) || item.task ) {
			return;
		}
		scaleText( item );
		var scale = width / item.vp.width;
		var ratio = Math.min( window.devicePixelRatio || 1, 3 );
		// No more than 16 million pixels a page (phones limit canvas sizes).
		var pixels = width * ratio * item.vp.height * scale * ratio;
		if ( pixels > 16e6 ) {
			ratio *= Math.sqrt( 16e6 / pixels );
		}
		var viewport = item.page.getViewport( { scale: scale * ratio } );
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.floor( viewport.width );
		canvas.height = Math.floor( viewport.height );
		canvas.setAttribute( 'aria-hidden', 'true' );
		item.task = item.page.render( { canvas: canvas, viewport: viewport } );
		try {
			await item.task.promise;
		} catch ( e ) {
			item.task = null;
			return; // Cancelled (let go while drawing), or this page failed: the rest still show.
		}
		item.task = null;
		if ( ! item.near && ! again ) {
			return; // Scrolled far away meanwhile.
		}
		if ( item.canvas ) {
			item.canvas.replaceWith( canvas );
		} else {
			item.div.prepend( canvas );
		}
		item.canvas = canvas;
		item.width = width;
	}

	function release( item ) {
		if ( item.task ) {
			item.task.cancel();
			item.task = null;
		}
		if ( item.canvas ) {
			item.canvas.width = 0; // Frees the memory at once in every browser.
			item.canvas.remove();
			item.canvas = null;
		}
	}

	async function text( pdfjs, item ) {
		if ( item.text ) {
			return;
		}
		item.text = true;
		scaleText( item );
		var layer = document.createElement( 'div' );
		layer.className = 'textLayer';
		item.div.appendChild( layer );
		try {
			await new pdfjs.TextLayer( {
				textContentSource: item.page.streamTextContent(),
				container: layer,
				viewport: item.vp,
			} ).render();
		} catch ( e ) {
			layer.remove();
		}
		// The PDF's links to web pages and e-mail addresses.
		try {
			var notes = await item.page.getAnnotations( { intent: 'display' } );
			notes.forEach( function ( note ) {
				if ( note.subtype !== 'Link' || ! note.url || ! /^(https?:|mailto:)/i.test( note.url ) ) {
					return;
				}
				var r = item.vp.convertToViewportRectangle( note.rect );
				var a = document.createElement( 'a' );
				a.className = 'bmlt-minutes-latest__link';
				a.href = note.url;
				if ( ! /^mailto:/i.test( note.url ) ) {
					a.target = '_blank';
					a.rel = 'noopener';
				}
				a.setAttribute( 'aria-label', note.url );
				a.style.left = ( Math.min( r[ 0 ], r[ 2 ] ) / item.vp.width * 100 ) + '%';
				a.style.top = ( Math.min( r[ 1 ], r[ 3 ] ) / item.vp.height * 100 ) + '%';
				a.style.width = ( Math.abs( r[ 2 ] - r[ 0 ] ) / item.vp.width * 100 ) + '%';
				a.style.height = ( Math.abs( r[ 3 ] - r[ 1 ] ) / item.vp.height * 100 ) + '%';
				item.div.appendChild( a );
			} );
		} catch ( e ) {
			// No links: the page still shows.
		}
	}
}() );
