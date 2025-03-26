/**
 * WordPress dependencies
 */
import { __experimentalSpacer as Spacer, RadioControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Group from '../layout/group';
import { useVectorEmebeddingSettings } from '../../provider';

export default ({ postType }) => {
	const { embeddingMode, key } = postType;
	const { setEmbeddingForPostType } = useVectorEmebeddingSettings();

	const options = [
		{ label: __('Automatic', 'elasticpress'), value: 'automatic' },
		{ label: __('Manual', 'elasticpress'), value: 'manual' },
	];

	return (
		<Spacer marginBottom={6}>
			<p>
				{__(
					'This setting controls which posts will be indexed with vector embedding data.',
					'elasticpress',
				)}
			</p>
			<h4>{__('Modes', 'elasticpress')}</h4>
			<ul style={{ paddingLeft: '20px', listStyle: 'disc' }}>
				<li>
					{__(
						'Automatic (Default): Posts are indexed based on taxonomy terms and post meta. Configure rules to include or exclude posts automatically.',
						'elasticpress',
					)}
				</li>
				<li>
					{__(
						'Manual: Editors will manually select which posts will qualify for vector embedding.',
						'elasticpress',
					)}
				</li>
			</ul>
			<p>
				{__(
					'Choose the mode that best fits your needs. If unsure, the automatic mode ensures consistent indexing based on predefined rules',
					'elasticpress',
				)}
			</p>
			<Group>
				<RadioControl
					label="Embedding Mode"
					help={
						embeddingMode === 'automatic'
							? __(
									'Posts be will indexed according to the configuration below.',
									'elasticpress',
								)
							: __(
									'Users will manually select which posts will qualify for vector embedding',
									'elasticpress',
								)
					}
					selected={embeddingMode}
					options={options}
					onChange={(value) => {
						setEmbeddingForPostType(key, null, 'embeddingMode', value);
					}}
				/>
			</Group>
		</Spacer>
	);
};
