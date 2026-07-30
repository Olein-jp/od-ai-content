<?php
/**
 * Normalize archive staging timestamps for reproducible ZIP output.
 *
 * @package OdAiContent
 */

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php normalize-mtimes.php <directory> <unix-timestamp>\n" );
	exit( 1 );
}

$directory = $argv[1];
$timestamp = filter_var( $argv[2], FILTER_VALIDATE_INT );

if ( ! is_dir( $directory ) || false === $timestamp ) {
	fwrite( STDERR, "Invalid directory or timestamp.\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$directory,
		FilesystemIterator::SKIP_DOTS
	),
	RecursiveIteratorIterator::CHILD_FIRST
);

foreach ( $iterator as $item ) {
	if ( ! touch( $item->getPathname(), $timestamp, $timestamp ) ) {
		fwrite( STDERR, 'Unable to normalize timestamp: ' . $item->getPathname() . "\n" );
		exit( 1 );
	}
}

if ( ! touch( $directory, $timestamp, $timestamp ) ) {
	fwrite( STDERR, 'Unable to normalize timestamp: ' . $directory . "\n" );
	exit( 1 );
}
