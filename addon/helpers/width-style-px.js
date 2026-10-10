import { helper } from '@ember/component/helper';
import { htmlSafe } from '@ember/template';

/**
 * A safe inline width from a column width such as `190px` or `12%`; nothing when unset.
 */
export default helper(function widthStylePx([width]) {
    if (!width) {
        return htmlSafe('');
    }

    const value = String(width).trim();

    return htmlSafe(/^\d+(\.\d+)?(px|%|rem|em)$/.test(value) ? `width: ${value}; flex: 0 0 ${value};` : '');
});
