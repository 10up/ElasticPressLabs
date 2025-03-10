/**
 * WordPress dependencies.
 */
import { CheckboxControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginPostStatusInfo as PluginPostStatusInfoLegacy } from '@wordpress/edit-post';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

export default () => {
	const { editPost } = useDispatch('core/editor');

	const { ep_allow_vector_embedding = false, ...meta } = useSelect(
		(select) => select('core/editor').getEditedPostAttribute('meta') || {},
	);

	const onChange = (ep_allow_vector_embedding) => {
		editPost({ meta: { ...meta, ep_allow_vector_embedding } });
	};

	const WrapperElement =
		typeof PluginPostStatusInfo !== 'undefined'
			? PluginPostStatusInfo
			: PluginPostStatusInfoLegacy;

	return (
		<WrapperElement>
			<CheckboxControl
				label={__('Allow Vector Embedding', 'elasticpress')}
				help={__('Includes this post for vector embeddings.', 'elasticpress')}
				checked={ep_allow_vector_embedding}
				onChange={onChange}
				__nextHasNoMarginBottom
			/>
		</WrapperElement>
	);
};
