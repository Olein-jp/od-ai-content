<?php
/**
 * Verify that the bundled Japanese catalog covers the POT template.
 *
 * @package OdAiContent
 */

$project_root = dirname( __DIR__ );
$pot_file     = $project_root . '/languages/od-ai-content.pot';
$po_file      = $project_root . '/languages/od-ai-content-ja.po';
$mo_file      = $project_root . '/languages/od-ai-content-ja.mo';

foreach ( array( $pot_file, $po_file, $mo_file ) as $required_file ) {
	if ( ! is_readable( $required_file ) ) {
		fwrite( STDERR, 'Required translation file is missing: ' . $required_file . PHP_EOL );
		exit( 1 );
	}
}

/**
 * Parse singular msgid/msgstr pairs from a PO-compatible file.
 *
 * @param string $file Catalog path.
 * @return array<string, string>
 */
function od_ai_content_parse_catalog( $file ) {
	$entries      = array();
	$current_id   = null;
	$current_value = null;
	$active_field = null;
	$lines        = file( $file, FILE_IGNORE_NEW_LINES );

	if ( false === $lines ) {
		return $entries;
	}

	$flush = static function () use ( &$entries, &$current_id, &$current_value ) {
		if ( null !== $current_id && '' !== $current_id ) {
			$entries[ $current_id ] = (string) $current_value;
		}

		$current_id    = null;
		$current_value = null;
	};

	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, 'msgid ' ) ) {
			$flush();
			$current_id   = od_ai_content_decode_po_string( substr( $line, 6 ) );
			$current_value = '';
			$active_field = 'id';
			continue;
		}

		if ( 0 === strpos( $line, 'msgstr ' ) ) {
			$current_value = od_ai_content_decode_po_string( substr( $line, 7 ) );
			$active_field  = 'value';
			continue;
		}

		if ( isset( $line[0] ) && '"' === $line[0] ) {
			$fragment = od_ai_content_decode_po_string( $line );

			if ( 'id' === $active_field ) {
				$current_id .= $fragment;
			} elseif ( 'value' === $active_field ) {
				$current_value .= $fragment;
			}
		}
	}

	$flush();

	return $entries;
}

/**
 * Decode a quoted PO string.
 *
 * @param string $value Quoted value.
 * @return string
 */
function od_ai_content_decode_po_string( $value ) {
	$decoded = json_decode( trim( $value ), true );

	return is_string( $decoded ) ? $decoded : '';
}

/**
 * Parse singular entries from a GNU MO file.
 *
 * @param string $file MO catalog path.
 * @return array<string, string>
 */
function od_ai_content_parse_mo_catalog( $file ) {
	$data = file_get_contents( $file );

	if ( false === $data || strlen( $data ) < 28 ) {
		return array();
	}

	$header = unpack(
		'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations/Vhash_size/Vhash_offset',
		substr( $data, 0, 28 )
	);

	if ( ! is_array( $header ) || 0x950412de !== $header['magic'] ) {
		return array();
	}

	$entries = array();

	for ( $index = 0; $index < $header['count']; $index++ ) {
		$original = unpack(
			'Vlength/Voffset',
			substr( $data, $header['originals'] + ( 8 * $index ), 8 )
		);
		$translated = unpack(
			'Vlength/Voffset',
			substr( $data, $header['translations'] + ( 8 * $index ), 8 )
		);

		if ( ! is_array( $original ) || ! is_array( $translated ) ) {
			return array();
		}

		$message_id = substr( $data, $original['offset'], $original['length'] );
		$message    = substr( $data, $translated['offset'], $translated['length'] );

		if ( '' !== $message_id ) {
			$entries[ $message_id ] = $message;
		}
	}

	return $entries;
}

/**
 * Extract translatable strings and plugin metadata from PHP source files.
 *
 * @param string $root Project root.
 * @return string[]
 */
function od_ai_content_extract_source_messages( $root ) {
	$files    = array_merge(
		array( $root . '/od-ai-content.php' ),
		glob( $root . '/includes/*.php' )
	);
	$messages = array();
	$pattern  = "/\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:\\\\\\\\'|[^'])*)'\\s*,\\s*'od-ai-content'\\s*\\)/";

	foreach ( $files as $file ) {
		$source = file_get_contents( $file );

		if ( false === $source ) {
			continue;
		}

		preg_match_all( $pattern, $source, $matches );

		foreach ( $matches[1] as $message ) {
			$messages[] = str_replace( "\\'", "'", $message );
		}

		if ( $root . '/od-ai-content.php' === $file ) {
			preg_match_all(
				'/^[ \t]*\*[ \t]+(?:Plugin Name|Description|Author):[ \t]+(.+)$/m',
				$source,
				$header_matches
			);
			$messages = array_merge( $messages, $header_matches[1] );
		}
	}

	return array_values( array_unique( $messages ) );
}

$template_entries = od_ai_content_parse_catalog( $pot_file );
$japanese_entries = od_ai_content_parse_catalog( $po_file );
$compiled_entries = od_ai_content_parse_mo_catalog( $mo_file );
$source_entries   = od_ai_content_extract_source_messages( $project_root );
$missing          = array();
$stale            = array();
$compiled_errors  = array();

foreach ( $source_entries as $message_id ) {
	if ( ! array_key_exists( $message_id, $template_entries ) ) {
		$missing[] = $message_id;
	}
}

foreach ( array_keys( $template_entries ) as $message_id ) {
	if ( ! in_array( $message_id, $source_entries, true ) ) {
		$stale[] = $message_id;
	}

	if ( ! isset( $japanese_entries[ $message_id ] ) || '' === $japanese_entries[ $message_id ] ) {
		$missing[] = $message_id;
		continue;
	}

	if (
		! isset( $compiled_entries[ $message_id ] )
		|| $japanese_entries[ $message_id ] !== $compiled_entries[ $message_id ]
	) {
		$compiled_errors[] = $message_id;
	}
}

if ( ! empty( $missing ) ) {
	fwrite( STDERR, "Translation template or Japanese translations are missing:\n- " . implode( "\n- ", array_unique( $missing ) ) . PHP_EOL );
	exit( 1 );
}

if ( ! empty( $stale ) ) {
	fwrite( STDERR, "Translation template contains stale messages:\n- " . implode( "\n- ", $stale ) . PHP_EOL );
	exit( 1 );
}

if ( ! empty( $compiled_errors ) ) {
	fwrite( STDERR, "Compiled MO translations are missing or stale:\n- " . implode( "\n- ", $compiled_errors ) . PHP_EOL );
	exit( 1 );
}

printf(
	"Verified %d Japanese translations and compiled MO catalog.\n",
	count( $template_entries )
);
