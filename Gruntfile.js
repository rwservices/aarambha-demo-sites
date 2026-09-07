module.exports = function( grunt ) {
	require( 'load-grunt-tasks' )( grunt );

	const copyFiles = [
		'**', // include everything, then prune what should not ship

		// Dependencies & build artifacts
		'!node_modules/**',
		'!vendor/**', // no runtime composer deps; autoloader is not loaded
		'!build/**', // packaging output
		'!assets/src/**', // un-built source assets

		// Version control & CI
		'!.git/**',
		'!.github/**',
		'!.gitignore',
		'!.gitattributes',
		'!.wordpress-org/**', // wordpress.org SVN assets (banner/icon/screenshots)

		// Tests
		'!cypress/**',
		'!cypress.config.js',
		'!tests/**',

		// Dev tooling / editor config
		'!.claude/**',
		'!.vscode/**',
		'!.idea/**',
		'!*.config.js', // webpack/babel/postcss/tailwind/etc.
		'!Gruntfile.js',
		'!.editorconfig',
		'!.browserslistrc',
		'!.nvmrc',
		'!.eslintrc*',
		'!.eslintignore',
		'!.stylelintrc*',
		'!.stylelintignore',
		'!.prettierrc*',
		'!.prettierignore',

		// Package manifests & lock files (dev-only)
		'!package.json',
		'!package-lock.json',
		'!composer.json',
		'!composer.lock',

		// Lint / static-analysis config
		'!phpcs.xml',
		'!phpcs.xml.dist',
		'!phpstan.neon',
		'!phpstan.neon.dist',
		'!phpunit.xml',
		'!phpunit.xml.dist',

		// Agent / contributor docs
		'!CLAUDE.md',
		'!AGENTS.md',

		// Junk
		'!**/*.map', // source maps
		'!**/.DS_Store',
		'!**/*.tmp',
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
			// Previous packaging output (staged folder + zip).
			build: [ 'build/**' ],
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
					'*.php',
					'inc/**/*.php',
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
	} );

	// Report the version string declared in each place it lives, and flag any
	// that disagree with package.json. Dependency-free so it always runs.
	grunt.registerTask( 'version-compare', function() {
		const read = ( file ) => grunt.file.exists( file ) ? grunt.file.read( file ) : '';
		const pkgVersion = grunt.file.readJSON( 'package.json' ).version;

		const sources = {
			'package.json': pkgVersion,
			'aarambha-demo-sites.php (header)':
				( read( 'aarambha-demo-sites.php' ).match( /^\s*\*\s*Version:\s*(.+)$/m ) || [] )[ 1 ],
			'inc/helpers/constant.php (AARAMBHA_DS_VERSION)':
				( read( 'inc/helpers/constant.php' ).match( /AARAMBHA_DS_VERSION['"]\s*,\s*['"]([^'"]+)/ ) || [] )[ 1 ],
			'readme.txt (Stable tag)':
				( read( 'readme.txt' ).match( /^Stable tag:\s*(.+)$/m ) || [] )[ 1 ],
		};

		let mismatch = false;
		Object.keys( sources ).forEach( ( label ) => {
			const value = ( sources[ label ] || '' ).trim() || '(not found)';
			const ok = value === pkgVersion;
			if ( ! ok ) {
				mismatch = true;
			}
			grunt.log.writeln( `${ ok ? '[ok]  ' : '[diff]' } ${ label }: ${ value }` );
		} );

		if ( mismatch ) {
			grunt.fail.warn( `Version strings disagree with package.json (${ pkgVersion }).` );
		}
	} );
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
		'clean:build',
	] );

	// release task
	grunt.registerTask( 'release', [ 'preBuildClean', 'build' ] );
};
