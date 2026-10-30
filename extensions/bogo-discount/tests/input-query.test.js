import { describe, it, expect } from 'vitest';
import fs from 'node:fs';

const constants = JSON.parse(fs.readFileSync(new URL('../../../shared/offer-constants.json', import.meta.url), 'utf8'));
const query = fs.readFileSync(new URL('../src/cart_lines_discounts_generate_run.graphql', import.meta.url), 'utf8');

describe('input query', () => {
  it('asks for the eligible tag from shared constants (no drift)', () => {
    expect(typeof constants.eligibleTag).toBe('string');
    expect(constants.eligibleTag.length).toBeGreaterThan(0);
    expect(query).toContain(`hasAnyTag(tags: ["${constants.eligibleTag}"])`);
  });
});
