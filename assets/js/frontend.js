/**
 * Adventskalender – Frontend-Logik.
 *
 * Ohne externe Abhängigkeiten. Inhalte werden erst beim Öffnen vom Server
 * geholt; gesperrte Türchen enthalten keinerlei Inhaltsdaten.
 */
( function () {
	'use strict';

	var CONFIG = window.AdventskalenderData || {};
	var I18N = CONFIG.i18n || {};
	var REST = CONFIG.restUrl || '';
	var REDUCED = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Setzt Platzhalter wie %d / %1$d / %2$s in übersetzten Texten.
	 */
	function format( template, args ) {
		if ( ! template ) {
			return '';
		}
		var i = 0;
		return String( template )
			.replace( /%(\d+)\$[ds]/g, function ( match, index ) {
				var value = args[ parseInt( index, 10 ) - 1 ];
				return 'undefined' === typeof value ? match : String( value );
			} )
			.replace( /%[ds]/g, function () {
				var value = args[ i++ ];
				return 'undefined' === typeof value ? '' : String( value );
			} );
	}

	/**
	 * Lesen/Schreiben im localStorage – scheitert still, z. B. im Privatmodus.
	 */
	var Store = {
		read: function ( key ) {
			try {
				var raw = window.localStorage.getItem( key );
				var parsed = raw ? JSON.parse( raw ) : [];
				return Array.isArray( parsed ) ? parsed : [];
			} catch ( e ) {
				return [];
			}
		},
		write: function ( key, value ) {
			try {
				window.localStorage.setItem( key, JSON.stringify( value ) );
			} catch ( e ) {
				/* Speichern ist optional. */
			}
		}
	};

	/**
	 * Ein Kalender-Instanz-Objekt.
	 */
	function Calendar( root ) {
		this.root = root;
		this.year = root.getAttribute( 'data-year' ) || '';
		this.layout = root.getAttribute( 'data-layout' ) || 'classic';
		this.remember = '1' === root.getAttribute( 'data-remember' );
		this.mosaic = root.getAttribute( 'data-mosaic' ) || '';
		this.doors = Array.prototype.slice.call( root.querySelectorAll( '.ak-door' ) );
		this.lightbox = root.querySelector( '[data-ak-lightbox]' );
		this.dialog = root.querySelector( '[data-ak-dialog]' );
		this.contentEl = root.querySelector( '[data-ak-content]' );
		this.titleEl = root.querySelector( '[data-ak-title]' );
		this.dayEl = root.querySelector( '[data-ak-day]' );
		this.prevBtn = root.querySelector( '[data-ak-prev]' );
		this.nextBtn = root.querySelector( '[data-ak-next]' );
		this.toastEl = root.querySelector( '[data-ak-toast]' );
		this.storageKey = 'adventskalender:opened:' + this.year;
		this.cache = {};
		this.pending = {};
		this.currentDay = null;
		this.lastFocus = null;
		this.toastTimer = null;
		this.requestId = 0;

		this.init();
	}

	Calendar.prototype.init = function () {
		var self = this;

		this.doors.forEach( function ( door ) {
			door.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				self.handleDoorClick( door );
			} );
		} );

		this.restoreOpened();
		this.bindLightbox();
		this.applyMosaic();
		this.syncState();

		if ( this.mosaic ) {
			var resizeTimer = null;
			window.addEventListener( 'resize', function () {
				window.clearTimeout( resizeTimer );
				resizeTimer = window.setTimeout( function () {
					self.applyMosaic();
				}, 150 );
			} );
		}
	};

	/**
	 * Ermittelt die tatsächlich gerenderte Spaltenzahl des Rasters.
	 */
	Calendar.prototype.detectColumns = function () {
		var cells = this.root.querySelectorAll( '.ak-cell' );
		if ( ! cells.length ) {
			return 1;
		}
		var firstTop = cells[ 0 ].offsetTop;
		var columns = 0;
		for ( var i = 0; i < cells.length; i++ ) {
			if ( cells[ i ].offsetTop !== firstTop ) {
				break;
			}
			columns++;
		}
		return Math.max( 1, columns );
	};

	/**
	 * Verteilt das Mosaik-Bild passend zum aktuellen Raster.
	 */
	Calendar.prototype.applyMosaic = function () {
		if ( ! this.mosaic || 'mosaic' !== this.layout ) {
			return;
		}

		var cols = this.detectColumns();
		var total = this.doors.length;
		var rows = Math.max( 1, Math.ceil( total / cols ) );
		var url = this.mosaic;

		this.doors.forEach( function ( door, index ) {
			var front = door.querySelector( '.ak-door__front' );
			if ( ! front ) {
				return;
			}
			var col = index % cols;
			var row = Math.floor( index / cols );
			var x = cols > 1 ? ( col * 100 ) / ( cols - 1 ) : 0;
			var y = rows > 1 ? ( row * 100 ) / ( rows - 1 ) : 0;

			front.style.backgroundImage = 'url("' + url.replace( /"/g, '%22' ) + '")';
			front.style.backgroundSize = cols * 100 + '% ' + rows * 100 + '%';
			front.style.backgroundPosition = x.toFixed( 4 ) + '% ' + y.toFixed( 4 ) + '%';
		} );
	};

	/**
	 * Öffnet zuvor geöffnete Türchen optisch wieder.
	 */
	Calendar.prototype.restoreOpened = function () {
		if ( ! this.remember ) {
			return;
		}
		var opened = Store.read( this.storageKey );
		if ( ! opened.length ) {
			return;
		}
		this.doors.forEach( function ( door ) {
			var day = parseInt( door.getAttribute( 'data-day' ), 10 );
			if ( opened.indexOf( day ) !== -1 && 'locked' !== door.getAttribute( 'data-state' ) ) {
				door.classList.add( 'is-open' );
				door.setAttribute( 'aria-expanded', 'true' );
			}
		} );
	};

	/**
	 * Merkt ein geöffnetes Türchen.
	 */
	Calendar.prototype.rememberOpened = function ( day ) {
		if ( ! this.remember ) {
			return;
		}
		var opened = Store.read( this.storageKey );
		if ( opened.indexOf( day ) === -1 ) {
			opened.push( day );
			Store.write( this.storageKey, opened );
		}
	};

	/**
	 * Holt den aktuellen Freischaltzustand vom Server.
	 *
	 * Wichtig für stark gecachte Seiten: der Server bleibt die einzige
	 * Autorität darüber, welches Türchen offen ist.
	 */
	Calendar.prototype.syncState = function () {
		if ( ! REST || ! window.fetch ) {
			return;
		}

		var self = this;
		var url = REST + 'state?year=' + encodeURIComponent( this.year );

		window.fetch( url, { credentials: 'same-origin', headers: this.headers() } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data || ! Array.isArray( data.days ) ) {
					return;
				}
				data.days.forEach( function ( entry ) {
					self.updateDoorState( entry );
				} );
				self.applyMosaic();
			} )
			.catch( function () {
				/* Der serverseitig gerenderte Zustand bleibt gültig. */
			} );
	};

	/**
	 * Aktualisiert ein Türchen anhand der Serverantwort.
	 */
	Calendar.prototype.updateDoorState = function ( entry ) {
		var door = this.root.querySelector( '.ak-door[data-day="' + parseInt( entry.day, 10 ) + '"]' );
		if ( ! door ) {
			return;
		}

		var previous = door.getAttribute( 'data-state' );
		if ( previous === entry.state ) {
			if ( entry.notice ) {
				door.setAttribute( 'data-notice', entry.notice );
			}
			return;
		}

		door.setAttribute( 'data-state', entry.state );
		door.classList.remove( 'ak-door--locked', 'ak-door--closed', 'ak-door--empty' );
		door.classList.add( 'ak-door--' + entry.state );

		if ( 'locked' === entry.state ) {
			door.setAttribute( 'aria-disabled', 'true' );
			door.setAttribute( 'data-notice', entry.notice || '' );
			door.classList.remove( 'is-open' );
			door.setAttribute( 'aria-expanded', 'false' );
			door.setAttribute( 'aria-label', format( I18N.lockedDoor, [ entry.day, entry.notice || '' ] ) );
		} else {
			door.removeAttribute( 'aria-disabled' );
			door.setAttribute( 'aria-label', format( I18N.openDoor, [ entry.day ] ) );
			if ( entry.notice ) {
				door.setAttribute( 'data-notice', entry.notice );
			} else {
				door.removeAttribute( 'data-notice' );
			}
			if ( entry.preview ) {
				var inside = door.querySelector( '[data-ak-inside]' );
				if ( inside ) {
					inside.innerHTML = entry.preview;
				}
			}
			this.restoreOpened();
		}
	};

	/**
	 * Request-Header inklusive REST-Nonce für angemeldete Benutzer.
	 */
	Calendar.prototype.headers = function () {
		var headers = { Accept: 'application/json' };
		if ( CONFIG.nonce ) {
			headers[ 'X-WP-Nonce' ] = CONFIG.nonce;
		}
		return headers;
	};

	/**
	 * Klick auf ein Türchen.
	 */
	Calendar.prototype.handleDoorClick = function ( door ) {
		var state = door.getAttribute( 'data-state' );
		var day = parseInt( door.getAttribute( 'data-day' ), 10 );

		if ( 'locked' === state ) {
			this.shake( door );
			this.toast( door.getAttribute( 'data-notice' ) || '' );
			return;
		}

		var alreadyOpen = door.classList.contains( 'is-open' );

		door.classList.add( 'is-open' );
		door.setAttribute( 'aria-expanded', 'true' );
		this.rememberOpened( day );

		var delay = alreadyOpen || REDUCED ? 0 : 520;
		var self = this;

		this.loadDoor( day ).catch( function () {
			/* Fehler werden beim Öffnen der Lightbox gemeldet. */
		} );

		window.setTimeout( function () {
			self.openLightbox( day, door );
		}, delay );
	};

	/**
	 * Lädt den Inhalt eines Türchens (mit kleinem Cache).
	 */
	Calendar.prototype.loadDoor = function ( day ) {
		if ( this.cache[ day ] ) {
			return Promise.resolve( this.cache[ day ] );
		}
		if ( this.pending[ day ] ) {
			return this.pending[ day ];
		}
		if ( ! REST || ! window.fetch ) {
			return Promise.reject( new Error( 'no-fetch' ) );
		}

		var self = this;
		var url = REST + 'door/' + encodeURIComponent( day ) + '?year=' + encodeURIComponent( this.year );

		var request = window.fetch( url, { credentials: 'same-origin', headers: this.headers() } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						var error = new Error( 'request-failed' );
						error.data = data;
						error.status = response.status;
						throw error;
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				self.cache[ day ] = data;
				delete self.pending[ day ];
				return data;
			} )
			.catch( function ( error ) {
				delete self.pending[ day ];
				throw error;
			} );

		this.pending[ day ] = request;

		return request;
	};

	/**
	 * Öffnet die Lightbox für einen Tag.
	 */
	Calendar.prototype.openLightbox = function ( day, door ) {
		if ( ! this.lightbox ) {
			return;
		}

		var self = this;
		var token = ++this.requestId;

		this.currentDay = day;
		this.lastFocus = door || document.activeElement;

		this.lightbox.hidden = false;
		document.documentElement.classList.add( 'ak-no-scroll' );
		document.body.style.overflow = 'hidden';

		if ( this.dayEl ) {
			this.dayEl.textContent = format( I18N.doorLabel, [ day ] );
		}
		if ( this.titleEl ) {
			this.titleEl.textContent = '';
		}
		if ( this.contentEl ) {
			this.contentEl.innerHTML = '<p class="ak-lightbox__loading">' + escapeHtml( I18N.loading || '' ) + '</p>';
			this.contentEl.scrollTop = 0;
		}

		this.updateNav();

		if ( this.dialog ) {
			this.dialog.focus();
		}

		this.loadDoor( day )
			.then( function ( data ) {
				if ( token !== self.requestId || ! data ) {
					return;
				}
				self.renderContent( data );
			} )
			.catch( function ( error ) {
				if ( token !== self.requestId ) {
					return;
				}
				var message = I18N.error || '';
				if ( error && error.data && error.data.message ) {
					message = error.data.message;
				}
				if ( self.contentEl ) {
					self.contentEl.innerHTML = '<p class="ak-empty">' + escapeHtml( message ) + '</p>';
				}
				if ( error && 403 === error.status ) {
					self.markLocked( day );
				}
			} );
	};

	/**
	 * Schreibt die Serverantwort in die Lightbox.
	 */
	Calendar.prototype.renderContent = function ( data ) {
		if ( this.titleEl ) {
			this.titleEl.textContent = data.title || '';
		}
		if ( this.contentEl ) {
			// Das Markup stammt ausschließlich aus der eigenen REST-Route
			// und wurde serverseitig mit wp_kses_post bereinigt.
			this.contentEl.innerHTML = data.html || '';
			this.contentEl.scrollTop = 0;
			this.bindGalleryZoom( this.contentEl );
			this.playFirstVideo( this.contentEl );
		}
	};

	/**
	 * Markiert ein Türchen nachträglich als gesperrt (z. B. nach 403).
	 */
	Calendar.prototype.markLocked = function ( day ) {
		var door = this.root.querySelector( '.ak-door[data-day="' + day + '"]' );
		if ( ! door ) {
			return;
		}
		door.classList.remove( 'is-open' );
		door.setAttribute( 'data-state', 'locked' );
		door.setAttribute( 'aria-expanded', 'false' );
		door.setAttribute( 'aria-disabled', 'true' );
		door.classList.add( 'ak-door--locked' );
	};

	/**
	 * Setzt den Fokus bei geladenen Videos nicht automatisch auf Autoplay,
	 * sondern lädt nur das Vorschaubild (Rücksicht auf Datenvolumen).
	 */
	Calendar.prototype.playFirstVideo = function () {
		/* Bewusst leer: kein Autoplay. */
	};

	/**
	 * Galerie-Bilder im Vollbild anzeigen.
	 */
	Calendar.prototype.bindGalleryZoom = function ( scope ) {
		var buttons = scope.querySelectorAll( '[data-ak-zoom]' );
		if ( ! buttons.length ) {
			return;
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var src = button.getAttribute( 'data-ak-zoom' );
				if ( ! src ) {
					return;
				}
				var overlay = document.createElement( 'div' );
				overlay.className = 'ak-zoom';
				overlay.setAttribute( 'role', 'dialog' );
				overlay.setAttribute( 'aria-modal', 'true' );
				overlay.tabIndex = -1;

				var image = document.createElement( 'img' );
				image.src = src;
				image.alt = '';
				overlay.appendChild( image );

				function close() {
					overlay.remove();
					document.removeEventListener( 'keydown', onKey, true );
					button.focus();
				}

				function onKey( event ) {
					if ( 'Escape' === event.key ) {
						event.stopPropagation();
						close();
					}
				}

				overlay.addEventListener( 'click', close );
				document.addEventListener( 'keydown', onKey, true );
				document.body.appendChild( overlay );
				overlay.focus();
			} );
		} );
	};

	/**
	 * Verdrahtet Schließen, Tastatur und Navigation der Lightbox.
	 */
	Calendar.prototype.bindLightbox = function () {
		if ( ! this.lightbox ) {
			return;
		}

		var self = this;

		Array.prototype.forEach.call( this.lightbox.querySelectorAll( '[data-ak-close]' ), function ( element ) {
			element.addEventListener( 'click', function () {
				self.closeLightbox();
			} );
		} );

		if ( this.prevBtn ) {
			this.prevBtn.addEventListener( 'click', function () {
				self.step( -1 );
			} );
		}
		if ( this.nextBtn ) {
			this.nextBtn.addEventListener( 'click', function () {
				self.step( 1 );
			} );
		}

		this.lightbox.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				self.closeLightbox();
				return;
			}
			if ( 'Tab' === event.key ) {
				self.trapFocus( event );
				return;
			}
			if ( 'ArrowLeft' === event.key ) {
				self.step( -1 );
			}
			if ( 'ArrowRight' === event.key ) {
				self.step( 1 );
			}
		} );
	};

	/**
	 * Hält den Fokus innerhalb der Lightbox.
	 */
	Calendar.prototype.trapFocus = function ( event ) {
		if ( ! this.dialog ) {
			return;
		}

		var selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), video[controls], iframe, [tabindex]:not([tabindex="-1"])';
		var focusable = Array.prototype.filter.call( this.dialog.querySelectorAll( selector ), function ( element ) {
			return null !== element.offsetParent || 'IFRAME' === element.tagName;
		} );

		if ( ! focusable.length ) {
			event.preventDefault();
			this.dialog.focus();
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		var active = document.activeElement;

		if ( event.shiftKey && ( active === first || active === this.dialog ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	/**
	 * Liste der öffenbaren Tage in aufsteigender Reihenfolge.
	 */
	Calendar.prototype.openableDays = function () {
		return this.doors
			.filter( function ( door ) {
				return 'locked' !== door.getAttribute( 'data-state' );
			} )
			.map( function ( door ) {
				return parseInt( door.getAttribute( 'data-day' ), 10 );
			} )
			.sort( function ( a, b ) {
				return a - b;
			} );
	};

	/**
	 * Blättert zum vorherigen/nächsten Türchen.
	 */
	Calendar.prototype.step = function ( direction ) {
		var days = this.openableDays();
		var index = days.indexOf( this.currentDay );
		if ( index === -1 ) {
			return;
		}
		var target = days[ index + direction ];
		if ( 'undefined' === typeof target ) {
			return;
		}

		var door = this.root.querySelector( '.ak-door[data-day="' + target + '"]' );
		if ( door ) {
			door.classList.add( 'is-open' );
			door.setAttribute( 'aria-expanded', 'true' );
			this.rememberOpened( target );
		}
		this.openLightbox( target, door );
	};

	/**
	 * Aktiviert/deaktiviert die Navigationsschalter.
	 */
	Calendar.prototype.updateNav = function () {
		var days = this.openableDays();
		var index = days.indexOf( this.currentDay );

		if ( this.prevBtn ) {
			this.prevBtn.disabled = index <= 0;
		}
		if ( this.nextBtn ) {
			this.nextBtn.disabled = index === -1 || index >= days.length - 1;
		}
	};

	/**
	 * Schließt die Lightbox und gibt den Fokus zurück.
	 */
	Calendar.prototype.closeLightbox = function () {
		if ( ! this.lightbox || this.lightbox.hidden ) {
			return;
		}

		++this.requestId;
		this.lightbox.hidden = true;
		document.documentElement.classList.remove( 'ak-no-scroll' );
		document.body.style.overflow = '';

		// Laufende Medien anhalten.
		if ( this.contentEl ) {
			Array.prototype.forEach.call( this.contentEl.querySelectorAll( 'video, audio' ), function ( media ) {
				try {
					media.pause();
				} catch ( e ) {
					/* ignorieren */
				}
			} );
			this.contentEl.innerHTML = '';
		}

		if ( this.lastFocus && this.lastFocus.focus ) {
			this.lastFocus.focus();
		}
		this.currentDay = null;
	};

	/**
	 * Wackel-Animation für gesperrte Türchen.
	 */
	Calendar.prototype.shake = function ( door ) {
		if ( REDUCED ) {
			return;
		}
		door.classList.remove( 'is-shaking' );
		// Reflow erzwingen, damit die Animation erneut startet.
		void door.offsetWidth;
		door.classList.add( 'is-shaking' );
		window.setTimeout( function () {
			door.classList.remove( 'is-shaking' );
		}, 500 );
	};

	/**
	 * Kurze Hinweismeldung.
	 */
	Calendar.prototype.toast = function ( message ) {
		if ( ! this.toastEl || ! message ) {
			return;
		}
		var self = this;
		this.toastEl.textContent = message;
		this.toastEl.classList.add( 'is-visible' );
		window.clearTimeout( this.toastTimer );
		this.toastTimer = window.setTimeout( function () {
			self.toastEl.classList.remove( 'is-visible' );
		}, 3600 );
	};

	/**
	 * Minimaler HTML-Escape für eigene Textausgaben.
	 */
	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value );
		return div.innerHTML;
	}

	/**
	 * Initialisierung.
	 */
	function boot() {
		var roots = document.querySelectorAll( '[data-ak-calendar]' );
		Array.prototype.forEach.call( roots, function ( root ) {
			if ( root.dataset.akReady ) {
				return;
			}
			root.dataset.akReady = '1';
			new Calendar( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
