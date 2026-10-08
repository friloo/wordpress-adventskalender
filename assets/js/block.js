/**
 * Adventskalender – Block für den Block-Editor.
 *
 * Bewusst ohne Build-Schritt: reines JavaScript mit wp.element.createElement.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor ? wp.blockEditor.InspectorControls : null;
	var components = wp.components || {};
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	var CONFIG = window.AdventskalenderBlock || {};
	var I18N = CONFIG.i18n || {};

	/**
	 * Baut Optionen für ein Auswahlfeld inklusive „übernehmen“-Eintrag.
	 */
	function options( source ) {
		var list = [ { label: I18N.inherit || '', value: '' } ];
		Object.keys( source || {} ).forEach( function ( key ) {
			list.push( { label: source[ key ], value: key } );
		} );
		return list;
	}

	registerBlockType( 'adventskalender/calendar', {
		edit: function ( props ) {
			var attributes = props.attributes || {};

			function set( key ) {
				return function ( value ) {
					var update = {};
					update[ key ] = 'undefined' === typeof value || null === value ? '' : String( value );
					props.setAttributes( update );
				};
			}

			var inspector = InspectorControls && PanelBody
				? el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: I18N.settings || '', initialOpen: true },
						TextControl && el( TextControl, {
							label: I18N.heading || '',
							value: attributes.heading || '',
							onChange: set( 'heading' ),
							__nextHasNoMarginBottom: true
						} ),
						TextControl && el( TextControl, {
							label: I18N.year || '',
							type: 'number',
							value: attributes.year || '',
							placeholder: I18N.inherit || '',
							onChange: set( 'year' ),
							__nextHasNoMarginBottom: true
						} ),
						SelectControl && el( SelectControl, {
							label: I18N.layout || '',
							value: attributes.layout || '',
							options: options( CONFIG.layouts ),
							onChange: set( 'layout' ),
							__nextHasNoMarginBottom: true
						} ),
						SelectControl && el( SelectControl, {
							label: I18N.theme || '',
							value: attributes.theme || '',
							options: options( CONFIG.themes ),
							onChange: set( 'theme' ),
							__nextHasNoMarginBottom: true
						} ),
						SelectControl && el( SelectControl, {
							label: I18N.columns || '',
							value: attributes.columns || '',
							options: [
								{ label: I18N.inherit || '', value: '' },
								{ label: '3', value: '3' },
								{ label: '4', value: '4' },
								{ label: '5', value: '5' },
								{ label: '6', value: '6' },
								{ label: '7', value: '7' },
								{ label: '8', value: '8' }
							],
							onChange: set( 'columns' ),
							__nextHasNoMarginBottom: true
						} ),
						SelectControl && el( SelectControl, {
							label: I18N.shuffle || '',
							value: attributes.shuffle || '',
							options: [
								{ label: I18N.inherit || '', value: '' },
								{ label: 'An', value: '1' },
								{ label: 'Aus', value: '0' }
							],
							onChange: set( 'shuffle' ),
							__nextHasNoMarginBottom: true
						} ),
						SelectControl && el( SelectControl, {
							label: I18N.snow || '',
							value: attributes.snow || '',
							options: [
								{ label: I18N.inherit || '', value: '' },
								{ label: 'An', value: '1' },
								{ label: 'Aus', value: '0' }
							],
							onChange: set( 'snow' ),
							__nextHasNoMarginBottom: true
						} )
					)
				)
				: null;

			var preview = ServerSideRender
				? el( ServerSideRender, {
					key: 'preview',
					block: 'adventskalender/calendar',
					attributes: attributes
				} )
				: el( 'p', { key: 'preview' }, I18N.description || '' );

			var blockProps = wp.blockEditor && wp.blockEditor.useBlockProps
				? wp.blockEditor.useBlockProps( { className: 'ak-block-preview' } )
				: { className: 'ak-block-preview' };

			return el( 'div', blockProps, inspector, preview );
		},

		save: function () {
			return null;
		}
	} );
} )( window.wp );
