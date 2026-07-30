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

	var CheckboxControl = wp.components.CheckboxControl;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var Spinner = wp.components.Spinner;
	var TextareaControl = wp.components.TextareaControl;
	var apiFetch = wp.apiFetch;
	var createElement = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var useDispatch = wp.data.useDispatch;
	var useSelect = wp.data.useSelect;

	function EditorSettingsPanel() {
		var editorState = useSelect( function ( select ) {
				var editor = select( 'core/editor' );

				return {
					isDirty: editor.isEditedPostDirty(),
					meta: editor.getEditedPostAttribute( 'meta' ) || {},
					postId: editor.getCurrentPostId(),
				};
			}, [] );
		var meta = editorState.meta;
		var editPost = useDispatch( 'core/editor' ).editPost;
		var exclusionValue = meta[ settings.exclusionMetaKey ];
		var llmsSelectionValue = meta[ settings.llmsSelectionMetaKey ];
		var isLlmsSelected = settings.llmsDefaultSelected;
		var diagnosisState = useState( null );
		var diagnosis = diagnosisState[ 0 ];
		var setDiagnosis = diagnosisState[ 1 ];
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

		function runDiagnosis() {
			if ( ! editorState.postId ) {
				return;
			}

			setIsLoading( true );
			setError( '' );

			apiFetch( {
				method: 'POST',
				path: settings.diagnosisPath.replace( '%d', editorState.postId ),
			} ).then( function ( result ) {
				setDiagnosis( result );
			} ).catch( function ( requestError ) {
				setError( requestError && requestError.message ? requestError.message : settings.diagnosisError );
			} ).finally( function () {
				setIsLoading( false );
			} );
		}

		useEffect( function () {
			runDiagnosis();
		}, [ editorState.postId ] );

		function renderDiagnosis() {
			var elements = [
				createElement( 'hr', { key: 'separator' } ),
				createElement( 'h3', { key: 'title' }, settings.diagnosisTitle ),
			];

			if ( editorState.isDirty ) {
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

			if ( diagnosis ) {
				elements.push(
					createElement(
						'p',
						{ key: 'status' },
						createElement( 'strong', null, diagnosis.status_label )
					)
				);

				if ( diagnosis.checks && diagnosis.checks.length ) {
					elements.push(
						createElement(
							'ul',
							{ key: 'checks' },
							diagnosis.checks.map( function ( check, index ) {
								return createElement(
									'li',
									{ key: check.code + '-' + index },
									check.message
								);
							} )
						)
					);
				}

				elements.push(
					createElement(
						'label',
						{
							key: 'preview-label',
							style: { display: 'block', fontWeight: 600, marginBottom: '8px' },
						},
						settings.previewLabel
					),
					createElement( 'textarea', {
						'aria-label': settings.previewLabel,
						key: 'preview',
						readOnly: true,
						rows: 14,
						style: { fontFamily: 'monospace', width: '100%' },
						value: diagnosis.markdown || '',
					} )
				);

				if ( diagnosis.markdown_url ) {
					elements.push(
						createElement(
							'a',
							{
								href: diagnosis.markdown_url,
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
						disabled: isLoading,
						isBusy: isLoading,
						key: 'run',
						onClick: runDiagnosis,
						style: { display: 'block', marginTop: '12px' },
						variant: 'secondary',
					},
					settings.runDiagnosisLabel
				)
			);

			return elements;
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
				renderDiagnosis()
			);
	}

	registerPlugin( 'od-ai-content-editor-settings', {
		render: EditorSettingsPanel,
	} );
}( window.wp, window.odAiContentEditorSettings ) );
