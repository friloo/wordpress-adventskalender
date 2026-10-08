/**
 * Adventskalender – Admin-Interaktionen (Medienauswahl, Feldlogik).
 */
( function ( $ ) {
	'use strict';

	var CONFIG = window.AdventskalenderAdmin || {};
	var I18N = CONFIG.i18n || {};

	/**
	 * Einzelne Medienauswahl (Bild oder Video).
	 */
	function initMediaField( field ) {
		var input = field.querySelector( '[data-ak-media-input]' );
		var preview = field.querySelector( '[data-ak-media-preview]' );
		var selectBtn = field.querySelector( '[data-ak-media-select]' );
		var removeBtn = field.querySelector( '[data-ak-media-remove]' );
		var type = field.getAttribute( 'data-type' ) || 'image';
		var frame = null;

		if ( ! input || ! selectBtn ) {
			return;
		}

		selectBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			if ( ! frame ) {
				frame = window.wp.media( {
					title: 'video' === type ? I18N.selectVideo : I18N.selectImage,
					button: { text: 'video' === type ? I18N.useVideo : I18N.useImage },
					library: { type: 'video' === type ? 'video' : 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					input.value = attachment.id;
					preview.innerHTML = '';

					var thumb = attachment.sizes && ( attachment.sizes.medium || attachment.sizes.thumbnail || attachment.sizes.full );
					if ( thumb && thumb.url ) {
						var img = document.createElement( 'img' );
						img.src = thumb.url;
						img.alt = '';
						preview.appendChild( img );
					} else {
						var name = document.createElement( 'span' );
						name.className = 'ak-media-field__filename';
						name.textContent = attachment.filename || attachment.title || '';
						preview.appendChild( name );
					}

					if ( removeBtn ) {
						removeBtn.hidden = false;
					}
				} );
			}

			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				input.value = '';
				preview.innerHTML = '';
				removeBtn.hidden = true;
			} );
		}
	}

	/**
	 * Mehrfachauswahl für Galerien.
	 */
	function initGalleryField( field ) {
		var input = field.querySelector( '[data-ak-gallery-input]' );
		var list = field.querySelector( '[data-ak-gallery-list]' );
		var selectBtn = field.querySelector( '[data-ak-gallery-select]' );
		var frame = null;

		if ( ! input || ! list || ! selectBtn ) {
			return;
		}

		function sync() {
			var ids = Array.prototype.map.call( list.children, function ( item ) {
				return item.getAttribute( 'data-id' );
			} );
			input.value = ids.join( ',' );
		}

		function bindRemove( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var item = button.closest( 'li' );
				if ( item ) {
					item.remove();
					sync();
				}
			} );
		}

		Array.prototype.forEach.call( field.querySelectorAll( '[data-ak-gallery-remove]' ), bindRemove );

		selectBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			frame = window.wp.media( {
				title: I18N.selectImages,
				button: { text: I18N.useImages },
				library: { type: 'image' },
				multiple: 'add'
			} );

			frame.on( 'open', function () {
				var selection = frame.state().get( 'selection' );
				( input.value || '' ).split( ',' ).forEach( function ( id ) {
					id = parseInt( id, 10 );
					if ( ! id ) {
						return;
					}
					var attachment = window.wp.media.attachment( id );
					attachment.fetch();
					selection.add( [ attachment ] );
				} );
			} );

			frame.on( 'select', function () {
				list.innerHTML = '';
				frame.state().get( 'selection' ).toJSON().forEach( function ( attachment ) {
					var item = document.createElement( 'li' );
					item.setAttribute( 'data-id', attachment.id );

					var thumb = attachment.sizes && ( attachment.sizes.thumbnail || attachment.sizes.medium || attachment.sizes.full );
					var img = document.createElement( 'img' );
					img.src = thumb && thumb.url ? thumb.url : attachment.url;
					img.alt = '';
					item.appendChild( img );

					var remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'ak-gallery-field__remove';
					remove.setAttribute( 'data-ak-gallery-remove', '' );
					remove.setAttribute( 'aria-label', I18N.remove || 'X' );
					remove.innerHTML = '&times;';
					bindRemove( remove );
					item.appendChild( remove );

					list.appendChild( item );
				} );
				sync();
			} );

			frame.open();
		} );
	}

	/**
	 * Zeigt nur die Felder, die zum gewählten Medientyp passen.
	 */
	function initConditionalFields( scope ) {
		function apply() {
			var typeInput = scope.querySelector( '[data-ak-media-type]:checked' );
			var type = typeInput ? typeInput.value : 'none';

			Array.prototype.forEach.call( scope.querySelectorAll( '[data-ak-when]' ), function ( element ) {
				element.hidden = element.getAttribute( 'data-ak-when' ) !== type;
			} );

			var sourceInput = scope.querySelector( '[data-ak-video-source]:checked' );
			var source = sourceInput ? sourceInput.value : 'embed';

			Array.prototype.forEach.call( scope.querySelectorAll( '[data-ak-when-video]' ), function ( element ) {
				element.hidden = element.getAttribute( 'data-ak-when-video' ) !== source;
			} );
		}

		Array.prototype.forEach.call(
			scope.querySelectorAll( '[data-ak-media-type], [data-ak-video-source]' ),
			function ( input ) {
				input.addEventListener( 'change', apply );
			}
		);

		apply();
	}

	/**
	 * Shortcode in die Zwischenablage kopieren.
	 */
	function initCopyButtons() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-copy]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var target = document.querySelector( button.getAttribute( 'data-ak-copy' ) );
				if ( ! target ) {
					return;
				}

				var done = function () {
					var original = button.textContent;
					button.textContent = I18N.copied || 'OK';
					window.setTimeout( function () {
						button.textContent = original;
					}, 1600 );
				};

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( target.value ).then( done, function () {} );
				} else {
					target.select();
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( e ) {
						/* ignorieren */
					}
				}
			} );
		} );
	}

	/**
	 * Rückfrage vor dem Neumischen.
	 */
	function initShuffleConfirm() {
		var checkbox = document.querySelector( '[data-ak-confirm-shuffle]' );
		if ( ! checkbox ) {
			return;
		}
		checkbox.addEventListener( 'change', function () {
			if ( checkbox.checked && I18N.confirmShuffle && ! window.confirm( I18N.confirmShuffle ) ) {
				checkbox.checked = false;
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-media]' ), initMediaField );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-gallery]' ), initGalleryField );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-fields]' ), initConditionalFields );
		initCopyButtons();
		initShuffleConfirm();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window.jQuery );
