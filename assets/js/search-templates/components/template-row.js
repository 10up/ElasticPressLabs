/**
 * WordPress Dependencies.
 */
import { Button, Flex, PanelBody, PanelRow, TextControl } from '@wordpress/components';
import { useState, WPElement } from '@wordpress/element';
import { cautionFilled, cloudDownload, cloudUpload } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useSearchTemplate, useSearchTemplateDispatch } from '../provider';
import { useSettingsScreen } from '../../settings-screen';
import TemplateField from './template-field';

/**
 * Template Row component.
 *
 * @param {object} props Component props.
 * @param {string} props.templateName The search template name.
 * @returns {WPElement}
 */
export default ({ templateName }) => {
	const { templates } = useSearchTemplate();
	const { loadTemplate, deleteTemplate, saveTemplate } = useSearchTemplateDispatch();
	const { createNotice } = useSettingsScreen();

	const [template, setTemplate] = useState(
		templates[templateName]
			? JSON.stringify(JSON.parse(templates[templateName]), null, '\t')
			: '',
	);
	const [isSaving, setIsSaving] = useState(false);
	const [icon, setIcon] = useState(null);

	const onTogglePanelBody = (opening) => {
		if (!opening) {
			return;
		}

		if (templates[templateName] === null) {
			setIcon(cloudDownload);
			loadTemplate(templateName)
				.then((response) => {
					setTemplate(JSON.stringify(response, null, '\t'));
				})
				.catch((error) => {
					createNotice(
						'error',
						__('Could not load your templates.', 'elasticpress-labs'),
					);
					// eslint-disable-next-line no-console
					console.error(__('ElasticPress Labs Error: ', 'elasticpress-labs'), error);
				})
				.finally(() => {
					setIcon(null);
				});
		}
	};

	const onValueChange = (newValue) => {
		setTemplate(newValue);

		if (newValue !== JSON.stringify(JSON.parse(templates[templateName]), null, '\t')) {
			setIcon(cautionFilled);
		}
	};

	const onSaveTemplate = () => {
		setIcon(cloudUpload);
		setIsSaving(true);
		saveTemplate(templateName, template)
			.then((response) => {
				setTemplate(JSON.stringify(response, null, '\t'));
				setIcon(null);
				createNotice('success', __('Template saved.', 'elasticpress-labs'));
			})
			.catch((error) => {
				createNotice(
					'error',
					__('Could not save the template. Please try again.', 'elasticpress-labs'),
				);
				// eslint-disable-next-line no-console
				console.error(__('ElasticPress Labs Error: ', 'elasticpress-labs'), error);
			})
			.finally(() => {
				setIcon(null);
				setIsSaving(false);
			});
	};

	const onDeleteTemplate = () => {
		deleteTemplate(templateName)
			.then(() => {
				createNotice('success', __('Template deleted.', 'elasticpress-labs'));
			})
			.catch((error) => {
				createNotice(
					'error',
					__('Could not delete the template. Please try again.', 'elasticpress-labs'),
				);
				// eslint-disable-next-line no-console
				console.error(__('ElasticPress Labs Error: ', 'elasticpress-labs'), error);
			});
	};

	return (
		<PanelBody
			title={templateName}
			icon={icon}
			key={templateName}
			initialOpen={false}
			onToggle={onTogglePanelBody}
		>
			<PanelRow>
				<Flex direction="column" style={{ width: '100%' }}>
					<TextControl
						label={__('Name', 'elasticpress-labs')}
						help={__(
							'Template names are not editable. If you need a different name, delete the template and recreate it.',
							'elasticpress-labs',
						)}
						value={templateName}
						disabled
					/>
					<TemplateField
						value={template}
						onChange={onValueChange}
						disabled={isSaving || templates[template] === null}
					/>
					<Flex justify="flex-start">
						<Button
							disabled={false}
							isBusy={false}
							onClick={onSaveTemplate}
							type="button"
							variant="primary"
						>
							{__('Save changes', 'elasticpress-labs')}
						</Button>
						<Button
							disabled={false}
							isBusy={false}
							onClick={onDeleteTemplate}
							type="button"
							variant="secondary"
							isDestructive
						>
							{__('Delete template', 'elasticpress-labs')}
						</Button>
					</Flex>
				</Flex>
			</PanelRow>
		</PanelBody>
	);
};
