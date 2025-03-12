const defaultEslintrc = require('10up-toolkit/config/.eslintrc');

module.exports = {
	extends: '@10up/eslint-config/wordpress',
	rules: {
		...defaultEslintrc.rules,
		'jsdoc/check-tag-names': [
			'error',
			{
				definedTags: ['filter', 'action'],
			},
		],
	},
	globals: {
		module: true,
		process: true,
		jQuery: true,
	},
};
