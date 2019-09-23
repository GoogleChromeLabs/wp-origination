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

		// Shell actions.
		shell: {
			options: {
				stdout: true,
				stderr: true,
			},
			verify_matching_versions: {
				command: 'composer run-script verify-version-consistency',
			},
			dist: {
				command: 'composer run-script dist',
			},
		},

		// Deploys a git Repo to the WordPress SVN repo.
		wp_deploy: {
			deploy: {
				options: {
					plugin_slug: 'origination',
					build_dir: 'dist',
					assets_dir: 'wp-assets',
				},
			},
		},

	} );

	// Load tasks.
	grunt.loadNpmTasks( 'grunt-shell' );

	// Register tasks.
	grunt.registerTask( 'default', [
		'shell:dist',
	] );

	grunt.registerTask( 'deploy', [
		'shell:dist',
		// @todo Verify versions are matching.
		'wp_deploy',
	] );
};
