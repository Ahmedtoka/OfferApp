import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import { parse } from 'graphql';

const constants = JSON.parse(fs.readFileSync(new URL('../../../shared/offer-constants.json', import.meta.url), 'utf8'));
const query = fs.readFileSync(new URL('../src/cart_lines_discounts_generate_run.graphql', import.meta.url), 'utf8');
const toml = fs.readFileSync(new URL('../shopify.extension.toml', import.meta.url), 'utf8');
const fixturesDir = new URL('./fixtures/', import.meta.url);

// The tag is a merchant setting: Shopify fills $eligibleTags from the JSON metafield
// $app:bogo/config on the automatic discount node (written by the Laravel admin).
describe('input query uses the eligibleTags variable', () => {
  const op = parse(query).definitions.find((d) => d.kind === 'OperationDefinition');

  it('declares $eligibleTags: [String!]!', () => {
    const v = op.variableDefinitions.find((d) => d.variable.name.value === 'eligibleTags');
    expect(v).toBeDefined();
    const t = v.type; // NonNull(List(NonNull(String)))
    expect(t.kind).toBe('NonNullType');
    expect(t.type.kind).toBe('ListType');
    expect(t.type.type.kind).toBe('NonNullType');
    expect(t.type.type.type.name.value).toBe('String');
    expect(op.variableDefinitions).toHaveLength(2);
  });

  it('declares $collectionIds: [ID!]!', () => {
    const v = op.variableDefinitions.find((d) => d.variable.name.value === 'collectionIds');
    expect(v).toBeDefined();
    const t = v.type; // NonNull(List(NonNull(ID)))
    expect(t.kind).toBe('NonNullType');
    expect(t.type.kind).toBe('ListType');
    expect(t.type.type.kind).toBe('NonNullType');
    expect(t.type.type.type.name.value).toBe('ID');
  });

  it('passes $collectionIds to inAnyCollection and asks for the language', () => {
    expect(query).toMatch(/inAnyCollection\(ids:\s*\$collectionIds\)/);
    expect(query).toMatch(/localization\s*\{\s*language\s*\{\s*isoCode\s*\}\s*\}/);
  });

  it('passes the variable to hasAnyTag, with no hard-coded tag literal', () => {
    expect(query).toMatch(/hasAnyTag\(tags:\s*\$eligibleTags\)/);
    expect(query).not.toContain(constants.eligibleTag);
    expect(query).not.toMatch(/hasAnyTag\(tags:\s*\[/);
  });
});

describe('shopify.extension.toml', () => {
  // Split on [[...]] / [...] headers so we can check the target block only.
  const blocks = toml.split(/^\s*(?=\[)/m);
  const target = blocks.find((b) => /^\[\[extensions\.targeting\]\]/.test(b) && b.includes('cart.lines.discounts.generate.run'));

  it('declares input_variables on the target: namespace $app:bogo, key config', () => {
    expect(target).toBeDefined();
    const m = target.match(/^\s*input_variables\s*=\s*\{([^}]*)\}/m);
    expect(m).not.toBeNull();
    expect(m[1]).toMatch(/namespace\s*=\s*"\$app:bogo"/);
    expect(m[1]).toMatch(/key\s*=\s*"config"/);
    expect(constants.metafieldNamespace).toBe('$app:bogo');
    expect(constants.metafieldDiscountKey).toBe('config');
  });

  it('has no extension-level [extensions.input] variables (declaring both fails deploy)', () => {
    expect(toml).not.toMatch(/^\s*\[extensions\.input/m);
    expect(toml).not.toMatch(/^\s*input\.variables/m);
  });
});

describe('message constants', () => {
  it('has localized gift and percent messages, English and Arabic', () => {
    expect(constants.giftMessages).toEqual({ en: 'Gift', ar: 'هدية' });
    expect(constants.percentMessageTemplates).toEqual({ en: '{pct}% off', ar: 'خصم {pct}%' });
  });
});

describe('fixtures carry the input variables', () => {
  const files = fs.readdirSync(fixturesDir).filter((f) => f.endsWith('.json'));
  const load = (f) => JSON.parse(fs.readFileSync(new URL(f, fixturesDir), 'utf8')).payload;

  it('default tag is a non-empty string', () => {
    expect(typeof constants.eligibleTag).toBe('string');
    expect(constants.eligibleTag.length).toBeGreaterThan(0);
  });

  it.each(files.filter((f) => !['custom_tag_name.json', 'collection_eligible.json'].includes(f)))('%s uses the default eligibleTags', (f) => {
    expect(load(f).inputVariables).toEqual({ eligibleTags: [constants.eligibleTag], collectionIds: [] });
  });

  it('collection_eligible carries the collection id', () => {
    expect(load('collection_eligible.json').inputVariables).toEqual({
      eligibleTags: [constants.eligibleTag],
      collectionIds: ['gid://shopify/Collection/1'],
    });
  });

  it('custom_tag_name uses WinterOffer', () => {
    expect(load('custom_tag_name.json').inputVariables).toEqual({ eligibleTags: ['WinterOffer'], collectionIds: [] });
  });
});
