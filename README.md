# WebMCP for Joomla

A Joomla 6 system plugin that offers the site to AI agents in the browser, as
[WebMCP](https://github.com/webmachinelearning/webmcp) tools: instead of reading pages and
clicking buttons, an agent calls functions the site itself provides.

**Status: prototype.** WebMCP is a draft standard of the W3C Web Machine Learning Community
Group, in an origin trial in Chrome. This plugin follows the draft of November 2026 and
works with Chrome 158's own WebMCP (behind `chrome://flags/#enable-webmcp-testing`).

## What an agent can do

| Tool | Does |
|---|---|
| `search_articles` | find articles by words in their title or text |
| `get_article` | read one article as plain text |
| `list_product_filters` | the properties products can be filtered on (colour, material…) |
| `search_products` | products by words, properties, price range and stock |
| `get_product` | one product, with the options to choose from |
| `add_to_cart` | put a product in the visitor's cart |
| `get_cart` | what is in the cart, and the totals |
| `remove_from_cart` | take a line out of the cart |
| `go_to_checkout` | take the visitor to the checkout page |

The shop tools work with [J2Commerce](https://www.j2commerce.com) 6. There is deliberately
no tool that places an order: the agent fills the cart, the visitor checks out.

Every tool runs on the server as the visitor (their session, access levels and cart),
with the input checked against the tool's schema. Other extensions can add their own
tools through the `onWebMcpCollectTools` event.

## Why

On the development site, one shopping task ("a red product for outdoor use, in stock,
under $400, into the cart") took an agent 33 steps and 86 seconds by reading and clicking,
and 2 steps and 6 seconds with these tools. See [docs/comparison.md](docs/comparison.md)
for what that does and does not show.

## Installing

Requires Joomla 6.1 or later and PHP 8.3. Install the package from the releases, and the
plugin is enabled. Its options switch the article and shop tools on or off and set the
maximum number of results.

To try it you need a browser with WebMCP (Chrome with `chrome://flags/#enable-webmcp-testing`)
and an agent that uses it, or the Model Context Tool Inspector extension.

## Development

See [docs/development.md](docs/development.md) and [docs/security-review.md](docs/security-review.md).

## License

GNU General Public License version 3 or later.
