/* eslint-env node */
/* eslint-disable camelcase, no-console, no-param-reassign */

/*
 * Copyright 2019 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {

		pkg: grunt.file.readJSON( 'package.json' ),

		// Clean up the build.
		clean: {
			build: {
				src: [ 'build' ]
			}
		},

		// Shell actions.
		shell: {
			options: {
				stdout: true,
				stderr: true
			},
			readme: {
				command: 'php ./vendor/xwp/wp-dev-lib/generate-markdown-readme' // Generate the readme.md.
			},
			create_build_zip: {
				command: 'if [ ! -e build ]; then echo "Run grunt build first."; exit 1; fi; if [ -e origination.zip ]; then rm origination.zip; fi; cd build; zip -r ../origination.zip .; cd ..; echo; echo "ZIP of build: $(pwd)/origination.zip"'
			}
		},

		// Deploys a git Repo to the WordPress SVN repo.
		wp_deploy: {
			deploy: {
				options: {
					plugin_slug: 'origination',
					build_dir: 'build',
					assets_dir: 'wp-assets'
				}
			}
		}

	} );

	// Load tasks.
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-shell' );

	// Register tasks.
	grunt.registerTask( 'default', [
		'build'
	] );

	grunt.registerTask( 'generate-readme-md', [
		'shell:readme'
	] );

	grunt.registerTask( 'build', function() {
		var done, spawnQueue, stdout;
		done = this.async();
		spawnQueue = [];
		stdout = [];

		spawnQueue.push(
			{
				cmd: 'git',
				args: [ '--no-pager', 'log', '-1', '--format=%h', '--date=short' ]
			},
			{
				cmd: 'git',
				args: [ 'ls-files' ]
			}
		);

		function finalize() {
			var commitHash, lsOutput, versionAppend, paths;
			commitHash = stdout.shift();
			lsOutput = stdout.shift();
			versionAppend = new Date().toISOString().replace( /\.\d+/, '' ).replace( /-|:/g, '' ) + '-' + commitHash;

			paths = lsOutput.trim().split( /\n/ ).filter( function( file ) {
				return ! /^(\.|bin|([^/]+)+\.(md|json|xml)|Gruntfile\.js|tests|wp-assets|dev-lib|readme\.md|composer\..*)/.test( file );
			} );
			paths.push( 'vendor/autoload.php' );
			paths.push( 'vendor/composer/*.*' );

			grunt.task.run( 'clean' );
			grunt.config.set( 'copy', {
				build: {
					src: paths,
					dest: 'build',
					expand: true,
					options: {
						noProcess: [ '*/**' ], // That is, only process origination.php and readme.txt.
						process: function( content, srcpath ) {
							var matches, version, versionRegex;
							if ( /origination\.php$/.test( srcpath ) ) {
								versionRegex = /(\*\s+Version:\s+)(\d+(\.\d+)+-\w+)/;

								// If not a stable build (e.g. 0.7.0-beta), amend the version with the git commit and current timestamp.
								matches = content.match( versionRegex );
								if ( matches ) {
									version = matches[ 2 ] + '-' + versionAppend;
									console.log( 'Updating version in origination.php to ' + version );
									content = content.replace( versionRegex, '$1' + version );
								}
							}
							return content;
						}
					}
				}
			} );

			grunt.task.run( 'generate-readme-md' );
			grunt.task.run( 'copy' );

			done();
		}

		function doNext() {
			var nextSpawnArgs = spawnQueue.shift();
			if ( ! nextSpawnArgs ) {
				finalize();
			} else {
				grunt.util.spawn(
					nextSpawnArgs,
					function( err, res ) {
						if ( err ) {
							throw new Error( err.message );
						}
						stdout.push( res.stdout );
						doNext();
					}
				);
			}
		}

		doNext();
	} );

	grunt.registerTask( 'create-build-zip', [
		'shell:create_build_zip'
	] );
};
