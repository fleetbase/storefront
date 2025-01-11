import { helper } from '@ember/component/helper';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import numbersOnly from '@fleetbase/ember-ui/utils/numbers-only';
import calculatePercentage from '@fleetbase/ember-core/utils/calculate-percentage';

export default helper(function getTipAmount([tip, subtotal, currency]) {
    let amount = tip;
    if (typeof tip === 'string' && tip.endsWith('%')) {
        amount = calculatePercentage(numbersOnly(tip), subtotal);
    }

    amount = parseInt(amount);
    amount = isNaN(amount) ? 0 : amount;

    return formatCurrency(amount, currency);
});
