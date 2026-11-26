# WebMCP or clicking: what an agent spends on one task

A first, small measurement on the development site (Joomla 6.2.1, J2Commerce 6.6.6,
seeded with `tools/seed-dev-site.php`), 26 November 2026.

## The task

> Find a red product for outdoor use, in stock, under $400, and put it in the cart.

The agent starts on the home page, with an empty cart. Both runs end with the same
result: one Cast Iron Skillet 12" #3 ($305.77) in the visitor's cart, as the J2Commerce
cart page shows.

## The two runs

The agent was Claude (Opus 5.5) in the Claude desktop app's built-in browser (Chromium
156), doing the task one step at a time, the way a browser agent works: one tool call,
read the result, decide the next step.

**A. Reading and clicking**, as browser agents do on any site today: open pages, read their
text or accessibility tree, find and click links, checkboxes and buttons.

**B. WebMCP**: the tools this plugin registers with `document.modelContext`. That browser
has no WebMCP switched on, so a stand-in with the draft's API (`registerTool`, `getTools`,
`executeTool`, as in `tests/cypress/support/model-context.js`) was put in the page. The
plugin's script and the server side are the real ones.

## Results

|                                            | A: reading and clicking           | B: WebMCP tools                     |
|--------------------------------------------|-----------------------------------|-------------------------------------|
| Agent steps (tool calls)                   | 33, of which 3 failed, 2 screenshots | 2 (`search_products`, `add_to_cart`) |
| Wall time, including the agent's thinking  | 86 s                              | 5.7 s                               |
| Server time of the tool calls              | (page loads)                      | 0.4 s + 0.16 s                      |
| Text read from the site                    | ≈ 10,000 characters + 2 images    | ≈ 900 characters of results         |
| Tool definitions read once                 | –                                 | 5,430 characters (9 tools)          |
| Tokens of site content, roughly (chars / 4)| ≈ 2,500 + images                  | ≈ 1,600, of which 1,350 definitions |

What made run A long:

- The product list shows no colours or uses. The agent has to find the filter panel.
- Each filter click reloads the list, and the "Use" group closes again after each reload.
  Three clicks missed: an element off screen, a click on the box instead of the label.
- Two screenshots were needed to find an element the text tools did not reach.

## What the numbers mean, and what they do not

- **Steps matter more than characters.** Each step is a full round trip to the model,
  which reads the whole conversation again: the token cost of an agent grows with the
  number of steps times the length of the context. 33 steps against 2 is the real
  difference, more than the 10,000 against 900 characters.
- **The tool definitions are a fixed cost** per page: 9 tools take 5,430 characters. For a
  one-off question they are most of what WebMCP costs; for a task of several steps they
  pay for themselves quickly. Short descriptions matter.
- **Run A was a good case for clicking.** The agent used text tools (accessibility tree,
  page text), not only screenshots; it knew the site was a J2Commerce shop with filters;
  and the shop has a filter for each property. Agents that work from screenshots, or a
  shop without such filters, need more steps.
- **One task, one run each.** This shows the order of magnitude, not a benchmark. The
  WebMCP run used a stand-in for the browser API; with a real browser agent, the
  browser and the agent add their own overhead per call.
- **WebMCP gives reliability as well as speed.** The tool answers "out of stock", "Color is
  required" or "there is no property value Purple" as text. A clicking agent has to
  notice such a message on the page.

## Repeating it

1. Set up the development site (docs/development.md) and empty the cart.
2. Run A: give an agent the task and the home page URL, with only page tools.
3. Run B: the same, with the WebMCP tools (a browser with WebMCP switched on, or the
   stand-in from `tests/cypress/support/model-context.js`).
4. Count the steps, the time from the first to the last step, and the characters of
   every result the agent read.
