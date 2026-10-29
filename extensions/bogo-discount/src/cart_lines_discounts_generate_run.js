// SPIKE (Task 1): first line only — one gift unit + one 30% unit on the same cart line.
export function cartLinesDiscountsGenerateRun(input) {
  const line = input.cart.lines[0];
  if (!line || !input.discount.discountClasses.includes('PRODUCT')) return { operations: [] };
  const unit = Math.round(parseFloat(line.cost.amountPerQuantity.amount) * 100);
  const fmt = (p) => (p / 100).toFixed(2);
  return {
    operations: [{
      productDiscountsAdd: {
        selectionStrategy: 'ALL',
        candidates: [
          { message: 'هدية', targets: [{ cartLine: { id: line.id, quantity: 1 } }], value: { fixedAmount: { amount: fmt(unit) } } },
          { message: 'خصم 30%', targets: [{ cartLine: { id: line.id, quantity: 1 } }], value: { fixedAmount: { amount: fmt(Math.round(unit * 0.3)) } } },
        ],
      },
    }],
  };
}
