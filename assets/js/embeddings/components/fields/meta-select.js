/**
 * External dependencies
 */
import { FormTokenField } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useVectorEmebeddingSettings } from '../../provider';

export default (props) => {
	const { value, postType: postTypeObj, updateKey, label } = props;
	const { setEmbeddingForPostType } = useVectorEmebeddingSettings();
	const { key: postType } = postTypeObj;
	return (
		<FormTokenField
			value={value}
			label={label}
			onChange={(tokens) => setEmbeddingForPostType(postType, null, updateKey, tokens)}
			placeholder={__('Add meta field...', 'elasticpress')}
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};
