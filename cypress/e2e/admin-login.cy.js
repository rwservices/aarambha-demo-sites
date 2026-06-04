describe( 'Run login', () => {
	beforeEach( () => {
		cy.visit( '/wp-login.php' );

		cy.get( '#user_login' ).type( Cypress.env( 'wpUser' ) );
		cy.get( '#user_pass' ).type( Cypress.env( 'wpPassword' ) );
		cy.get( '#wp-submit' ).click();
	} );

	it( 'should take us to admin page', () => {
		const adminUrl = `${ Cypress.config( 'baseUrl' ) }wp-admin/`;

		cy.url().should( 'eq', adminUrl );
	} );
} );
