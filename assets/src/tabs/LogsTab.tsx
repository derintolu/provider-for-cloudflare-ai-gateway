import { useEffect, useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { getLogs, type LogEntry, type LogsSummary } from '../api';
import { getErrorMessage } from '../utils';

export default function LogsTab() {
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ summary, setSummary ] = useState< LogsSummary | null >( null );
	const [ model, setModel ] = useState( '' );
	const [ success, setSuccess ] = useState< '' | 'true' | 'false' >( '' );
	const [ cached, setCached ] = useState< '' | 'true' | 'false' >( '' );

	useEffect( () => {
		setLoading( true );
		setError( null );

		const timeout = setTimeout( () => {
			getLogs( { model, success, cached } )
				.then( ( result ) => {
					setLogs( result.logs );
					setSummary( result.summary );
				} )
				.catch( ( err ) =>
					setError(
						getErrorMessage(
							err,
							__(
								'Could not load gateway logs.',
								'cloudflare-ai-gateway'
							)
						)
					)
				)
				.finally( () => setLoading( false ) );
		}, 300 );

		return () => clearTimeout( timeout );
	}, [ model, success, cached ] );

	return (
		<Flex direction="column" gap={ 4 } expanded>
			<Card>
				<CardBody>
					<Flex gap={ 4 }>
						<FlexBlock>
							<TextControl
								label={ __(
									'Filter by model',
									'cloudflare-ai-gateway'
								) }
								value={ model }
								onChange={ setModel }
								placeholder={ __(
									'e.g. llama',
									'cloudflare-ai-gateway'
								) }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						</FlexBlock>
						<FlexItem style={ { minWidth: '180px' } }>
							<SelectControl
								label={ __(
									'Status',
									'cloudflare-ai-gateway'
								) }
								value={ success }
								onChange={ setSuccess }
								options={ [
									{
										label: __(
											'All',
											'cloudflare-ai-gateway'
										),
										value: '',
									},
									{
										label: __(
											'Success',
											'cloudflare-ai-gateway'
										),
										value: 'true',
									},
									{
										label: __(
											'Failed',
											'cloudflare-ai-gateway'
										),
										value: 'false',
									},
								] }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						</FlexItem>
						<FlexItem style={ { minWidth: '180px' } }>
							<SelectControl
								label={ __( 'Cache', 'cloudflare-ai-gateway' ) }
								value={ cached }
								onChange={ setCached }
								options={ [
									{
										label: __(
											'All',
											'cloudflare-ai-gateway'
										),
										value: '',
									},
									{
										label: __(
											'Cache hit',
											'cloudflare-ai-gateway'
										),
										value: 'true',
									},
									{
										label: __(
											'Cache miss',
											'cloudflare-ai-gateway'
										),
										value: 'false',
									},
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
				<>
					{ summary && (
						<Flex gap={ 4 }>
							<FlexBlock>
								<Card>
									<CardBody>
										<p className="cfaig-muted">
											{ __(
												'Requests',
												'cloudflare-ai-gateway'
											) }
										</p>
										<p className="cfaig-stat">
											{ summary.requestCount }
										</p>
									</CardBody>
								</Card>
							</FlexBlock>
							<FlexBlock>
								<Card>
									<CardBody>
										<p className="cfaig-muted">
											{ __(
												'Cache hit rate',
												'cloudflare-ai-gateway'
											) }
										</p>
										<p className="cfaig-stat">
											{ summary.cacheHitRate }%
										</p>
									</CardBody>
								</Card>
							</FlexBlock>
							<FlexBlock>
								<Card>
									<CardBody>
										<p className="cfaig-muted">
											{ __(
												'Total cost (this page)',
												'cloudflare-ai-gateway'
											) }
										</p>
										<p className="cfaig-stat">
											${ summary.totalCost }
										</p>
									</CardBody>
								</Card>
							</FlexBlock>
						</Flex>
					) }

					<Card>
						<CardBody>
							{ logs.length === 0 ? (
								<p className="cfaig-muted">
									{ __(
										'No requests logged yet.',
										'cloudflare-ai-gateway'
									) }
								</p>
							) : (
								<div className="cfaig-table-scroll">
									<table className="cfaig-table">
										<thead>
											<tr>
												<th>
													{ __(
														'Time',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Model',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Status',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Cache',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Tokens',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Cost',
														'cloudflare-ai-gateway'
													) }
												</th>
												<th>
													{ __(
														'Duration',
														'cloudflare-ai-gateway'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ logs.map( ( log ) => (
												<tr key={ log.id }>
													<td>
														{ log.created_at
															? new Date(
																	log.created_at
															  ).toLocaleString()
															: '—' }
													</td>
													<td>
														<code>
															{ log.model ?? '—' }
														</code>
													</td>
													<td>
														<span
															className={
																log.success
																	? 'cfaig-ok'
																	: 'cfaig-fail'
															}
														>
															{ log.success
																? __(
																		'Success',
																		'cloudflare-ai-gateway'
																  )
																: __(
																		'Failed',
																		'cloudflare-ai-gateway'
																  ) }
														</span>
													</td>
													<td>
														{ log.cached
															? __(
																	'Hit',
																	'cloudflare-ai-gateway'
															  )
															: __(
																	'Miss',
																	'cloudflare-ai-gateway'
															  ) }
													</td>
													<td>
														{ sprintf(
															'%1$s / %2$s',
															String(
																log.tokens_in ??
																	'—'
															),
															String(
																log.tokens_out ??
																	'—'
															)
														) }
													</td>
													<td>
														{ log.cost !== undefined
															? `$${ log.cost }`
															: '—' }
													</td>
													<td>
														{ log.duration !==
														undefined
															? `${ log.duration }ms`
															: '—' }
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							) }
						</CardBody>
					</Card>
				</>
			) }
		</Flex>
	);
}
