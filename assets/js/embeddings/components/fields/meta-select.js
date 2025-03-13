/**
 * External dependencies
 */
import { FormTokenField } from '@wordpress/components';

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
			placeholder="Enter meta keys"
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};
