import { test as setup } from '@playwright/test';
import { setDefaultFeatureSettings, wpCli } from 'elasticpress-playwright-utils';

setup('Setup global variables', async () => {
	const wpCliRespObj = await setDefaultFeatureSettings();

	process.env.EP_INDEX_NAMES = wpCliRespObj.indexNames;
	process.env.EP_IS_EPIO = wpCliRespObj.isEpIo === 1 ? '1' : '0';
	process.env.WP_VERSION = wpCliRespObj.wpVersion;

	process.env.EP_INDEX_TIMEOUT = '30000';
	process.env.ES_VERSION = await wpCli(
		'eval "echo ElasticPress\\Elasticsearch::factory()->get_elasticsearch_version();"',
	);
});
