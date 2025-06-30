/**
 * External dependencies
 */
import { RangeControl, Panel, PanelBody, PanelHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useVectorEmebeddingSettings } from '../../provider';

export default () => {
	const { currentSettings, setChunkSize, setChunkOverlap } = useVectorEmebeddingSettings();
	const { chunkSize, chunkOverlap } = currentSettings;
	return (
		<Panel>
			<PanelHeader>
				<h2>{__('Indexing', 'elasticpress-labs')}</h2>
			</PanelHeader>
			<PanelBody>
				<RangeControl
					label="Chunk Size (in words)"
					value={chunkSize}
					onChange={setChunkSize}
					min={1}
					max={300}
				/>
				<RangeControl
					label="Chunk Overlap (in words)"
					value={chunkOverlap}
					onChange={setChunkOverlap}
					min={1}
					max={100}
				/>
			</PanelBody>
		</Panel>
	);
};
