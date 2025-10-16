// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { Page } from '@playwright/test';

export const setVectorEmbeddingsSettings = async (loggedInPage: Page) => {
	await loggedInPage.getByRole('button', { name: 'AI', exact: true }).click();
	await loggedInPage.getByRole('button', { name: 'Vector Embeddings' }).click();
	await loggedInPage.getByRole('checkbox', { name: 'Enable' }).check();
	await loggedInPage
		.getByLabel('OpenAI API Key')
		.fill(process.env.VECTOR_EMBEDDINGS_API_KEY || '');
	await loggedInPage
		.getByLabel('OpenAI Embeddings API Url')
		.fill(process.env.VECTOR_EMBEDDINGS_API_URL || '');
	await loggedInPage
		.getByLabel('The name of the embedding model to use')
		.fill(process.env.VECTOR_EMBEDDINGS_MODEL || '');
};
