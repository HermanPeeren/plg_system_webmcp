import { callTool, installModelContext } from '../support/model-context';

/**
 * The plugin registers its tools with the browser, and an agent can use them to search,
 * read and shop, with the visitor's own cart as the result.
 *
 * Needs the development site seeded with tools/seed-dev-site.php (docs/development.md).
 */
describe('WebMCP tools', () => {
  const tools = () => cy.window().then((win) => win.document.modelContext.getTools());

  beforeEach(() => {
    cy.clearCookies();
    cy.visit('/', { onBeforeLoad: installModelContext });

    // The script registers the tools as a module, after the page has loaded.
    tools().should('have.length', 9);
  });

  it('registers every tool with a title, a description and a schema', () => {
    tools().then((list) => {
      expect(list.map((t) => t.name)).to.deep.equal([
        'add_to_cart', 'get_article', 'get_cart', 'get_product', 'go_to_checkout',
        'list_product_filters', 'remove_from_cart', 'search_articles', 'search_products',
      ]);

      list.forEach((tool) => {
        expect(tool.title, tool.name).to.be.a('string').and.not.be.empty;
        expect(tool.description, tool.name).to.have.length.greaterThan(40);
        expect(tool.inputSchema.type, tool.name).to.equal('object');
      });
    });
  });

  it('marks only the reading tools as read-only, and checkout as consequential', () => {
    tools().then((list) => {
      const readOnly = list.filter((t) => t.annotations.readOnlyHint).map((t) => t.name);

      expect(readOnly).to.deep.equal(['get_article', 'get_cart', 'get_product', 'list_product_filters', 'search_articles', 'search_products']);
      expect(list.find((t) => t.name === 'go_to_checkout').annotations.consequentialHint).to.equal(true);
    });
  });

  it('finds and reads an article', () => {
    cy.window()
      .then((win) => callTool(win, 'search_articles', { query: 'cast iron' }))
      .then((found) => {
        const article = found.articles.find((a) => a.title === 'Caring for a cast iron skillet');
        expect(article, 'the blog article').to.exist;

        return cy.window().then((win) => callTool(win, 'get_article', { id: article.id }));
      })
      .then((article) => {
        expect(article.text).to.contain('seasoned');
        expect(article.text).not.to.contain('<p>');
      });
  });

  it('lists products by property, price and stock', () => {
    cy.window()
      .then((win) => callTool(win, 'search_products', { properties: ['Red', 'Outdoor'], max_price: 400, in_stock_only: true }))
      .then((result) => {
        expect(result.count).to.be.greaterThan(0);

        result.products.forEach((product) => {
          expect(product.properties.Colour).to.include('Red');
          expect(product.properties.Use).to.include('Outdoor');
          expect(product.price).to.be.at.most(400);
          expect(product.in_stock).to.equal(true);
        });
      });
  });

  it('answers an unknown property with the values there are', () => {
    cy.window()
      .then((win) => callTool(win, 'search_products', { properties: ['Purple'] }))
      .its('error')
      .should('contain', 'There is no property value "Purple"');
  });

  it('fills the visitor\'s own cart, which the cart page then shows', () => {
    cy.window()
      .then((win) => callTool(win, 'search_products', { properties: ['Red', 'Outdoor'], in_stock_only: true }))
      .then((result) => cy.window().then((win) => callTool(win, 'add_to_cart', { product_id: result.products[0].id, quantity: 2 })))
      .then((added) => {
        expect(added.added).to.equal(true);
        expect(added.cart.item_count).to.equal(1);
        expect(added.cart.items[0].quantity).to.equal(2);
      });

    cy.visit('/index.php?option=com_j2commerce&view=carts');
    cy.contains('Cast Iron Skillet');
  });

  it('chooses a required option before adding a product that needs one', () => {
    cy.window()
      .then((win) => callTool(win, 'search_products', { query: 'Spice Rack' }))
      .then((result) => {
        const product = result.products[0];
        expect(product.needs_options).to.equal(true);

        return cy.window().then((win) => callTool(win, 'add_to_cart', { product_id: product.id }).then((r) => ({ error: r.error, id: product.id })));
      })
      .then(({ error, id }) => {
        expect(error).to.contain('is required');

        return cy.window().then((win) => callTool(win, 'get_product', { id }));
      })
      .then((product) => {
        const option = product.options.find((o) => o.required);
        const value = option.values[0];

        return cy.window().then((win) => callTool(win, 'add_to_cart', { product_id: product.id, options: [`${option.option_id}:${value.value_id}`] }));
      })
      .then((added) => {
        expect(added.cart.items[0].options.join(' ')).to.contain(':');
      });
  });

  it('takes the visitor to the checkout, where they place the order themselves', () => {
    cy.window()
      .then((win) => callTool(win, 'go_to_checkout'))
      .its('error')
      .should('contain', 'The cart is empty');

    cy.window()
      .then((win) => callTool(win, 'search_products', { in_stock_only: true, limit: 1 }))
      .then((result) => cy.window().then((win) => callTool(win, 'add_to_cart', { product_id: result.products[0].id })))
      .then(() => cy.window().then((win) => callTool(win, 'go_to_checkout')))
      .then((result) => expect(result.navigate_to).to.contain('checkout'));

    cy.location('pathname', { timeout: 10000 }).should('contain', 'checkout');
  });

  it('refuses a call without the page\'s token', () => {
    cy.request({
      method: 'POST',
      url: '/index.php?option=com_ajax&plugin=webmcp&group=system&format=json',
      form: true,
      body: { tool: 'get_cart', input: '{}' },
    }).its('body.data.error').should('contain', 'expired');
  });
});
