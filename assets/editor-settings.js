( function ( wp, settings ) {
	'use strict';

	if (
		! wp ||
		! settings ||
		! wp.components ||
		! wp.data ||
		! wp.editor ||
		! wp.element ||
		! wp.plugins ||
		! wp.apiFetch
	) {
		return;
	}

	var Button = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var Notice = wp.components.Notice;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var Spinner = wp.components.Spinner;
	var TextareaControl = wp.components.TextareaControl;
	var apiFetch = wp.apiFetch;
	var createElement = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var useState = wp.element.useState;
	var useDispatch = wp.data.useDispatch;
	var useSelect = wp.data.useSelect;

	function EditorSettingsPanel() {
		var editorState = useSelect( function ( select ) {
				var editor = select( 'core/editor' );

				return {
					isDirty: editor.isEditedPostDirty(),
					isSaving: editor.isSavingPost(),
					meta: editor.getEditedPostAttribute( 'meta' ) || {},
					postId: editor.getCurrentPostId(),
				};
			}, [] );
		var meta = editorState.meta;
		var editPost = useDispatch( 'core/editor' ).editPost;
		var exclusionValue = meta[ settings.exclusionMetaKey ];
		var llmsSelectionValue = meta[ settings.llmsSelectionMetaKey ];
		var isLlmsSelected = settings.llmsDefaultSelected;
		var previewState = useState( null );
		var preview = previewState[ 0 ];
		var setPreview = previewState[ 1 ];
		var loadingState = useState( false );
		var isLoading = loadingState[ 0 ];
		var setIsLoading = loadingState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

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

		function generatePreview() {
			if ( ! editorState.postId || editorState.isDirty || editorState.isSaving || isLoading ) {
				return;
			}

			setPreview( null );
			setIsLoading( true );
			setError( '' );

			apiFetch( {
				method: 'POST',
				path: settings.previewPath.replace( '%d', editorState.postId ),
			} ).then( function ( result ) {
				setPreview( result );
			} ).catch( function ( requestError ) {
				setError( requestError && requestError.message ? requestError.message : settings.previewError );
			} ).finally( function () {
				setIsLoading( false );
			} );
		}

		function renderBlockReport( blocks, title, key, modifier ) {
			if ( ! blocks.length ) {
				return null;
			}

			return createElement(
				'section',
				{
					className: 'od-ai-content-preview__report od-ai-content-preview__report--' + modifier,
					key: key,
				},
				createElement( 'h4', null, title ),
				createElement(
					'ul',
					null,
					blocks.map( function ( blockName ) {
						return createElement( 'li', { key: blockName }, blockName );
					} )
				)
			);
		}

		function renderMarkdownPreview() {
			var elements = [
				createElement( 'hr', { key: 'separator' } ),
				createElement( 'h3', { key: 'title' }, settings.previewTitle ),
			];
			var excludedBlocks;
			var fallbackBlocks;

			if ( editorState.isDirty && ! editorState.isSaving ) {
				elements.push(
					createElement(
						Notice,
						{
							isDismissible: false,
							key: 'dirty',
							status: 'warning',
						},
						settings.dirtyNotice
					)
				);
			}

			if ( isLoading ) {
				elements.push( createElement( Spinner, { key: 'spinner' } ) );
			}

			if ( error ) {
				elements.push(
					createElement(
						Notice,
						{
							isDismissible: false,
							key: 'error',
							status: 'error',
						},
						error
					)
				);
			}

			if ( preview ) {
				excludedBlocks = preview.excluded_blocks || [];
				fallbackBlocks = preview.fallback_blocks || [];

				elements.push(
					renderBlockReport(
						fallbackBlocks,
						settings.fallbackBlocksTitle,
						'fallback-blocks',
						'warning'
					),
					renderBlockReport(
						excludedBlocks,
						settings.excludedBlocksTitle,
						'excluded-blocks',
						'information'
					),
					createElement(
						'label',
						{
							className: 'od-ai-content-preview__preview-label',
							key: 'preview-label',
						},
						settings.previewLabel
					),
					createElement( 'textarea', {
						'aria-label': settings.previewLabel,
						className: 'od-ai-content-preview__preview',
						key: 'preview',
						readOnly: true,
						rows: 14,
						value: preview.markdown || '',
					} )
				);

				if ( preview.markdown_url ) {
					elements.push(
						createElement(
							'a',
							{
								href: preview.markdown_url,
								key: 'markdown-link',
								rel: 'noopener noreferrer',
								target: '_blank',
							},
							settings.viewMarkdownLabel
						)
					);
				}
			}

			elements.push(
				createElement(
					Button,
					{
						className: 'od-ai-content-preview__run',
						disabled: ! editorState.postId || editorState.isDirty || editorState.isSaving || isLoading,
						isBusy: isLoading,
						key: 'run',
						onClick: generatePreview,
						variant: 'secondary',
					},
					settings.runPreviewLabel
				)
			);

			return createElement(
				'div',
				{
					'aria-live': 'polite',
					className: 'od-ai-content-preview',
				},
				elements
			);
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
			} ),
			renderMarkdownPreview()
		);
	}

	registerPlugin( 'od-ai-content-editor-settings', {
		render: EditorSettingsPanel,
	} );
}( window.wp, window.odAiContentEditorSettings ) );
