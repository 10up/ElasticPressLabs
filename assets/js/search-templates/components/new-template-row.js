/**
 * WordPress Dependencies.
 */
import { Button, Flex, PanelBody, PanelRow, TextControl } from '@wordpress/components';
import { useState, WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useSearchTemplateDispatch } from '../provider';
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
					__('Could not save the template. Please try again.', 'elasticpress-labs'),
				);
				// eslint-disable-next-line no-console
				console.error(__('ElasticPress Labs Error: ', 'elasticpress-labs'), error);
			});
	};

	return (
		<PanelBody title={__('Add New Template', 'elasticpress-labs')} initialOpen>
			<PanelRow>
				<Flex direction="column" style={{ width: '100%' }}>
					<TextControl
						label={__('Name', 'elasticpress-labs')}
						help={__(
							'Template names are not editable. Double-check your template name before saving it.',
							'elasticpress-labs',
						)}
						value={name}
						onChange={setName}
					/>
					<TemplateField value={template} onChange={setTemplate} />
					<Flex justify="flex-start">
						<Button
							disabled={false}
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
