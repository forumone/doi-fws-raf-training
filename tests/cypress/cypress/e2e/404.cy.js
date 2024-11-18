describe('404 Page', () => {
  beforeEach(() => {
    cy.visit('/non-existing-page', { failOnStatusCode: false });
  });

  it('should display the 404 page title', () => {
    cy.get('h1').should('contain.text', 'Page not found');
  });

  it('should display an appropriate message', () => {
    cy.get('.region-content').should('contain.text', 'The requested page could not be found.');
  });
});
