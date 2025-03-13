/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import MetaSelect from '../fields/meta-select';
import Group from '../layout/group';
import { useVectorEmebeddingSettings } from '../../provider';

export default ({ postType, embeddingMode }) => {
	const { fieldsIndexingInclude, fieldsIndexingExclude, enablefieldsIndexing, key } = postType;
	const { setEmbeddingForPostType } = useVectorEmebeddingSettings();
	return (
		<>
			<h4>{__('Post Meta', 'elasticpresslabs')}</h4>
			<CheckboxControl
				label={__('Post Meta Fields')}
				checked={enablefieldsIndexing}
				onChange={() => {
					setEmbeddingForPostType(
						key,
						null,
						'enablefieldsIndexing',
						!enablefieldsIndexing,
					);
				}}
			/>
			{embeddingMode === 'automatic' && enablefieldsIndexing && (
				<>
					<Group indent>
						<MetaSelect
							postType={postType}
							label={__(
								'Include posts that have any of these meta keys',
								'elasticpresslabs',
							)}
							updateKey="fieldsIndexingInclude"
							value={fieldsIndexingInclude}
						/>
					</Group>
					<Group indent>
						<MetaSelect
							postType={postType}
							updateKey="fieldsIndexingExclude"
							label={__(
								'Exclude posts that have any of these meta keys',
								'elasticpresslabs',
							)}
							value={fieldsIndexingExclude}
						/>
					</Group>
				</>
			)}
		</>
	);
};
