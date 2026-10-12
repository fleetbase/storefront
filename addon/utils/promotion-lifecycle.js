/**
 * Where a promotion is in its life, the way the server's availability reads it: any promotion
 * past its end has ended, an active one before its start is scheduled, otherwise its status.
 *
 * @param {Object} promotion a promotion record or payload
 * @param {Date} now the moment to read it at
 * @return {String} draft, scheduled, active, paused or ended
 */
export default function promotionLifecycle(promotion, now = new Date()) {
    if (!promotion) {
        return 'draft';
    }

    const status = promotion.status ?? 'draft';
    const endsAt = promotion.ends_at ? new Date(promotion.ends_at) : null;
    const startsAt = promotion.starts_at ? new Date(promotion.starts_at) : null;

    if (status === 'ended' || (endsAt && endsAt <= now)) {
        return 'ended';
    }

    if (status === 'active' && startsAt && startsAt > now) {
        return 'scheduled';
    }

    return status;
}
