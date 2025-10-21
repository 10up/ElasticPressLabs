/**
 * External dependencies
 */
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */

import MetaSelect from '../fields/meta-select';
import Group from '../layout/group';
import { useVectorEmebeddingSettings } from '../../provider';

export default ({ postType }) => {
	const { setEmbeddingForPostType } = useVectorEmebeddingSettings();
	const { fieldsEmbedding, key } = postType;

	const coreFields = [
		{
			label: __('Post Title', 'elasticpress-labs'),
			value: 'post_title',
		},
		{
			label: __('Post Content', 'elasticpress-labs'),
			value: 'post_content',
		},
		{
			label: __('Post Excerpt', 'elasticpress-labs'),
			value: 'post_excerpt',
		},
	];

	return (
		<>
			<p>
				{__(
					'This setting controls which fields will be used to create embedded data.',
					'elasticpress-labs',
				)}
			</p>
			<p>
				{__(
					'Select from the post fields below. Additional meta keys can be added to the input below.',
					'elasticpress-labs',
				)}
			</p>
			<Group>
				{coreFields.map((field) => {
					const { label, value } = field;
					return (
						<CheckboxControl
							key={value}
							label={label}
							checked={fieldsEmbedding.includes(value)}
							onChange={() => {
								setEmbeddingForPostType(
									key,
									null,
									'fieldsEmbedding',
									fieldsEmbedding.includes(value)
										? fieldsEmbedding.filter((f) => f !== value)
										: [...fieldsEmbedding, value],
								);
							}}
						/>
					);
				})}
			</Group>
			<Group>
				<MetaSelect
					postType={postType}
					label={__('Add Custom Fields', 'elasticpress-labs')}
					value={fieldsEmbedding}
					updateKey="fieldsEmbedding"
				/>
			</Group>
		</>
	);
};
