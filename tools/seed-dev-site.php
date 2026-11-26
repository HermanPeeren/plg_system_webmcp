<?php

/**
 * Fill the development site with what the tools work on, after J2Commerce's own sample data:
 *
 *   php joomla/cli/joomla.php j2commerce:load:sampledata --profile=standard --clean --yes
 *   php tools/seed-dev-site.php            # into ./joomla
 *
 * J2Commerce's sample data has products, options and orders but no product filters, and
 * the site has no articles of its own or menu items. This adds:
 *
 * - three filter groups (Colour, Material, Use) with values, given to the products in a
 *   fixed pattern, so a question like "red products for outdoor use" has answers;
 * - a Blog category with a few articles;
 * - menu items Shop (all shop categories, with the filter sidebar) and Blog, so a person,
 *   or an agent that only clicks, can find them from the home page;
 * - guest checkout, and the J2Commerce setup wizard marked as done.
 *
 * It can be run again: what it added before is replaced. MySQL only, development sites only.
 */

declare(strict_types=1);

$root = \dirname(__DIR__);
$site = $argv[1] ?? $root . '/joomla';

$configuration = $site . '/configuration.php';

if (!is_file($configuration)) {
    fwrite(STDERR, "No Joomla configuration at {$configuration}.\n");
    exit(1);
}

require_once $configuration;

// Joomla's configuration.php declares the class JConfig; its properties are the settings.
$configClass = 'JConfig';
$config      = array_map(
    static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
    get_object_vars(new $configClass())
);

[$host, $port] = array_pad(explode(':', $config['host'] ?: 'localhost', 2), 2, '3306');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($host, $config['user'], $config['password'], $config['db'], (int) $port);
$db->set_charset('utf8mb4');

$p   = $config['dbprefix'];
$now = gmdate('Y-m-d H:i:s');

function prepare(mysqli $db, string $sql): mysqli_stmt
{
    $statement = $db->prepare($sql);

    if ($statement === false) {
        throw new RuntimeException($db->error);
    }

    return $statement;
}

/**
 * @param list<scalar|null> $params
 *
 * @return list<array<string, mixed>>
 */
