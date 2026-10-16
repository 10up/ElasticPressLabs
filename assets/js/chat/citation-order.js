/**
 * Display numbers of the sources an answer cites, in the order the answer first cites them.
 *
 * Markers in the answer text are `[[k]]`, one per fact; each provenance entry binds `k` to a
 * source (`n`, the passage number). The first source the answer cites is shown as 1, the next
 * new one as 2, and so on, in the answer and in the Sources panel.
 *
 * @param {object|undefined} message Assistant message: `content`, `provenance`.
 * @returns {Map<number, number>} Display number by passage number (only cited sources).
 */
export const getSourceOrder = (message) => {
	const entries = new Map(
		(Array.isArray(message?.provenance) ? message.provenance : []).map((entry) => [
			Number(entry.k),
			Number(entry.n),
		]),
	);
	const order = new Map();

	String(message?.content || '').replace(/\[\[(\d+)\]\]/g, (marker, k) => {
		const source = entries.get(Number(k));

		if (source && !order.has(source)) {
			order.set(source, order.size + 1);
		}

		return marker;
	});

	return order;
};
