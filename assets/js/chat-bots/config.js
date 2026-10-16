/**
 * Window dependencies.
 */
const {
	restApiEndpoint,
	availableGates = [],
	executionModes = [],
	answering = { fields: {}, models: [], featureUrl: '', rerankSetUp: false },
	gateStages = {},
} = window.epChatBots || {};

export { restApiEndpoint, availableGates, executionModes, answering, gateStages };
