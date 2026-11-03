/**
 * Window dependencies.
 */
const {
	botsAdminUrl,
	botsEndpoint,
	chatEndpoint,
	historyUrl,
	recentChats = [],
	recentChatsLimit = 10,
	askUrl = '',
	todayLabel = '',
	initialChatId,
	requestTimeoutMs,
	executionModes = [],
	searchAlgorithms = {},
	technicalDetails = false,
	aiMethods = [],
} = window.epChat || {};

export {
	botsAdminUrl,
	botsEndpoint,
	chatEndpoint,
	historyUrl,
	recentChats,
	recentChatsLimit,
	askUrl,
	todayLabel,
	initialChatId,
	requestTimeoutMs,
	executionModes,
	searchAlgorithms,
	technicalDetails,
	aiMethods,
};
