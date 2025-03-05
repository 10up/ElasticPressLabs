/**
 * WordPress dependencies
 */
import { Button, Flex, TabPanel } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useVectorEmebeddingSettings } from '../provider';
import { useSettingsScreen } from '../../settings-screen';
import PostType from '../components/pages/post-type';
import Indexing from '../components/pages/indexing';

export default () => {
	const { currentVectorEmbeddingsConfiguration, save } = useVectorEmebeddingSettings();
	const { postTypeConfig: postTypes } = currentVectorEmbeddingsConfiguration;
	const { createNotice } = useSettingsScreen();
	const [currentTab, setCurrentTab] = useState(0); // eslint-disable-line
	const is2columns = window.innerWidth > 782;

	const tabs = postTypes.map((postType) => ({
		title: `Type: ${postType.label}`,
		name: postType.key,
		postType,
	}));

	tabs.push({
		title: __('Indexing', 'elasticpress'),
		name: 'indexing',
		postType: {},
		Component: Indexing,
	});

	/**
	 * Submit event.
	 *
	 * @param {Event} event Submit event.
	 */
	const onSubmit = async (event) => {
		event.preventDefault();

		try {
			await save();
			createNotice('success', __('Settings saved.', 'elasticpress'));
		} catch (e) {
			createNotice('error', __('Something went wrong. Please try again.', 'elasticpress'));
		}
	};

	return (
		<form className="ep-vector-embedding-settings__post-types-list">
			<TabPanel
				className="ep-vector-embedding-settings__tabs"
				activeClass="ep-vector-embedding-settings__tabs__tab--active"
				onSelect={(val) => setCurrentTab(val)}
				initialTabName={postTypes[0].key}
				orientation={is2columns ? 'vertical' : 'horizontal'}
				tabs={tabs}
			>
				{({ postType, Component }) => {
					if (Component) {
						return <Component />;
					}

					return <PostType key={postType.key} postType={postType} />;
				}}
			</TabPanel>

			<Flex justify="flex-end">
				<Button
					className="ep-vector-embeddings-panel__save"
					variant="primary"
					onClick={onSubmit}
				>
					Save
				</Button>
			</Flex>
		</form>
	);
};
