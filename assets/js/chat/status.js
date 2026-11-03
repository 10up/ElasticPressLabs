/**
 * Statuses of a question that has not finished.
 */
export const ACTIVE_STATUSES = ['queued', 'running'];

/**
 * Card variants of an assistant turn; any other status is a plain answer.
 */
const CARD_STATUSES = ['refused', 'clarify', 'error', 'loading'];

/**
 * Card variant shown for an assistant turn.
 *
 * @param {string} status Stored or REST status.
 * @returns {string|undefined}
 */
export const toCardStatus = (status) => (CARD_STATUSES.includes(status) ? status : undefined);

/**
 * Whether a card status has no sources to cite (declined, clarify, or failed).
 *
 * @param {string|undefined} status Card status.
 * @returns {boolean}
 */
export const hasNoSources = (status) => ['refused', 'clarify', 'error'].includes(status);
