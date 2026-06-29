const { defineConfig } = require( 'cypress' );
require( 'dotenv' ).config();

module.exports = defineConfig( {
	env: {
		wpUser: process.env.CYPRESS_WP_USER || process.env.WP_USER || 'admin',
		wpPassword: process.env.CYPRESS_WP_PASSWORD || process.env.WP_PASSWORD || 'admin',
	},
	e2e: {
		baseUrl: process.env.CYPRESS_BASE_URL || process.env.BASE_URL || 'http://elementor.local/',
		setupNodeEvents( on, config ) {
			// implement node event listeners here
			return config;
		},
	},
} );
