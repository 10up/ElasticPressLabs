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

	const { ep_embedding_exclude = false, ...meta } = useSelect(
		(select) => select('core/editor').getEditedPostAttribute('meta') || {},
	);

	const onChange = (ep_embedding_exclude) => {
		editPost({ meta: { ...meta, ep_embedding_exclude } });
	};

	const WrapperElement =
		typeof PluginPostStatusInfo !== 'undefined'
			? PluginPostStatusInfo
			: PluginPostStatusInfoLegacy;

	return (
		<WrapperElement>
			<CheckboxControl
				label={__('Exclude from vector embeddings', 'elasticpress-labs')}
				help={__(
					"Check this if you don't want this post to be vectorized.",
					'elasticpress-labs',
				)}
				checked={ep_embedding_exclude}
				onChange={onChange}
				__nextHasNoMarginBottom
			/>
		</WrapperElement>
	);
};
