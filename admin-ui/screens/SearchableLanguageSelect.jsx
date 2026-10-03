import { Autocomplete, Text } from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'

export default function SearchableLanguageSelect( {
	label,
	options,
	value,
	onChange,
	placeholder = 'Search languages',
	requiredIndicator = false,
	disabled = false,
	labelHidden = false,
	emptyMessage = 'No languages match this search.',
} ) {
	const selectedOption = options.find( ( option ) => option.value === value )
	const selectedLabel = selectedOption?.label || ''
	const [ search, setSearch ] = useState( selectedLabel )

	useEffect( () => {
		setSearch( selectedLabel )
	}, [ selectedLabel ] )

	const term = search === selectedLabel ? '' : search.trim().toLowerCase()
	const filteredOptions = options.filter(
		( option ) =>
			! term ||
			`${ option.label } ${ option.value }`.toLowerCase().includes( term )
	)
	const hasSelection = options.some( ( option ) => option.value === value )

	function selectOption( selected ) {
		const nextValue = selected[ 0 ]
		const option = options.find( ( item ) => item.value === nextValue )
		if ( ! option || option.disabled ) {
			return
		}
		onChange( nextValue )
		setSearch( option.label )
	}

	return (
		<Autocomplete
			options={ filteredOptions }
			selected={ hasSelection ? [ value ] : [] }
			onSelect={ selectOption }
			emptyState={
				<Text as="p" tone="subdued">
					{ emptyMessage }
				</Text>
			}
			textField={
				<Autocomplete.TextField
					label={ label }
					labelHidden={ labelHidden }
					requiredIndicator={ requiredIndicator }
					value={ search }
					onChange={ setSearch }
					placeholder={ placeholder }
					autoComplete="off"
					disabled={ disabled }
				/>
			}
		/>
	)
}
