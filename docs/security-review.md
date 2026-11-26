# Security review

Last full review: 0.1.0, 2026-11-26

The plugin lets software that is not the visitor (an AI agent in the visitor's browser)
act on the site as the visitor. This is what it does to keep that safe, and what to check
before every release.

## What an agent can and cannot do

- **Only what the visitor can do.** Every tool runs on the server, in the visitor's
  session: their access levels for articles and categories, their language, their cart.
  The article query checks state, publishing dates, category state and access levels; the
  shop goes through J2Commerce's own models (`ProductsModel` for what is on the
  storefront, `CartModel::addCartItem()` for stock, options and quantity rules).
- **No order, no payment.** There is no tool that places an order. `go_to_checkout` only
  takes the visitor to the checkout page, where they enter their address, see the costs
  and confirm themselves. It carries `consequentialHint`.
- **Cart lines of this visitor only.** `remove_from_cart` checks that the line belongs to
  the visitor's own cart, as J2Commerce's own controller does.
- **Navigation stays on the site.** The script follows `navigate_to` only to the page's own
  origin.

## Requests

- **CSRF.** Every call needs the session's form token, from the page's script options.
  Another site cannot make the visitor's browser call a tool. Without a valid token the
  answer is "The page has expired".
- **Input is checked on the server**, against the tool's schema (`InputValidator`): types,
  lengths, ranges, enums, list sizes, and no unknown properties. The browser's own check,
  if any, is not relied on. JSON depth is limited to 8.
- **Queries** use the query builder with bound parameters; search words are escaped for
  `LIKE` and limited to 8 words of at most 100 characters.
- **Errors.** A tool's own failures (`ToolFailure`) give the agent a message that tells
  nothing the visitor may not know ("No article with id 12" for both missing and
  forbidden). Any other exception is logged; the agent gets a generic message.

## Content an agent reads

- Article text and product descriptions are written by people and may contain text aimed
  at the agent ("ignore your instructions…"). Those tools carry `untrustedContentHint`, and
  their descriptions say to treat the text as information, not instructions.
- HTML is turned into plain text: no tags, no scripts, no `{loadmodule}` codes.
- Output is limited: at most `max_results` items per search, article text at 20,000
  characters, product descriptions at 2,000.

## Known limits

- **Page cache.** With Joomla's page cache, a cached page carries an old form token, and
  the tools answer "The page has expired". Exclude pages from the cache, or leave the
  plugin off on cached sites, until the token is fetched separately.
- **No rate limit.** An agent (or a script with the page's token) can call tools as often
  as it can call the site's own pages. The tools are not more expensive than those pages,
  but a site that needs a limit must add it at the server.
- **The WebMCP draft is a draft.** The browser API may change; the script follows the draft
  of November 2026.

## Release checklist

- [ ] Every new tool runs as the visitor and uses the extension's own access checks.
- [ ] A new tool that changes something is not `readOnlyHint`; one with consequences
      (money, messages, accounts) is `consequentialHint` and leaves the final step to the
      visitor.
- [ ] Every input property has a type and a limit (length, range, items).
- [ ] Every error message an agent gets is one the visitor may see.
- [ ] Tools that return text written by others carry `untrustedContentHint`.
- [ ] `composer test`, `composer analyse`, `composer cs`, `composer audit` pass, and the
      Cypress specs pass on the development site.
- [ ] Update the "Last full review" line above.
