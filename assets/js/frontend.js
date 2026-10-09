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

	// Name des Abfrageparameters für verlinkbare Türchen (serverseitig
	// über den Filter adventskalender_url_parameter änderbar).
	var PARAM = CONFIG.param || 'tuerchen';

	// Nur der erste Kalender einer Seite fasst die Adresszeile an.
	var urlTaken = false;

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
		this.ownsUrl = ! urlTaken;
		this.urlHandled = false;
		this.pushedState = false;
		this.ignoreNextPop = false;
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

		if ( this.ownsUrl ) {
			urlTaken = true;
		}

		this.restoreOpened();
		this.bindLightbox();
		this.bindHistory();
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
	 * Liest den verlinkten Tag aus der Adresszeile.
	 *
	 * @return {number|null}
	 */
	Calendar.prototype.dayFromUrl = function () {
		if ( ! window.URLSearchParams ) {
			return null;
		}

		var day = parseInt( new URLSearchParams( window.location.search ).get( PARAM ), 10 );

		return ( day >= 1 && day <= 31 ) ? day : null;
	};

	/**
	 * Schreibt oder entfernt den Tag in der Adresszeile.
	 *
	 * @param {number|null} day     Tag, oder null zum Entfernen.
	 * @param {boolean}     replace Eintrag ersetzen statt anfügen.
	 */
	Calendar.prototype.writeUrl = function ( day, replace ) {
		if ( ! this.ownsUrl || ! window.history || ! window.URL ) {
			return;
		}

		try {
			var url = new URL( window.location.href );
			if ( day ) {
				url.searchParams.set( PARAM, String( day ) );
			} else {
				url.searchParams.delete( PARAM );
			}

			var state = day ? { adventskalender: day } : {};
			if ( replace ) {
				window.history.replaceState( state, '', url.toString() );
			} else {
				window.history.pushState( state, '', url.toString() );
				this.pushedState = true;
			}
		} catch ( e ) {
			/* Ohne History-API bleibt die Adresszeile unverändert. */
		}
	};

	/**
	 * Reagiert auf Vor- und Zurück-Navigation.
	 *
	 * Ohne das verlässt die Zurück-Geste auf dem Telefon die ganze Seite,
	 * statt nur die Lightbox zu schließen.
	 */
	Calendar.prototype.bindHistory = function () {
		if ( ! this.ownsUrl || ! window.history ) {
			return;
		}

		var self = this;
		window.addEventListener( 'popstate', function () {
			if ( self.ignoreNextPop ) {
				self.ignoreNextPop = false;
				return;
			}

			var day = self.dayFromUrl();
			if ( day && self.openableDays().indexOf( day ) !== -1 ) {
				self.openFromUrl( day );
			} else {
				self.closeLightbox( { fromHistory: true } );
			}
		} );
	};

	/**
	 * Öffnet ein verlinktes Türchen, ohne die Adresszeile erneut zu ändern.
	 *
	 * @param {number} day Tag.
	 */
	Calendar.prototype.openFromUrl = function ( day ) {
		var door = this.root.querySelector( '.ak-door[data-day="' + day + '"]' );
		if ( ! door ) {
			return;
		}

		if ( 'locked' === door.getAttribute( 'data-state' ) ) {
			this.toast( door.getAttribute( 'data-notice' ) || '' );
			return;
		}

		this.markOpen( door, day );
		this.rememberOpened( day );
		this.openLightbox( day, door, { fromHistory: true } );
	};

	/**
	 * Prüft beim Laden, ob ein Türchen verlinkt wurde.
	 *
	 * Wird erst nach dem Zustandsabgleich aufgerufen: eine gecachte Seite
	 * könnte das Türchen sonst fälschlich für gesperrt halten.
	 */
	Calendar.prototype.maybeOpenFromUrl = function () {
		if ( ! this.ownsUrl || this.urlHandled ) {
			return;
		}
		this.urlHandled = true;

		var day = this.dayFromUrl();
		if ( ! day ) {
			return;
		}

		var door = this.root.querySelector( '.ak-door[data-day="' + day + '"]' );
		if ( door && door.scrollIntoView ) {
			door.scrollIntoView( { block: 'center', behavior: REDUCED ? 'auto' : 'smooth' } );
		}

		this.openFromUrl( day );
	};

	/**
	 * Verteilt das Mosaik-Bild über das Raster.
	 *
	 * Rechnet in Pixeln statt in Prozent pro Zelle: nur so bleiben die
	 * Lücken zwischen den Türchen Teil des Bildes, das Seitenverhältnis
	 * erhalten (Bild wird beschnitten, nicht verzerrt) und die Spaltenzahl
	 * egal. Das serverseitige Prozentverfahren bleibt als Rückfall für
	 * Besucher ohne JavaScript bestehen.
	 */
	Calendar.prototype.applyMosaic = function () {
		if ( ! this.mosaic || 'mosaic' !== this.layout ) {
			return;
		}

		if ( this.mosaicSize ) {
			this.paintMosaic();
			return;
		}

		var self = this;
		var probe = new Image();
		probe.onload = function () {
			self.mosaicSize = { w: probe.naturalWidth || 0, h: probe.naturalHeight || 0 };
			self.paintMosaic();
		};
		probe.onerror = function () {
			// Maße unbekannt: Bild über das Raster spannen.
			self.mosaicSize = { w: 0, h: 0 };
			self.paintMosaic();
		};
		probe.src = this.mosaic;
	};

	/**
	 * Schreibt die berechneten Hintergrundwerte in die Türchen.
	 */
	Calendar.prototype.paintMosaic = function () {
		var grid = this.root.querySelector( '.ak-grid' );
		if ( ! grid ) {
			return;
		}

		var area = grid.getBoundingClientRect();
		if ( ! area.width || ! area.height ) {
			return;
		}

		var natural = this.mosaicSize || { w: 0, h: 0 };
		var width = area.width;
		var height = area.height;
		var offsetX = 0;
		var offsetY = 0;

		if ( natural.w > 0 && natural.h > 0 ) {
			// Wie "cover": füllt das Raster, ohne zu verzerren.
			var scale = Math.max( area.width / natural.w, area.height / natural.h );
			width = natural.w * scale;
			height = natural.h * scale;
			offsetX = ( area.width - width ) / 2;
			offsetY = ( area.height - height ) / 2;
		}

		var url = 'url("' + this.mosaic.replace( /"/g, '%22' ) + '")';
		var size = Math.round( width ) + 'px ' + Math.round( height ) + 'px';

		this.doors.forEach( function ( door ) {
			var front = door.querySelector( '.ak-door__front' );
			if ( ! front ) {
				return;
			}
			// Bewusst das Türchen messen: die Klappe ist im offenen
			// Zustand gedreht und hätte ein verzerrtes Rechteck.
			var tile = door.getBoundingClientRect();
			front.style.backgroundImage = url;
			front.style.backgroundSize = size;
			front.style.backgroundPosition =
				Math.round( area.left + offsetX - tile.left ) + 'px ' +
				Math.round( area.top + offsetY - tile.top ) + 'px';
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
		var self = this;
		this.doors.forEach( function ( door ) {
			var day = parseInt( door.getAttribute( 'data-day' ), 10 );
			if ( opened.indexOf( day ) !== -1 && 'locked' !== door.getAttribute( 'data-state' ) ) {
				self.markOpen( door, day );
			}
		} );
	};

	/**
	 * Markiert ein Türchen als geöffnet – inklusive passender Beschriftung
	 * für Screenreader.
	 */
	Calendar.prototype.markOpen = function ( door, day, animate ) {
		door.classList.add( 'is-open' );
		door.setAttribute( 'aria-expanded', 'true' );
		door.setAttribute( 'aria-label', format( I18N.alreadyOpen, [ day ] ) );

		// Die aufgeklappte Tür verschwindet nach der Animation. Bliebe sie
		// stehen, läge am Ende jede geöffnete Klappe über ihrem linken
		// Nachbarn.
		if ( ! animate || REDUCED ) {
			door.classList.add( 'is-revealed' );
			return;
		}

		window.setTimeout( function () {
			door.classList.add( 'is-revealed' );
		}, 900 );
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
		var self = this;

		if ( ! REST || ! window.fetch ) {
			this.maybeOpenFromUrl();
			return;
		}

		var url = REST + 'state?year=' + encodeURIComponent( this.year );

		window.fetch( url, { credentials: 'same-origin', headers: this.headers() } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( data && Array.isArray( data.days ) ) {
					data.days.forEach( function ( entry ) {
						self.updateDoorState( entry );
					} );
					self.applyMosaic();
				}
				self.maybeOpenFromUrl();
			} )
			.catch( function () {
				// Der serverseitig gerenderte Zustand bleibt gültig.
				self.maybeOpenFromUrl();
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
			door.classList.remove( 'is-open', 'is-revealed' );
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

		this.markOpen( door, day, ! alreadyOpen );
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
	Calendar.prototype.openLightbox = function ( day, door, options ) {
		if ( ! this.lightbox ) {
			return;
		}

		options = options || {};

		var self = this;
		var token = ++this.requestId;
		// Beim Blättern innerhalb der Lightbox wird der Verlaufseintrag
		// ersetzt, nicht angehängt – einmal „zurück“ schließt dann alles.
		var wasOpen = false === this.lightbox.hidden;

		this.currentDay = day;
		this.lastFocus = door || document.activeElement;

		if ( ! options.fromHistory ) {
			this.writeUrl( day, wasOpen );
		}

		this.lightbox.hidden = false;
		document.documentElement.classList.add( 'ak-no-scroll' );
		document.body.style.overflow = 'hidden';

		if ( this.dayEl ) {
			this.dayEl.hidden = false;
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

		// Heißt das Türchen schlicht „Türchen 7“, stünde das zweimal
		// übereinander. Dann reicht die Überschrift.
		if ( this.dayEl ) {
			var normalise = function ( value ) {
				return String( value || '' ).replace( /\s+/g, ' ' ).trim().toLowerCase();
			};
			this.dayEl.hidden = normalise( data.title ) === normalise( this.dayEl.textContent );
		}
		if ( this.contentEl ) {
			// Das Markup stammt ausschließlich aus der eigenen REST-Route
			// und wurde serverseitig mit wp_kses_post bereinigt.
			this.contentEl.innerHTML = data.html || '';
			this.contentEl.scrollTop = 0;
			this.bindGalleryZoom( this.contentEl );
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
		door.classList.remove( 'is-open', 'is-revealed' );
		door.setAttribute( 'data-state', 'locked' );
		door.setAttribute( 'aria-expanded', 'false' );
		door.setAttribute( 'aria-disabled', 'true' );
		door.classList.remove( 'ak-door--closed', 'ak-door--empty' );
		door.classList.add( 'ak-door--locked' );
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
			this.markOpen( door, target );
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
	Calendar.prototype.closeLightbox = function ( options ) {
		if ( ! this.lightbox || this.lightbox.hidden ) {
			return;
		}

		options = options || {};

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

		if ( options.fromHistory ) {
			return;
		}

		if ( this.pushedState && window.history ) {
			// Den eigenen Eintrag wieder entfernen; das dadurch ausgelöste
			// popstate darf nicht erneut schließen.
			this.pushedState = false;
			this.ignoreNextPop = true;
			window.history.back();
		} else {
			this.writeUrl( null, true );
		}
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
