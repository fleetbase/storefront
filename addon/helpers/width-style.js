import { helper } from '@ember/component/helper';
import { htmlSafe } from '@ember/template';

/**
 * A safe inline width for share bars: `{{width-style 42}}` → `width: 42%`.
 */
export default helper(function widthStyle([percent]) {
    const value = Math.max(0, Math.min(100, Number(percent) || 0));

    return htmlSafe(`width: ${value}%`);
});
