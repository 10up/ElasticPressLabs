/**
 * WordPress dependencies
 */
import {
	CheckboxControl,
	Panel,
	PanelBody,
	PanelHeader,
	PanelRow,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useVectorEmebeddingSettings } from '../../provider';
import TaxonomyInclusion from '../fieldsets/taxonomy-inclusion';
import MetaInclusion from '../fieldsets/meta-inclusion';
import EmbeddedFields from '../fieldsets/embedded-fields';
import Group from '../layout/group';

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
				<>
					<PanelBody initialOpen={false} title={__('Indexing Criteria')}>
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
							<ToggleGroupControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								isBlock
								style={{ maxWidth: '300px' }}
								label="Embedding Mode"
								help={
									embeddingMode === 'automatic'
										? __(
												'Posts be will indexed according to the confiruation below.',
												'elasticpress',
											)
										: __(
												'Editors will manually select which posts will qualify for vector embedding',
												'elasticpress',
											)
								}
								value={embeddingMode}
								onChange={(value) => {
									setEmbeddingForPostType(key, null, 'embeddingMode', value);
								}}
							>
								<ToggleGroupControlOption
									label={__('Automatic', 'elasticpress')}
									value="automatic"
								/>
								<ToggleGroupControlOption
									label={__('Manual', 'elasticpress')}
									value="manual"
								/>
							</ToggleGroupControl>
						</Group>

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
					<PanelBody initialOpen={false} title={__('Content Fields', 'elasticpress')}>
						<EmbeddedFields postType={postType} />
					</PanelBody>
				</>
			)}
		</Panel>
	);
};
