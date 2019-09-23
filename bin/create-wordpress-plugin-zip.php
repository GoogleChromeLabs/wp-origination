#!/usr/bin/env php
<?php
/**
 * WordPress Plugin Distribution ZIP Builder
 *
 * @package   Google\WP_Origination
 * @author    Weston Ruter
 * @link      https://github.com/GoogleChromeLabs/wp-origination
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @copyright 2019 Google LLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable Generic.PHP.BacktickOperator.Found
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_system
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fwrite

if ( 'cli' !== php_sapi_name() ) {
	fwrite( STDERR, "Must run from CLI.\n" );
	exit( 1 );
}

chdir( __DIR__ . '/..' );

if ( file_exists( 'dist' ) ) {
	system( 'rm -rf dist' );
}

system( 'git archive --format tar --prefix=dist/ HEAD | tar -xf -' );

if ( ! chdir( 'dist' ) ) {
	echo "Unable to enter dist directory.\n";
	exit( 1 );
}

system( 'composer install --no-dev --optimize-autoloader' );
unlink( 'composer.json' );
unlink( 'composer.lock' );

$origination_file = file_get_contents( 'origination.php' );

$version_append = '-' . gmdate( 'Ymd\THis' ) . '-' . trim( `git --no-pager log -1 --format=%h` );

$origination_file = preg_replace(
	'/(Version:\s+\d+(\.\d+)*-[a-z]+\d*).*$/m',
	'${0}' . $version_append,
	$origination_file
);

$origination_file = preg_replace(
	'#\n\t*// DIST_REMOVED.+?}\n#s',
	'',
	$origination_file
);

file_put_contents( 'origination.php', $origination_file );

$md_readme = `git show HEAD:README.md`;
$wp_readme = `git show HEAD:wp-readme.txt`;
if ( ! preg_match( '#<!--\s*WP_README_DESCRIPTION\s*-->(.+?)<!--\s*/WP_README_DESCRIPTION\s*-->#s', $md_readme, $matches ) ) {
	echo "Unable to extract description from readme.\n";
	exit( 2 );
}
$wp_readme = str_replace( '{{WP_README_DESCRIPTION}}', trim( $matches[1] ), $wp_readme );
file_put_contents( 'readme.txt', $wp_readme );

system( 'zip -r ../origination.zip .' );

echo "Done!\n";
