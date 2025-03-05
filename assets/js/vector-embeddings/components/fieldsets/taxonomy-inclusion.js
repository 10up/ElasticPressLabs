/**
 * External dependencies
 * */
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useVectorEmebeddingSettings } from '../../provider';
import TermSelect from '../fields/term-select';
import Group from '../layout/group';

export default ({ taxonomies, postType }) => {
	const { setEmbeddingForPostType } = useVectorEmebeddingSettings();
	const hasTaxonomies = Object.keys(taxonomies).length > 0;
	const { key } = postType;

	return (
		<div>
			{hasTaxonomies > 0 ? (
				Object.keys(taxonomies).map((taxonomy) => {
					const { label, termsInclude, termsExclude, enabled } = taxonomies[taxonomy];
					return (
						<>
							<CheckboxControl
								label={label}
								checked={enabled}
								onChange={() => {
									setEmbeddingForPostType(key, taxonomy, 'enabled', !enabled);
								}}
							/>
							{enabled && (
								<>
									<Group indent>
										<TermSelect
											postType={postType}
											taxonomy={taxonomy}
											label={__(
												'Include posts that have any of these terms',
												'elasticpress',
											)}
											updateKey="termsInclude"
											value={termsInclude}
										/>
									</Group>
									<Group indent>
										<TermSelect
											postType={postType}
											taxonomy={taxonomy}
											label={__(
												'Exclude posts that have any of these terms',
												'elasticpress',
											)}
											updateKey="termsExclude"
											value={termsExclude}
										/>
									</Group>
								</>
							)}
						</>
					);
				})
			) : (
				<p>
					{__('No public taxonomies are registered to this post type.', 'elasticpress')}
				</p>
			)}
		</div>
	);
};
