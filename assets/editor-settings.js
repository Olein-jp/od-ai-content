( function ( wp, settings ) {
	'use strict';

	if (
		! wp ||
		! settings ||
		! wp.components ||
		! wp.data ||
		! wp.editor ||
		! wp.element ||
		! wp.plugins
	) {
		return;
	}

	var CheckboxControl = wp.components.CheckboxControl;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var TextareaControl = wp.components.TextareaControl;
	var createElement = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var useDispatch = wp.data.useDispatch;
	var useSelect = wp.data.useSelect;

	function EditorSettingsPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var exclusionValue = meta[ settings.exclusionMetaKey ];
		var llmsSelectionValue = meta[ settings.llmsSelectionMetaKey ];
		var isLlmsSelected = settings.llmsDefaultSelected;

		if ( '1' === llmsSelectionValue ) {
			isLlmsSelected = true;
		} else if ( '0' === llmsSelectionValue ) {
			isLlmsSelected = false;
		}

		function updateMeta( key, value ) {
			var nextMeta = Object.assign( {}, meta );

			nextMeta[ key ] = value;
			editPost( { meta: nextMeta } );
		}

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'document-settings',
				title: settings.panelTitle,
			},
			createElement( CheckboxControl, {
				checked: '1' === exclusionValue,
				label: settings.exclusionLabel,
				onChange: function ( isChecked ) {
					updateMeta( settings.exclusionMetaKey, isChecked ? '1' : '0' );
				},
			} ),
			createElement( CheckboxControl, {
				checked: isLlmsSelected,
				label: settings.llmsSelectionLabel,
				onChange: function ( isChecked ) {
					updateMeta( settings.llmsSelectionMetaKey, isChecked ? '1' : '0' );
				},
			} ),
			createElement( TextareaControl, {
				label: settings.descriptionLabel,
				maxLength: 280,
				onChange: function ( value ) {
					updateMeta( settings.descriptionMetaKey, value );
				},
				rows: 4,
				value: meta[ settings.descriptionMetaKey ] || '',
			} )
		);
	}

	registerPlugin( 'od-ai-content-editor-settings', {
		render: EditorSettingsPanel,
	} );
}( window.wp, window.odAiContentEditorSettings ) );
