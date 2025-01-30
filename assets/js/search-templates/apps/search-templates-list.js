/**
 * WordPress Dependencies.
 */
import { createInterpolateElement, WPElement } from '@wordpress/element';
import { Panel, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { useSearchTemplate } from '../provider';
import TemplateRow from '../components/template-row';
import NewTemplateRow from '../components/new-template-row';
import { endpointExample, searchApiDocUrl } from '../config';

/**
 * Search Templates app.
 *
 * @returns {WPElement} App element.
 */
export default () => {
	const { isLoading, templates } = useSearchTemplate();

	return (
		<>
			<p>
				{createInterpolateElement(
					__(
						'Search templates are Elasticsearch queries stored in ElasticPress.io servers used by the <a>Search API</a>. Please note that all the API fields are still available for custom search templates. Your templates do not to differ in post types, offset, pagination arguments, or even filters, as for those you can still use query parameters. The templates can be used for searching in different fields or applying different scores, for instance.',
						'elasticpress-labs',
					),
					{ a: <a href={searchApiDocUrl} /> }, // eslint-disable-line jsx-a11y/anchor-has-content, jsx-a11y/control-has-associated-label
				)}
			</p>
			<p>
				{createInterpolateElement(
					sprintf(
						__(
							'Once you have a search template saved, you can start sending requests to your endpoint URL below. Your template needs to have <code>{{ep_placeholder}}</code> in all places where the search term needs to be used.',
							'elasticpress-labs',
						),
						endpointExample,
					),
					{ code: <code /> },
				)}
			</p>
			<p>
				{createInterpolateElement(
					sprintf(
						__('<strong>Endpoint URL:</strong> <code>%s</code>', 'elasticpress-labs'),
						endpointExample,
					),
					{ strong: <strong />, code: <code /> },
				)}
			</p>
			<Panel className="ep-search-template-panel">
				{isLoading ? (
					<div style={{ padding: '20px', textAlign: 'center' }}>
						{__('Loading...', 'elasticpress-labs')}
						<Spinner />
					</div>
				) : (
					<>
						{Object.keys(templates).map((templateName) => (
							<TemplateRow key={templateName} templateName={templateName} />
						))}
						<NewTemplateRow />
					</>
				)}
			</Panel>
		</>
	);
};
