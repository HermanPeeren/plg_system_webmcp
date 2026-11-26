# Developing the WebMCP plugin

## Layout

`src/` is the root of the installable package, laid out as it will be on a site:

```
src/
  webmcp.xml                              the manifest; <files folder="plugins/system/webmcp">
  script.php                              install script: Joomla 6.1 / PHP 8.3 checks, enables the plugin
  media/plg_system_webmcp/
    joomla.asset.json                     the script as a web asset (module, depends on core)
    js/webmcp.js                          registers the tools with document.modelContext;
                                          each execute() posts to com_ajax
  plugins/system/webmcp/
    services/provider.php                 the composition root
    language/en-GB/                       plg_system_webmcp(.sys).ini, with the tools' titles and descriptions
    src/
      Extension/WebMcp.php                the plugin: offers the tools on every frontend HTML page
                                          (onBeforeCompileHead), runs a call (onAjaxWebmcp)
      Event/CollectToolsEvent.php         onWebMcpCollectTools: other plugins add tools
      Tool/Tool.php                       the interface every tool implements
      Tool/ToolRegistry.php               the tools of one request, by name
      Tool/InputValidator.php             checks the input against the tool's schema, on the server
      Tool/CallbackTool.php               a tool made of a definition and a closure
      Tool/BuiltInTools.php               this plugin's own tools, as its options switch them on
      Tool/ToolFailure.php                a failure with a message for the agent
      Content/                            search_articles, get_article, on ArticleRepository
      Shop/                               the shop tools (ShopTools) on the Shop interface;
                                          J2CommerceShop implements it with J2Commerce's models
tests/
  Unit/                                   PHPUnit, no Joomla, no database
  Support/                                fake tools, articles and shop
  cypress/                                specs against the development site, with a stand-in
                                          for document.modelContext (support/model-context.js)
build/build.php                           zips src/ into build/plg_system_webmcp-<version>.zip
tools/
  install-local.php                       installs the built package into /joomla
  seed-dev-site.php                       filters, stock, blog articles, menu items, guest checkout
  fetch-j2commerce.sh                     J2Commerce's source into /.deps, at a fixed commit
  phpstan-bootstrap.php                   Joomla constants for PHPStan
docs/
  comparison.md                           WebMCP or clicking: steps, time and tokens for one task
  security-review.md                      the review and the release checklist
```

The namespace is `Yepr\Plugin\System\WebMcp`.

## How a tool call works

1. On a frontend HTML page, `WebMcp::offerTools()` collects the tools (its own, then
   `onWebMcpCollectTools`) and puts their definitions into the script options
   `plg_system_webmcp`, with the com_ajax URL and the session's form token.
2. `webmcp.js` registers each one with `document.modelContext.registerTool()`, if the
   browser has WebMCP. Its `execute()` posts `tool` and `input` (JSON) to
   `index.php?option=com_ajax&plugin=webmcp&group=system&format=json`.
3. `WebMcp::callTool()` checks the token, finds the tool, validates the input against its
   schema and runs it. The answer is `{ok: true, result}` or `{ok: false, error}`.
4. The script returns the result to the agent, or throws the error message. A result
   with `navigate_to` (same origin only) takes the visitor there afterwards.

## Adding a tool from another extension

```php
public static function getSubscribedEvents(): array
{
    return ['onWebMcpCollectTools' => 'addTools'];
}

public function addTools(\Yepr\Plugin\System\WebMcp\Event\CollectToolsEvent $event): void
{
    $event->addTool(new MyTool(...));   // implements Yepr\Plugin\System\WebMcp\Tool\Tool
}
```

Write the description for the agent: what the tool does, what it returns, what it does
not do. Give every input a type and a limit. Use `readOnlyHint` only for tools that change
nothing, and `consequentialHint` for tools with consequences.

## The development site

`/joomla` holds Joomla 6.2.1 with J2Commerce 6.6.6, installed and git-ignored:

- URL: http://localhost/plg_system_webmcp/joomla (WAMP's Apache)
- database `jlatest-webmcp` (user `root`, no password), table prefix `jwm_`
- the admin login is the one you install it with

To set it up again from scratch (check the latest stable Joomla first with
`gh release view --repo joomla/joomla-cms --json tagName --jq .tagName`):

```bash
mkdir -p .deps/joomla-zip
curl -sSL -o .deps/joomla-zip/joomla.zip https://github.com/joomla/joomla-cms/releases/download/6.2.1/Joomla_6.2.1-Stable-Full_Package.zip
mkdir -p joomla && unzip -q .deps/joomla-zip/joomla.zip -d joomla
php joomla/installation/joomla.php install -n --site-name="WebMCP dev" --admin-user=Admin --admin-username=yourusername --admin-password=yourpassword --admin-email=admin@example.invalid --db-type=mysqli --db-host=localhost --db-user=root --db-pass= --db-name=jlatest-webmcp --db-prefix=jwm_
rm -rf joomla/installation
mysql -uroot jlatest-webmcp -e "UPDATE jwm_guidedtours SET autostart = 0"

# J2Commerce: built from its source (no GitHub releases); esbuild is not needed with --no-minify
bash tools/fetch-j2commerce.sh
php .deps/j2commerce/build/build_package.php --release=6.6.6 --no-minify
php joomla/cli/joomla.php extension:install --path="$(pwd)/.deps/j2commerce/docs/packages/pkg_j2commerce_6-6-6.zip"
php joomla/cli/joomla.php j2commerce:load:sampledata --profile=standard --clean --yes

composer install-local
php tools/seed-dev-site.php
```

`seed-dev-site.php` can be run again; run it again after reloading J2Commerce's sample data.

## Commands

| Command | What it does |
|---|---|
| `composer test` | PHPUnit |
| `composer analyse` | PHPStan, level 8, against the Joomla in `/joomla` and J2Commerce in `/.deps` |
| `composer cs` | phpcs: PSR-12, with Joomla's tabs in `src/` |
| `composer cs-fix` | php-cs-fixer on tests, build and tools |
| `composer audit` | known vulnerabilities in the dependencies |
| `composer build` | the package zip in `build/` |
| `composer install-local` | build, then install into `/joomla` through Joomla's CLI |
| `npx cypress run` | the Cypress specs; copy `cypress.env.json.dist` to `cypress.env.json` first |

CI runs test, analyse, cs and audit on every push. Cypress needs the seeded development
site, so it is run by hand.

## Trying it in a browser

A browser with WebMCP: Chrome with `chrome://flags/#enable-webmcp-testing` enabled (or the
origin trial), and an agent or the Model Context Tool Inspector extension to call the
tools. Without one, the page still offers `window.JoomlaWebMcp.callTool(name, input)` for
trying a tool from the console.

## Known limits

- **Checkbox options** (several values for one option) are not offered by `get_product`
  and `add_to_cart` yet; select, radio and colour options are.
- **Text, date and file options** are not offered.
- **J2Commerce reads its add-to-cart input from the request**, so `J2CommerceShop` puts the
  tool's input there before it calls `CartModel::addCartItem()`.
- **Page cache**: see docs/security-review.md.
