/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );
const CssMinimizerPlugin = require( 'css-minimizer-webpack-plugin' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );
const CopyPlugin = require( 'copy-webpack-plugin' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Directory paths
const SRC_DIR = path.resolve( __dirname, 'assets/src' );
const BUILD_DIR = path.resolve( __dirname, 'assets/build' );

// Production flag
const isProduction = process.env.NODE_ENV === 'production';

// Ensure build directory exists
if ( ! fs.existsSync( BUILD_DIR ) ) {
	fs.mkdirSync( BUILD_DIR, { recursive: true } );
}

// Shared base config
const sharedConfig = {
	...defaultConfig,
	output: {
		path: BUILD_DIR,
		filename: '[name].js',
		chunkFilename: '[name].js',
	},
	devtool: isProduction ? false : defaultConfig.devtool,
	plugins: [
		...defaultConfig.plugins,
		new RemoveEmptyScriptsPlugin(),
		new CopyPlugin( {
			patterns: [
				{
					from: path.join( SRC_DIR, 'library' ),
					to: path.join( BUILD_DIR, 'library' ),
					noErrorOnMissing: true,
				},
			],
		} ),
	],
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...defaultConfig.optimization.splitChunks,
		},
		minimizer: defaultConfig.optimization.minimizer.concat( [ new CssMinimizerPlugin() ] ),
	},
};

// Styles config
const styles = {
	...sharedConfig,
	output: {
		path: path.join( BUILD_DIR, 'css' ),
		filename: '[name].js',
		chunkFilename: '[name].js',
	},
	entry: () => {
		const entries = {};
		const dir = path.join( SRC_DIR, 'css' );

		if ( fs.existsSync( dir ) ) {
			fs.readdirSync( dir ).forEach( ( fileName ) => {
				const fullPath = path.join( dir, fileName );
				if (
					! fs.lstatSync( fullPath ).isDirectory() &&
					fileName.match( /\.(scss|css)$/ )
				) {
					entries[ fileName.replace( /\.[^/.]+$/, '' ) ] = fullPath;
				}
			} );
		}

		return entries;
	},
	module: {
		rules: [
			// Keep all non-CSS/SCSS rules from sharedConfig
			...( sharedConfig?.module?.rules?.filter( ( rule ) => {
				return (
					! rule.test ||
					( ! rule.test.toString().includes( 'scss' ) &&
						! rule.test.toString().includes( 'css' ) )
				);
			} ) || [] ),
			// Custom SCSS/CSS rule with url() rewriting disabled
			{
				test: /\.(scss|css)$/,
				use: [
					MiniCssExtractPlugin.loader,
					{
						loader: 'css-loader',
						options: {
							url: false, // Preserve relative url() paths as-is (e.g. ../images/)
							importLoaders: 1,
						},
					},
					'sass-loader',
				],
			},
		],
	},
	plugins: [
		...sharedConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' &&
				plugin.constructor.name !== 'CopyPlugin', // Images already copied by scripts config
		),
	],
};

// Scripts config
const scripts = {
	...sharedConfig,
	output: {
		path: path.join( BUILD_DIR, 'js' ),
		filename: '[name].js',
		chunkFilename: '[name].js',
	},
	entry: {
		admin: path.resolve( process.cwd(), 'assets', 'src', 'js', 'admin.js' ),
	},
	module: {
		rules:
			sharedConfig?.module?.rules?.filter( ( rule ) => {
				return (
					! rule.test ||
					( ! rule.test.toString().includes( 'scss' ) &&
						! rule.test.toString().includes( 'css' ) )
				);
			} ) || [],
	},
	resolve: {
		...sharedConfig.resolve,
		extensions: [ '.tsx', '.ts', '.jsx', '.js' ],
		alias: {
			...( sharedConfig.resolve?.alias || {} ),
			'@': path.resolve( process.cwd(), 'assets', 'src' ),
		},
	},
};

module.exports = [ scripts, styles ];