function rows(mysqli $db, string $sql, array $params = []): array
{
    $statement = prepare($db, $sql);
    $statement->execute($params);
    $result = $statement->get_result();

    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * @param list<scalar|null> $params
 */
function run(mysqli $db, string $sql, array $params = []): int
{
    $statement = prepare($db, $sql);
    $statement->execute($params);

    return (int) $db->insert_id;
}

// ── Product filters ────────────────────────────────────────────────────────

$groups = [
    'Colour'   => ['Black' => '#000000', 'White' => '#ffffff', 'Red' => '#c0392b', 'Blue' => '#2e6db4', 'Green' => '#2e8b57'],
    'Material' => ['Cotton' => '', 'Leather' => '', 'Steel' => '', 'Plastic' => '', 'Wood' => ''],
    'Use'      => ['Outdoor' => '', 'Home' => '', 'Office' => '', 'Sport' => '', 'Travel' => ''],
];

foreach (rows($db, "SELECT j2commerce_filtergroup_id AS id FROM {$p}j2commerce_filtergroups WHERE group_name IN ('Colour', 'Material', 'Use')") as $old) {
    run($db, "DELETE pf FROM {$p}j2commerce_product_filters pf JOIN {$p}j2commerce_filters f ON f.j2commerce_filter_id = pf.filter_id WHERE f.group_id = ?", [$old['id']]);
    run($db, "DELETE FROM {$p}j2commerce_filters WHERE group_id = ?", [$old['id']]);
    run($db, "DELETE FROM {$p}j2commerce_filtergroups WHERE j2commerce_filtergroup_id = ?", [$old['id']]);
}

$filterIds = [];
$ordering  = 1;

foreach ($groups as $group => $values) {
    $groupId = run(
        $db,
        "INSERT INTO {$p}j2commerce_filtergroups (group_name, filter_input_type, source, enabled, access, ordering) VALUES (?, 'checkbox', 'j2commerce', 1, 1, ?)",
        [$group, $ordering++]
    );

    $order = 1;

    foreach ($values as $name => $colour) {
        $filterIds[$group][] = run(
            $db,
            "INSERT INTO {$p}j2commerce_filters (group_id, filter_name, filter_color, ordering) VALUES (?, ?, ?, ?)",
            [$groupId, $name, $colour, $order++]
        );
    }
}

$products = array_column(rows($db, "SELECT j2commerce_product_id AS id FROM {$p}j2commerce_products ORDER BY j2commerce_product_id"), 'id');

foreach ($products as $i => $productId) {
    // A fixed pattern, so every run gives the same answers: one colour, one material,
    // and one or two uses per product.
    // The three indexes vary independently, so most combinations occur.
    $chosen = [
        $filterIds['Colour'][$i % 5],
        $filterIds['Material'][($i * 3 + intdiv($i, 5)) % 5],
        $filterIds['Use'][intdiv($i, 5) % 5],
    ];

    if ($i % 3 === 0) {
        $chosen[] = $filterIds['Use'][(intdiv($i, 5) + 2) % 5];
    }

    foreach ($chosen as $filterId) {
        run($db, "INSERT INTO {$p}j2commerce_product_filters (product_id, filter_id) VALUES (?, ?)", [$productId, $filterId]);
    }

    run($db, "UPDATE {$p}j2commerce_products SET productfilter_ids = ? WHERE j2commerce_product_id = ?", [implode(',', $chosen), $productId]);
}

printf("Filters: %d groups, %d products tagged\n", \count($groups), \count($products));

// ── Stock ──────────────────────────────────────────────────────────────────

// J2Commerce's sample data leaves every variant's availability flag at 0, "marked out of
// stock", so the shop sells nothing. Variants with stock become available; a few stay
// out of stock, so "in stock only" has something to leave out.
run(
    $db,
    "UPDATE {$p}j2commerce_variants v JOIN {$p}j2commerce_productquantities q ON q.variant_id = v.j2commerce_variant_id
     SET v.availability = IF(q.quantity > 0 AND v.product_id % 7 <> 0, 1, 0)"
);

echo "Stock: variants with stock are available, except those of every 7th product\n";

// ── Guest checkout, setup wizard done ──────────────────────────────────────

$component = rows($db, "SELECT extension_id, params FROM {$p}extensions WHERE type = 'component' AND element = 'com_j2commerce'")[0] ?? null;

if ($component === null) {
    fwrite(STDERR, "J2Commerce is not installed.\n");
    exit(1);
}

$params                         = json_decode((string) $component['params'], true) ?: [];
$params['allow_guest_checkout'] = '1';
$params['onboarding_complete']  = '1';
run($db, "UPDATE {$p}extensions SET params = ? WHERE extension_id = ?", [json_encode($params), $component['extension_id']]);

echo "J2Commerce: guest checkout on, setup wizard done\n";

// ── Blog category and articles ─────────────────────────────────────────────

$blog = rows($db, "SELECT id FROM {$p}categories WHERE extension = 'com_content' AND alias = 'blog'")[0]['id'] ?? null;

if ($blog === null) {
    $rootRow = rows($db, "SELECT rgt FROM {$p}categories WHERE id = 1")[0];
    $rgt     = (int) $rootRow['rgt'];
    run($db, "UPDATE {$p}categories SET rgt = rgt + 2 WHERE rgt >= ?", [$rgt]);
    run($db, "UPDATE {$p}categories SET lft = lft + 2 WHERE lft > ?", [$rgt]);
    $blog = run(
        $db,
        "INSERT INTO {$p}categories (asset_id, parent_id, lft, rgt, level, path, extension, title, alias, description, published, access, params, metadesc, metakey, metadata, created_user_id, created_time, modified_user_id, modified_time, language)
         VALUES (0, 1, ?, ?, 1, 'blog', 'com_content', 'Blog', 'blog', '', 1, 1, '{}', '', '', '{}', 0, ?, 0, ?, '*')",
        [$rgt, $rgt + 1, $now, $now]
    );
}

$articles = [
    ['Choosing a jacket for hiking in the rain', 'A good rain jacket keeps water out and lets sweat escape. Look for a waterproof rating of at least 10,000 mm, taped seams and a hood that moves with your head. Layer underneath: a thin base layer and a fleece for cold days.'],
    ['Caring for a cast iron skillet', 'Cast iron lasts a lifetime if you keep it seasoned. Rinse it with hot water, dry it on the stove and rub in a thin layer of oil. Avoid soaking it and never put it in the dishwasher.'],
    ['Five ways to tidy the cables under your desk', 'A cable management box hides the power strip, Velcro ties bundle the rest, and a tray under the desk keeps everything off the floor. Label the plugs, and leave a little slack for moving the monitor.'],
    ['How we pack and ship your order', 'Orders placed before 14:00 leave the same day. We pack in recycled cardboard, without plastic fillers. Standard shipping takes two to four working days; you get a tracking link by email.'],
    ['Returns and exchanges', 'You can return an unused product within 30 days. Fill in the form on the order page, pack the product in its original box and bring it to a drop-off point. We refund within five working days after we receive it.'],
    ['What the colours and materials in our shop mean', 'Each product lists its colour, material and use, such as outdoor, home, office, sport or travel. Use the filters on the shop page to combine them: for example red, cotton and travel.'],
];

$workflowStage = (int) (rows($db, "SELECT id FROM {$p}workflow_stages WHERE `default` = 1 ORDER BY id LIMIT 1")[0]['id'] ?? 1);

foreach ($articles as $i => [$title, $text]) {
    $alias = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

    foreach (rows($db, "SELECT id FROM {$p}content WHERE alias = ? AND catid = ?", [$alias, $blog]) as $old) {
        run($db, "DELETE FROM {$p}workflow_associations WHERE item_id = ? AND extension = 'com_content.article'", [$old['id']]);
        run($db, "DELETE FROM {$p}content_frontpage WHERE content_id = ?", [$old['id']]);
        run($db, "DELETE FROM {$p}content WHERE id = ?", [$old['id']]);
    }

    $published = gmdate('Y-m-d H:i:s', time() - (\count($articles) - $i) * 86400);
    $id        = run(
        $db,
        "INSERT INTO {$p}content (asset_id, title, alias, introtext, `fulltext`, state, catid, created, created_by, created_by_alias, modified, modified_by, publish_up, images, urls, attribs, version, ordering, metakey, metadesc, access, hits, metadata, featured, language, note)
         VALUES (0, ?, ?, ?, '', 1, ?, ?, 0, 'Shop team', ?, 0, ?, '{}', '{}', '{}', 1, 0, '', '', 1, 0, '{}', 1, '*', '')",
        [$title, $alias, '<p>' . $text . '</p>', $blog, $published, $published, $published]
    );

    run($db, "INSERT INTO {$p}workflow_associations (item_id, stage_id, extension) VALUES (?, ?, 'com_content.article')", [$id, $workflowStage]);
    run($db, "INSERT INTO {$p}content_frontpage (content_id, ordering, featured_up, featured_down) VALUES (?, ?, NULL, NULL)", [$id, $i]);
}

printf("Blog: %d articles, featured on the home page\n", \count($articles));

// ── Menu items ─────────────────────────────────────────────────────────────

$shopCategory = rows($db, "SELECT id FROM {$p}categories WHERE extension = 'com_content' AND alias = 'shop' AND parent_id = 1")[0]['id'] ?? null;
$contentId    = rows($db, "SELECT extension_id FROM {$p}extensions WHERE type = 'component' AND element = 'com_content'")[0]['extension_id'];

$items = [
    'shop' => [
        'Shop',
        'index.php?option=com_j2commerce&view=products&catid=' . (int) $shopCategory,
        $component['extension_id'],
        json_encode([
            'page_limit'                    => '12',
            'show_subcategory_content'      => '3',
            'list_show_filter'              => '1',
            'list_show_filter_price'        => '1',
            'list_show_product_filter'      => '1',
            'list_show_manufacturer_filter' => '1',
            'list_show_filter_search'       => '1',
            'list_show_filter_sorting'      => '1',
        ]),
    ],
    'blog' => ['Blog', 'index.php?option=com_content&view=category&layout=blog&id=' . (int) $blog, $contentId, '{}'],
];

foreach ($items as $alias => [$title, $link, $componentId, $menuParams]) {
    foreach (rows($db, "SELECT lft, rgt FROM {$p}menu WHERE menutype = 'mainmenu' AND alias = ? AND client_id = 0", [$alias]) as $old) {
        $width = (int) $old['rgt'] - (int) $old['lft'] + 1;
        run($db, "DELETE FROM {$p}menu WHERE lft BETWEEN ? AND ?", [$old['lft'], $old['rgt']]);
        run($db, "UPDATE {$p}menu SET rgt = rgt - ? WHERE rgt > ?", [$width, $old['rgt']]);
        run($db, "UPDATE {$p}menu SET lft = lft - ? WHERE lft > ?", [$width, $old['rgt']]);
    }

    $rgt = (int) rows($db, "SELECT rgt FROM {$p}menu WHERE id = 1")[0]['rgt'];
    run($db, "UPDATE {$p}menu SET rgt = rgt + 2 WHERE rgt >= ?", [$rgt]);
    run($db, "UPDATE {$p}menu SET lft = lft + 2 WHERE lft > ?", [$rgt]);
    run(
        $db,
        "INSERT INTO {$p}menu (menutype, title, alias, note, path, link, type, published, parent_id, level, component_id, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id)
         VALUES ('mainmenu', ?, ?, '', ?, ?, 'component', 1, 1, 1, ?, 0, 1, '', 0, ?, ?, ?, 0, '*', 0)",
        [$title, $alias, $alias, $link, $componentId, $menuParams, $rgt, $rgt + 1]
    );
}

echo "Menu: Shop and Blog in the main menu\n";
