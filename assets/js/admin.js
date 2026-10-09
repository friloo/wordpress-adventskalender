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
					preview.classList.add( 'has-media' );

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
				preview.classList.remove( 'has-media' );
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

	/**
	 * Markenfarbe: Farbwähler, Live-Vorschau und Feldsteuerung.
	 */
	function initBrandColor() {
		var field = document.querySelector( '[data-ak-brand-color]' );
		var preview = document.querySelector( '[data-ak-brand-preview]' );
		if ( ! field || ! preview ) {
			return;
		}

		var calendar = preview.querySelector( '[data-ak-preview-calendar]' );
		var swatches = preview.querySelector( '[data-ak-swatches]' );
		var contrast = preview.querySelector( '[data-ak-contrast]' );
		var scheme = document.querySelector( '[data-ak-brand-scheme]' );
		var timer = null;
		var token = 0;

		/**
		 * Holt die Palette vom Server – dieselbe Berechnung wie im Frontend,
		 * damit Vorschau und Ausgabe nicht auseinanderlaufen können.
		 */
		function refresh() {
			if ( ! CONFIG.restUrl || ! window.fetch ) {
				return;
			}

			var color = field.value;
			var mode = scheme ? scheme.value : 'light';
			var current = ++token;

			var url = CONFIG.restUrl + 'palette?color=' + encodeURIComponent( color ) +
				'&scheme=' + encodeURIComponent( mode );

			window.fetch( url, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-WP-Nonce': CONFIG.nonce || '' }
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					if ( ! data || current !== token ) {
						return;
					}
					apply( data );
				} )
				.catch( function () {
					/* Vorschau bleibt wie sie ist. */
				} );
		}

		/**
		 * Überträgt die Serverantwort in die Vorschau.
		 */
		function apply( data ) {
			if ( calendar && data.palette ) {
				Object.keys( data.palette ).forEach( function ( property ) {
					calendar.style.setProperty( property, data.palette[ property ] );
				} );
				calendar.setAttribute( 'data-scheme', data.scheme || 'light' );
			}

			if ( swatches && data.palette ) {
				Array.prototype.forEach.call( swatches.children, function ( item ) {
					var key = item.getAttribute( 'data-key' );
					var value = data.palette[ key ];
					if ( ! value ) {
						return;
					}
					var chip = item.querySelector( '.ak-brand-swatches__chip' );
					var code = item.querySelector( 'code' );
					if ( chip ) {
						chip.style.background = value;
					}
					if ( code ) {
						code.textContent = value;
					}
				} );
			}

			if ( contrast && data.contrast ) {
				Array.prototype.forEach.call( contrast.children, function ( item ) {
					var key = item.getAttribute( 'data-key' );
					var check = data.contrast[ key ];
					if ( ! check ) {
						return;
					}
					item.classList.toggle( 'is-ok', !! check.passes );
					item.classList.toggle( 'is-low', ! check.passes );
					var mark = item.querySelector( '.ak-brand-contrast__mark' );
					var ratio = item.querySelector( '.ak-brand-contrast__ratio' );
					if ( mark ) {
						mark.textContent = check.passes ? '✓' : '!';
					}
					if ( ratio ) {
						ratio.textContent = String( check.ratio ).replace( '.', ',' ) + ':1';
					}
				} );
			}
		}

		function schedule() {
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 180 );
		}

		// Farbwähler von WordPress, wenn verfügbar.
		if ( $ && $.fn && $.fn.wpColorPicker ) {
			$( field ).wpColorPicker( {
				defaultColor: field.getAttribute( 'data-default-color' ),
				change: schedule,
				clear: schedule
			} );
		} else {
			field.addEventListener( 'input', schedule );
		}

		field.addEventListener( 'change', schedule );
		if ( scheme ) {
			scheme.addEventListener( 'change', refresh );
		}
	}

	/**
	 * Blendet die Markenfarbfelder nur für die passende Farbwelt ein.
	 */
	function initThemeFields() {
		var select = document.querySelector( '[data-ak-theme-select]' );
		var rows = document.querySelectorAll( '[data-ak-when-theme]' );
		if ( ! select || ! rows.length ) {
			return;
		}

		function apply() {
			Array.prototype.forEach.call( rows, function ( row ) {
				row.hidden = row.getAttribute( 'data-ak-when-theme' ) !== select.value;
			} );
		}

		select.addEventListener( 'change', apply );
		apply();
	}

	/**
	 * Zeigt die gewählte Zahlenschrift sofort an.
	 */
	function initFontPreview() {
		var select = document.querySelector( '[data-ak-font-select]' );
		var sample = document.querySelector( '[data-ak-font-sample]' );
		if ( ! select || ! sample ) {
			return;
		}

		function apply() {
			var option = select.options[ select.selectedIndex ];
			var stack = option ? option.getAttribute( 'data-stack' ) : '';
			sample.style.fontFamily = stack || '';

			// Auch die Türchen in der Markenvorschau mitziehen.
			var preview = document.querySelector( '[data-ak-preview-calendar]' );
			if ( preview ) {
				preview.style.setProperty( '--ak-door-number-font', stack || 'inherit' );
			}
		}

		select.addEventListener( 'change', apply );
		apply();
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-media]' ), initMediaField );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-gallery]' ), initGalleryField );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-ak-fields]' ), initConditionalFields );
		initCopyButtons();
		initShuffleConfirm();
		initThemeFields();
		initBrandColor();
		initFontPreview();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window.jQuery );
