import { RangeControl, Panel, PanelBody, PanelHeader } from '@wordpress/components';

export default () => {
	return (
		<Panel>
			<PanelHeader>
				<h2>General</h2>
			</PanelHeader>
			<PanelBody>
				<RangeControl label="Embedding Dimension" value={10} min={1} max={100} />
				<RangeControl label="Embedding Epochs" value={50} min={1} max={100} />
			</PanelBody>
		</Panel>
	);
};
