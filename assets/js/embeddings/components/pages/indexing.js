/**
 * External dependencies
 */
import { RangeControl, Panel, PanelBody, PanelHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useVectorEmbeddingSettings } from '../../provider';

export default () => {
	const { currentSettings, setChunkSize, setChunkOverlap } = useVectorEmbeddingSettings();
	const { chunkSize, chunkOverlap } = currentSettings;
	return (
		<Panel>
			<PanelHeader>
				<h2>{__('Indexing', 'elasticpress-labs')}</h2>
			</PanelHeader>
			<PanelBody>
				<RangeControl
					label={__('Chunk Size (in words)', 'elasticpress-labs')}
					value={chunkSize}
					onChange={setChunkSize}
					min={1}
					max={300}
				/>
				<RangeControl
					label={__('Chunk Overlap (in words)', 'elasticpress-labs')}
					value={chunkOverlap}
					onChange={setChunkOverlap}
					min={1}
					max={100}
				/>
			</PanelBody>
		</Panel>
	);
};
