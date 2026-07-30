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
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var useDispatch = wp.data.useDispatch;
	var useSelect = wp.data.useSelect;

	function EditorSettingsPanel() {
		var editorState = useSelect( function ( select ) {
				var editor = select( 'core/editor' );

				return {
					didSave: editor.didPostSaveRequestSucceed(),
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
		var diagnosisState = useState( null );
		var diagnosis = diagnosisState[ 0 ];
		var setDiagnosis = diagnosisState[ 1 ];
		var loadingState = useState( false );
		var isLoading = loadingState[ 0 ];
		var setIsLoading = loadingState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var diagnosisRequestId = useRef( 0 );
		var previousSaving = useRef( false );
		var refreshAfterSave = useRef( false );

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
			var requestId;

			if ( ! editorState.postId ) {
				return;
			}

			requestId = diagnosisRequestId.current + 1;
			diagnosisRequestId.current = requestId;
			setDiagnosis( null );
			setIsLoading( true );
			setError( '' );

			apiFetch( {
				method: 'POST',
				path: settings.diagnosisPath.replace( '%d', editorState.postId ),
			} ).then( function ( result ) {
				if ( requestId === diagnosisRequestId.current ) {
					setDiagnosis( result );
				}
			} ).catch( function ( requestError ) {
				if ( requestId === diagnosisRequestId.current ) {
					setError( requestError && requestError.message ? requestError.message : settings.diagnosisError );
				}
			} ).finally( function () {
				if ( requestId === diagnosisRequestId.current ) {
					setIsLoading( false );
				}
			} );
		}

		useEffect( function () {
			runDiagnosis();
		}, [ editorState.postId ] );

		useEffect( function () {
			if ( ! previousSaving.current && editorState.isSaving ) {
				diagnosisRequestId.current += 1;
				setDiagnosis( null );
				setIsLoading( false );
				setError( '' );
			}

			if ( previousSaving.current && ! editorState.isSaving && editorState.didSave ) {
				refreshAfterSave.current = true;
			}

			if ( refreshAfterSave.current && ! editorState.isSaving && ! editorState.isDirty ) {
				refreshAfterSave.current = false;
				runDiagnosis();
			}

			previousSaving.current = editorState.isSaving;
		}, [ editorState.didSave, editorState.isDirty, editorState.isSaving ] );

		function getCheckLabel( severity ) {
			var labels = {
				error: settings.errorCheckLabel,
				info: settings.infoCheckLabel,
				normal: settings.normalCheckLabel,
				warning: settings.warningCheckLabel,
			};

			return labels[ severity ] || labels.info;
		}

		function renderCheckList( checks, key ) {
			return createElement(
				'ul',
				{
					className: 'od-ai-content-diagnosis__checks',
					key: key,
				},
				checks.map( function ( check, index ) {
					var severity = [ 'error', 'info', 'normal', 'warning' ].indexOf( check.severity ) >= 0
						? check.severity
						: 'info';

					return createElement(
						'li',
						{
							className: 'od-ai-content-diagnosis__check od-ai-content-diagnosis__check--' + severity,
							key: check.code + '-' + index,
						},
						createElement(
							'span',
							{ className: 'od-ai-content-diagnosis__check-label' },
							getCheckLabel( severity )
						),
						createElement( 'span', null, check.message )
					);
				} )
			);
		}

		function renderDiagnosis() {
			var elements = [
				createElement( 'hr', { key: 'separator' } ),
				createElement( 'h3', { key: 'title' }, settings.diagnosisTitle ),
			];
			var checks;
			var issueChecks;
			var infoChecks;
			var normalChecks;
			var status;

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

			if ( diagnosis ) {
				checks = diagnosis.checks || [];
				issueChecks = checks.filter( function ( check ) {
					return 'error' === check.severity;
				} ).concat( checks.filter( function ( check ) {
					return 'warning' === check.severity;
				} ) );
				infoChecks = checks.filter( function ( check ) {
					return 'info' === check.severity;
				} );
				normalChecks = checks.filter( function ( check ) {
					return 'normal' === check.severity;
				} );
				status = [ 'error', 'excluded', 'normal', 'warning' ].indexOf( diagnosis.status ) >= 0
					? diagnosis.status
					: 'not-diagnosed';

				elements.push(
					createElement(
						'div',
						{
							'aria-live': 'polite',
							className: 'od-ai-content-diagnosis__status od-ai-content-diagnosis__status--' + status,
							key: 'status',
							role: 'status',
						},
						createElement(
							'span',
							{ className: 'od-ai-content-diagnosis__status-label' },
							settings.diagnosisStatusLabel
						),
						createElement( 'strong', null, diagnosis.status_label )
					)
				);

				if ( issueChecks.length ) {
					elements.push(
						createElement(
							'section',
							{
								className: 'od-ai-content-diagnosis__group',
								key: 'issues',
							},
							createElement( 'h4', null, settings.issuesTitle ),
							renderCheckList( issueChecks, 'issue-checks' )
						)
					);
				}

				if ( infoChecks.length ) {
					elements.push(
						createElement(
							'section',
							{
								className: 'od-ai-content-diagnosis__group',
								key: 'information',
							},
							createElement( 'h4', null, settings.informationTitle ),
							renderCheckList( infoChecks, 'info-checks' )
						)
					);
				}

				if ( normalChecks.length ) {
					elements.push(
						createElement(
							'details',
							{
								className: 'od-ai-content-diagnosis__passed',
								key: 'passed',
							},
							createElement(
								'summary',
								null,
								settings.passedChecksTitle + ' (' + normalChecks.length + ')'
							),
							renderCheckList( normalChecks, 'normal-checks' )
						)
					);
				}

				elements.push(
					createElement(
						'label',
						{
							className: 'od-ai-content-diagnosis__preview-label',
							key: 'preview-label',
						},
						settings.previewLabel
					),
					createElement( 'textarea', {
						'aria-label': settings.previewLabel,
						className: 'od-ai-content-diagnosis__preview',
						key: 'preview',
						readOnly: true,
						rows: 14,
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
						className: 'od-ai-content-diagnosis__run',
						disabled: editorState.isDirty || editorState.isSaving || isLoading,
						isBusy: isLoading,
						key: 'run',
						onClick: runDiagnosis,
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
