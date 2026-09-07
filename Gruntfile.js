module.exports = function( grunt ) {
	require( 'load-grunt-tasks' )( grunt );

	const copyFiles = [
		'**', // include everything
		'!node_modules/**', // exclude node_modules
		'!vendor/**', // exclude vendor directory
		'!assets/src/**', // exclude source assets/src
		'!cypress/**', // exclude Cypress tests
		'!tests/**', // exclude unit tests
		'!build/**', // exclude build output
		'!.git/**', // exclude git directory
		'!.gitignore', // exclude git config
		'!package.json', // exclude lock files
		'!package-lock.json', // exclude lock files
		'!composer.json', // exclude lock files
		'!composer.lock',
		'!*.config.js', // exclude config files used only for development
		'!Gruntfile.js', // exclude this build file
		'!webpack.config.js',
		'!babel.config.js',
		'!postcss.config.js',
		'!tailwind.config.js',
		'!cypress.config.js',
		'!phpcs.xml.dist', // exclude linting configs
		'!phpstan.neon.dist',
		'!phpunit.xml.dist',
		'!**/*.map', // exclude source maps
		'!**/.DS_Store', // exclude macOS metadata
		'!**/*.tmp', // exclude temporary files
		'!CLAUDE.md', // exclude CLAUDE.md
		'!AGENTS.md', // exclude AGENTS.md
		'CHANGELOG.md', // include CHANGELOG.md
	];

	// Project configuration
	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		// Clean task to remove temporary files and previous builds
		clean: {
			temp: {
				src: [ '**/*.tmp', '**/.afpDeleted*', '**/.DS_Store' ],
				dot: true,
				filter: 'isFile',
			},
			// Clean all build directories in assets folder and subfolders
			assets: {
				src: [
					'build/**', // All build directories in assets
				],
			},
			folder_v2: [ 'build/**' ],
		},

		// Check text domain for WordPress i18n
		checktextdomain: {
			options: {
				text_domain: 'aarambha-demo-sites',
				keywords: [
					'__:1,2d',
					'_e:1,2d',
					'_x:1,2c,3d',
					'esc_html__:1,2d',
					'esc_html_e:1,2d',
					'esc_html_x:1,2c,3d',
					'esc_attr__:1,2d',
					'esc_attr_e:1,2d',
					'esc_attr_x:1,2c,3d',
					'_ex:1,2c,3d',
					'_n:1,2,4d',
					'_nx:1,2,4c,5d',
					'_n_noop:1,2,3d',
					'_nx_noop:1,2,3c,4d',
				],
			},
			files: {
				src: [
					'inc/**/*.php',
					'!core/external/**', // Exclude external libs
				],
				expand: true,
			},
		},

		// Copy task for pro version
		copy: {
			pro: {
				files: [ {
					expand: true,
					src: copyFiles,
					dest: 'build/<%= pkg.name %>/',
				} ],
			},
		},

		// Compress task to create ZIP
		compress: {
			pro: {
				options: {
					mode: 'zip',
					archive: './build/<%= pkg.name %>-<%= pkg.version %>.zip',
				},
				expand: true,
				cwd: 'build/<%= pkg.name %>/',
				src: [ '**/*' ],
				dest: '<%= pkg.name %>/',
			},
		},

		// Search task configuration (if needed)
		search: {
			version: {
				files: {
					src: [ '*.php', 'inc/**/*.php' ],
				},
				options: {
					searchString: /Version:\s*(\d+\.\d+\.\d+)/,
					logFormat: 'console',
				},
			},
		},
	} );

	// Register tasks
	grunt.registerTask( 'version-compare', [ 'search:version' ] );
	grunt.registerTask( 'finish', function() {
		const json = grunt.file.readJSON( 'package.json' );
		const file = `./build/${ json.name }-${ json.version }.zip`;
		grunt.log.writeln( `Process finished. ZIP created: ${ file }` );
		grunt.log.writeln( '----------' );
	} );

	// Build task
	grunt.registerTask( 'build', [
		'checktextdomain',
		'copy:pro',
		'compress:pro',
		'finish',
	] );

	// Pre-build clean task
	grunt.registerTask( 'preBuildClean', [
		'clean:temp',
		'clean:assets',
		'clean:folder_v2',
	] );

	// release task
	grunt.registerTask( 'release', [ 'preBuildClean', 'build' ] );
};
