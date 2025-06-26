/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { useBlockProps, BlockControls } from '@wordpress/block-editor';
import { SelectControl, Placeholder, ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { edit, seen } from '@wordpress/icons';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Edit component.
 *
 * @returns {Function} Component.
 */
export default ({ attributes, setAttributes }) => {
	const { type } = attributes;
	const [isEditing, setIsEditing] = useState(true);

	const blockProps = useBlockProps({
		className: 'ep-ai-bot',
	});

	return (
		<div {...blockProps}>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon={isEditing ? seen : edit}
						label={
							isEditing
								? __('Preview', 'elasticpress-labs')
								: __('Edit', 'elasticpress-labs')
						}
						onClick={() => setIsEditing((prev) => !prev)}
						isPressed={isEditing}
					/>
				</ToolbarGroup>
			</BlockControls>
			{isEditing ? (
				<Placeholder
					label={__('AI Bot Setup', 'c2c')}
					instructions={__(
						"Select an AI Bot you've published, and pair it with an ElasticPress AI-enabled feature. The feature you select will display on the frontend, and will use the settings from the chosen AI Bot.",
						'elasticpress-labs',
					)}
				>
					<SelectControl
						label={__('AI Bot Selection', 'c2c')}
						value={type}
						onChange={(newType) => setAttributes({ type: newType })}
						options={[
							{ label: __('Select an Option', 'c2c'), value: '' },
							{ label: 'ZIP', value: 'zip' },
							{ label: 'CSV', value: 'csv' },
							{ label: 'XLSX', value: 'xlsx' },
							{ label: __('Documentation', 'c2c'), value: 'documentation' },
						]}
					/>
					<SelectControl
						label={__('AI Feature Selection', 'c2c')}
						value={type}
						onChange={(newType) => setAttributes({ type: newType })}
						options={[
							{ label: __('Select an Option', 'c2c'), value: '' },
							{ label: 'ZIP', value: 'zip' },
							{ label: 'CSV', value: 'csv' },
							{ label: 'XLSX', value: 'xlsx' },
							{ label: __('Documentation', 'c2c'), value: 'documentation' },
						]}
					/>
				</Placeholder>
			) : (
				<ServerSideRender block="elasticpress-labs/ai-bot" attributes={attributes} />
			)}
		</div>
	);
};
