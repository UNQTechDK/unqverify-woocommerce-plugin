const { execFileSync } = require('child_process');
const path = require('path');

const root = path.resolve(__dirname, '../../..');

function seededFixtures() {
  const output = execFileSync(path.join(root, 'node_modules/.bin/wp-env'), [
    'run', 'cli', '--', 'wp', 'eval',
    'echo "SEEDED_FIXTURES=" . wp_json_encode(array(' +
      '"parent" => wc_get_product_id_by_sku("UNQ-AGEV-CONFIGURABLE-ITEM"),' +
      '"restricted" => wc_get_product_id_by_sku("UNQ-AGEV-ITEM-L-AGE-RESTRICTED"),' +
      '"unrestricted" => wc_get_product_id_by_sku("UNQ-AGEV-ITEM-L-UNRESTRICTED"),' +
      '"ordinary" => wc_get_product_id_by_sku("UNQ-AGEV-ORDINARY"),' +
      '"cartUrl" => wc_get_cart_url())) . PHP_EOL;',
  ], { cwd: root, encoding: 'utf8', timeout: 60000 });
  const match = output.match(/SEEDED_FIXTURES=(.+)/);
  if (!match) throw new Error('Could not read seeded WooCommerce fixtures.');
  const fixtures = JSON.parse(match[1]);
  for (const name of ['parent', 'restricted', 'unrestricted', 'ordinary']) {
    if (!fixtures[name]) throw new Error(`Missing ${name} product; run pnpm seed:products.`);
  }
  return fixtures;
}

module.exports = { seededFixtures };
