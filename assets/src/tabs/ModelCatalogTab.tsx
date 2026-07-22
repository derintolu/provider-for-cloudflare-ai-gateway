import { useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { getCatalog, type CatalogModel } from '../api';

export default function ModelCatalogTab() {
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ models, setModels ] = useState< CatalogModel[] >( [] );
	const [ tasks, setTasks ] = useState< string[] >( [] );
	const [ task, setTask ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	useEffect( () => {
		setLoading( true );
		setError( null );

		const timeout = setTimeout( () => {
			getCatalog( { task, search } )
				.then( ( result ) => {
					setModels( result.models );
					setTasks( result.tasks );
				} )
				.catch( ( err ) =>
					setError(
						err && typeof err === 'object' && 'message' in err
							? String( ( err as { message: unknown } ).message )
							: __(
									'Could not load the model catalog.',
									'cloudflare-ai-gateway'
							  )
					)
				)
				.finally( () => setLoading( false ) );
		}, 300 );

		return () => clearTimeout( timeout );
	}, [ task, search ] );

	return (
		<Flex direction="column" gap={ 4 } expanded>
			<Card>
				<CardBody>
					<Flex gap={ 4 }>
						<FlexBlock>
							<SearchControl
								label={ __(
									'Search models',
									'cloudflare-ai-gateway'
								) }
								value={ search }
								onChange={ setSearch }
								placeholder={ __(
									'e.g. llama, flux, whisper…',
									'cloudflare-ai-gateway'
								) }
								__nextHasNoMarginBottom
							/>
						</FlexBlock>
						<FlexItem style={ { minWidth: '220px' } }>
							<SelectControl
								label={ __( 'Task', 'cloudflare-ai-gateway' ) }
								value={ task }
								onChange={ setTask }
								options={ [
									{
										label: __(
											'All tasks',
											'cloudflare-ai-gateway'
										),
										value: '',
									},
									...tasks.map( ( t ) => ( {
										label: t,
										value: t,
									} ) ),
								] }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						</FlexItem>
					</Flex>
				</CardBody>
			</Card>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ loading ? (
				<Flex justify="center" style={ { padding: '48px 0' } }>
					<Spinner />
				</Flex>
			) : (
				<Card>
					<CardBody>
						{ models.length === 0 ? (
							<p className="cfaig-muted">
								{ error
									? null
									: __(
											'No models found. Save your Account ID and API token on the Credentials tab first.',
											'cloudflare-ai-gateway'
									  ) }
							</p>
						) : (
							<>
								<p className="cfaig-muted">
									{ sprintf(
										// translators: %d is the number of models shown
										__(
											'%d model(s)',
											'cloudflare-ai-gateway'
										),
										models.length
									) }
								</p>
								<div className="cfaig-table-scroll">
									<table className="cfaig-table">
										<thead>
											<tr>
												<th>
													{ __(
														'Model ID',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Task',
														'cloudflare-ai-gateway'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ models.map( ( model ) => (
												<tr key={ model.id }>
													<td>
														<code>
															{ model.id }
														</code>
													</td>
													<td>
														<span className="cfaig-badge">
															{ model.task }
														</span>
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							</>
						) }
					</CardBody>
				</Card>
			) }
		</Flex>
	);
}
