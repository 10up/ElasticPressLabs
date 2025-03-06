/**
 * WordPress dependencies
 */
import { CheckboxControl, Panel, PanelBody, PanelHeader, PanelRow } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useVectorEmebeddingSettings } from '../../provider';
import TaxonomyInclusion from '../fieldsets/taxonomy-inclusion';
import MetaInclusion from '../fieldsets/meta-inclusion';
import EmbeddedFields from '../fieldsets/embedded-fields';
import EmbeddingMode from '../fieldsets/embedding-mode';

export default ({ postType }) => {
	const { setEmbeddingForPostType } = useVectorEmebeddingSettings();
	const {
		embeddable,
		fieldsIndexingInclude,
		fieldsIndexingExclude,
		key,
		label,
		taxonomies,
		embeddingMode,
	} = postType;

	return (
		<Panel key={label} className="ep-vector-embeddings-panel">
			<PanelHeader>
				<h2>{label}</h2>
			</PanelHeader>
			<PanelBody initialOpen>
				<PanelRow>
					<CheckboxControl
						label={__('Allow Vector Embedding', 'elasticpress')}
						help={__(
							'Enable or disable vector embeddings for this post type.',
							'elasticpress',
						)}
						checked={embeddable}
						onChange={() =>
							setEmbeddingForPostType(key, null, 'embeddable', !embeddable)
						}
					/>
				</PanelRow>
			</PanelBody>
			{embeddable && (
				<PanelBody initialOpen={false} title={__('Indexing Criteria', 'elasticpress')}>
					<EmbeddingMode {...{ postType }} />
					{embeddingMode === 'automatic' && (
						<>
							<TaxonomyInclusion {...{ taxonomies, postType }} />
							<MetaInclusion
								{...{
									postType,
									fieldsIndexingExclude,
									fieldsIndexingInclude,
									embeddingMode,
								}}
							/>
						</>
					)}
				</PanelBody>
			)}
			{embeddable && (
				<PanelBody initialOpen={false} title={__('Content Fields', 'elasticpress')}>
					<EmbeddedFields postType={postType} />
				</PanelBody>
			)}
		</Panel>
	);
};
