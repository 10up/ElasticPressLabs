import { useState, useEffect } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

export default ({ value, onChange, disabled, taxonomy, label, placeholder = '' }) => {
	const [terms, setTerms] = useState([]);
	const fetchedTerms = useSelect((select) =>
		select(coreStore).getEntityRecords('taxonomy', taxonomy, { per_page: -1 }),
	);

	useEffect(() => {
		if (fetchedTerms) {
			setTerms(fetchedTerms.map((term) => term.name));
		}
	}, [fetchedTerms]);

	return (
		<FormTokenField
			disabled={disabled}
			label={label}
			value={value}
			suggestions={terms}
			onChange={onChange}
			placeholder={placeholder || __('Type to search for terms', 'elasticpress')}
			__experimentalShowHowTo={false}
			__nextHasNoMarginBottom
			__nextHasNoMarginTop
			__next40pxDefaultSize
		/>
	);
};
