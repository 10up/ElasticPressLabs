/**
 * WordPress Dependencies.
 */
import { Button, Flex, Notice, PanelBody, PanelRow, TextControl } from '@wordpress/components';
import { useEffect, useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useSearchTemplate, useSearchTemplateDispatch } from '../provider';
import { useSettingsScreen } from '../../settings-screen';
import TemplateField from './template-field';

/**
 * New Template Row component.
 *
 * @returns {WPElement}
 */
export default () => {
	const [name, setName] = useState('');
	const [template, setTemplate] = useState('');
	const [disabled, setDisabled] = useState(true);

	const { templates } = useSearchTemplate();
	const { saveTemplate } = useSearchTemplateDispatch();
	const { createNotice } = useSettingsScreen();

	const onAddNewTemplate = () => {
		saveTemplate(name, template)
			.then(() => {
				setName('');
				setTemplate('');
				createNotice('success', __('Template saved.', 'elasticpress-labs'));
			})
			.catch((error) => {
				createNotice(
					'error',
					error.message ||
						__('Could not save the template. Please try again.', 'elasticpress-labs'),
				);
				// eslint-disable-next-line no-console
				console.error(__('ElasticPress Labs Error: ', 'elasticpress-labs'), error);
			});
	};

	const onChangeName = (newName) => {
		const sanitizedName = newName.toLowerCase().replace(/[^a-z0-9_-]/gi, '_');
		setName(sanitizedName);
	};

	const updateSaveButtonState = () => {
		setDisabled(name === '' || Object.keys(templates).includes(name) || template === '');
	};

	useEffect(updateSaveButtonState, [name, templates, template]);

	return (
		<PanelBody title={__('Add New Template', 'elasticpress-labs')} initialOpen>
			<PanelRow>
				<Flex direction="column" style={{ width: '100%' }}>
					{name && Object.keys(templates).includes(name) && (
						<Notice status="error" isDismissible={false}>
							{__(
								'This name is already in use. You can change the existing template instead.',
								'elasticpress-labs',
							)}
						</Notice>
					)}
					<TextControl
						label={__('Name', 'elasticpress-labs')}
						help={__(
							'Template names are not editable and only accept lowercase letters, numbers, -, and _. Double-check your template name before saving it.',
							'elasticpress-labs',
						)}
						value={name}
						onChange={onChangeName}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TemplateField value={template} onChange={setTemplate} />
					<Flex justify="flex-start">
						<Button
							disabled={disabled}
							isBusy={false}
							onClick={onAddNewTemplate}
							type="button"
							variant="primary"
						>
							{__('Save Template', 'elasticpress-labs')}
						</Button>
					</Flex>
				</Flex>
			</PanelRow>
		</PanelBody>
	);
};
