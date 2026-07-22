import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	getSettings,
	listGateways,
	listTextModels,
	testConnection,
	testInference,
	updateSettings,
	type GatewayRecord,
	type Settings,
	type TestInferenceResult,
	type TextModel,
} from '../api';
import { getErrorMessage } from '../utils';

type NoticeState = {
	status: 'success' | 'error' | 'info' | 'warning';
	message: string;
} | null;

export default function CredentialsTab() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ settings, setSettings ] = useState< Settings | null >( null );
	const [ accountId, setAccountId ] = useState( '' );
	const [ apiKey, setApiKey ] = useState( '' );
	const [ gatewayId, setGatewayId ] = useState( '' );
	const [ preferredModel, setPreferredModel ] = useState( '' );
	const [ models, setModels ] = useState< TextModel[] >( [] );
	const [ modelsError, setModelsError ] = useState< string | null >( null );
	const [ gateways, setGateways ] = useState< GatewayRecord[] >( [] );
	const [ gatewaysError, setGatewaysError ] = useState< string | null >(
		null
	);
	const [ notice, setNotice ] = useState< NoticeState >( null );
	const [ inferenceTesting, setInferenceTesting ] = useState( false );
	const [ inferenceResult, setInferenceResult ] =
		useState< TestInferenceResult | null >( null );

	useEffect( () => {
		getSettings()
			.then( ( result ) => {
				setSettings( result );
				setAccountId( result.accountId );
				setGatewayId( result.gatewayId );
				setPreferredModel( result.preferredModel );
			} )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage(
						err,
						__(
							'Could not load settings.',
							'cloudflare-ai-gateway'
						)
					),
				} )
			)
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		if (
			! settings ||
			( ! settings.hasApiKey && ! settings.connectorManaged ) ||
			! settings.accountId
		) {
			return;
		}
		listTextModels()
			.then( ( result ) => {
				setModels( result.models );
				setModelsError( null );
			} )
			.catch( ( err ) =>
				setModelsError(
					getErrorMessage(
						err,
						__(
							'Could not load your available models.',
							'cloudflare-ai-gateway'
						)
					)
				)
			);
		listGateways()
			.then( ( result ) => {
				setGateways( result.gateways );
				setGatewaysError( null );
			} )
			.catch( ( err ) =>
				setGatewaysError(
					getErrorMessage(
						err,
						__(
							'Could not load your named gateways.',
							'cloudflare-ai-gateway'
						)
					)
				)
			);
	}, [ settings ] );

	const hasCredentials =
		!! settings &&
		( settings.hasApiKey || settings.connectorManaged ) &&
		!! accountId;

	const handleSave = () => {
		setSaving( true );
		setNotice( null );
		updateSettings( { accountId, apiKey, gatewayId, preferredModel } )
			.then( ( result ) => {
				setSettings( result );
				setApiKey( '' );
				setGatewayId( result.gatewayId );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'cloudflare-ai-gateway' ),
				} );
			} )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage(
						err,
						__(
							'Could not save settings.',
							'cloudflare-ai-gateway'
						)
					),
				} )
			)
			.finally( () => setSaving( false ) );
	};

	const handleTest = () => {
		setTesting( true );
		setNotice( null );
		testConnection()
			.then( ( result ) => {
				setNotice( {
					status: result.success ? 'success' : 'error',
					message: result.message,
				} );
				if ( result.success ) {
					listTextModels().then( ( r ) => setModels( r.models ) );
				}
			} )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage(
						err,
						__( 'Connection test failed.', 'cloudflare-ai-gateway' )
					),
				} )
			)
			.finally( () => setTesting( false ) );
	};

	const handleTestInference = () => {
		setInferenceTesting( true );
		setInferenceResult( null );
		testInference()
			.then( setInferenceResult )
			.catch( ( err ) =>
				setInferenceResult( {
					success: false,
					message: getErrorMessage(
						err,
						__(
							'Request failed unexpectedly.',
							'cloudflare-ai-gateway'
						)
					),
				} )
			)
			.finally( () => setInferenceTesting( false ) );
	};

	const renderModelsStatus = () => {
		if ( modelsError ) {
			return (
				<Notice status="warning" isDismissible={ false }>
					{ modelsError }
				</Notice>
			);
		}

		if ( models.length === 0 ) {
			return (
				<p className="cfaig-muted">
					{ __(
						'No live models loaded yet — only the built-in default is available. This list refreshes automatically once your account has models to show.',
						'cloudflare-ai-gateway'
					) }
				</p>
			);
		}

		return (
			<p className="cfaig-muted">
				{ sprintf(
					// translators: %d: number of models loaded from the live Cloudflare catalog.
					__(
						'%d model(s) your account can access via Cloudflare Workers AI text generation. The full multi-modal catalog is on the Model Catalog tab.',
						'cloudflare-ai-gateway'
					),
					models.length
				) }
			</p>
		);
	};

	if ( loading ) {
		return (
			<Flex justify="center" style={ { padding: '48px 0' } }>
				<Spinner />
			</Flex>
		);
	}

	return (
		<Flex direction="column" gap={ 4 } expanded>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }

			{ settings &&
				( settings.hasApiKey || settings.connectorManaged ) &&
				! accountId && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Your API token is connected, but Cloudflare also requires an Account ID — it has no field for this on the Connectors screen. Enter it in the "Cloudflare account" card below and click "Save changes" to finish.',
							'cloudflare-ai-gateway'
						) }
					</Notice>
				) }

			<Notice status="info" isDismissible={ false }>
				<p style={ { margin: 0 } }>
					{ createInterpolateElement(
						__(
							'<link>Create your Cloudflare API token</link> with both <strong>Workers AI — Edit</strong> and <strong>AI Gateway — Edit</strong> permissions. Text/image generation only needs Workers AI, but the Gateway Config and Logs tabs — and the gateway picker below — need AI Gateway too, or Cloudflare will reject those requests with a generic "Authentication error" even though the token itself is valid. Under "Account Resources," scope the token to this specific account rather than leaving it unselected.',
							'cloudflare-ai-gateway'
						),
						{
							link: (
								<ExternalLink href="https://dash.cloudflare.com/profile/api-tokens">
									{ null }
								</ExternalLink>
							),
							strong: <strong />,
						}
					) }
				</p>
			</Notice>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'Cloudflare account', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<Flex direction="column" gap={ 4 }>
						<TextControl
							label={ __(
								'Account ID',
								'cloudflare-ai-gateway'
							) }
							help={ __(
								'Find this on the Cloudflare dashboard sidebar.',
								'cloudflare-ai-gateway'
							) }
							value={ accountId }
							onChange={ setAccountId }
							placeholder="fc6034fbe3a9c364856c3d380c47dc3e"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>

						{ settings?.connectorManaged ? (
							<Notice status="info" isDismissible={ false }>
								{ createInterpolateElement(
									__(
										'Your API token is managed on the <link>Settings → Connectors</link> screen.',
										'cloudflare-ai-gateway'
									),
									{
										link: (
											<ExternalLink
												href={
													window.cfaigAdmin
														?.connectorsUrl ??
													'options-connectors.php'
												}
											>
												{ null }
											</ExternalLink>
										),
									}
								) }
							</Notice>
						) : (
							<TextControl
								label={ __(
									'API token',
									'cloudflare-ai-gateway'
								) }
								help={
									settings?.hasApiKey
										? __(
												'A token is already saved — leave blank to keep it.',
												'cloudflare-ai-gateway'
										  )
										: __(
												'Requires both the "Workers AI - Edit" and "AI Gateway - Edit" permissions — Workers AI alone can\'t create or manage a gateway.',
												'cloudflare-ai-gateway'
										  )
								}
								type="password"
								value={ apiKey }
								onChange={ setApiKey }
								autoComplete="off"
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
						) }

						{ settings && ! settings.connectorApproved && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'This plugin needs to be allowed on the Connectors screen before requests will work.',
									'cloudflare-ai-gateway'
								) }
							</Notice>
						) }

						<Flex justify="flex-start" gap={ 2 }>
							<FlexItem>
								<Button
									variant="primary"
									isBusy={ saving }
									disabled={ saving }
									onClick={ handleSave }
								>
									{ __(
										'Save changes',
										'cloudflare-ai-gateway'
									) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									isBusy={ testing }
									disabled={ testing || ! hasCredentials }
									onClick={ handleTest }
								>
									{ __(
										'Test connection',
										'cloudflare-ai-gateway'
									) }
								</Button>
							</FlexItem>
						</Flex>
					</Flex>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'AI Gateway', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					{ hasCredentials ? (
						<Flex direction="column" gap={ 4 }>
							<SelectControl
								label={ __(
									'Gateway used for every inference request',
									'cloudflare-ai-gateway'
								) }
								value={ gatewayId }
								onChange={ setGatewayId }
								options={ [
									{
										label: __(
											"— Default (Cloudflare's built-in gateway, no setup needed) —",
											'cloudflare-ai-gateway'
										),
										value: '',
									},
									...gateways.map( ( gateway ) => ( {
										label: gateway.id,
										value: gateway.id,
									} ) ),
								] }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							<p className="cfaig-muted">
								{ __(
									'Every request — free-tier Workers AI and paid usage alike — routes through this gateway, so caching, rate limiting and logging configured on the Gateway Config tab apply automatically.',
									'cloudflare-ai-gateway'
								) }
							</p>
							{ gatewaysError && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ sprintf(
										// translators: %s: the underlying error message.
										__(
											"%s Your named gateways (if any) won't show in the list above, and the Gateway Config/Logs tabs won't work, until that's fixed — but text/image generation is unaffected, since those only need Workers AI access.",
											'cloudflare-ai-gateway'
										),
										gatewaysError
									) }
								</Notice>
							) }

							<Flex justify="flex-start" gap={ 2 } align="center">
								<FlexItem>
									<Button
										variant="secondary"
										isBusy={ inferenceTesting }
										disabled={ inferenceTesting }
										onClick={ handleTestInference }
									>
										{ __(
											'Send test prompt',
											'cloudflare-ai-gateway'
										) }
									</Button>
								</FlexItem>
								{ inferenceResult && (
									<FlexItem>
										{ inferenceResult.success ? (
											<span className="cfaig-ok">
												{ sprintf(
													// translators: 1: model reply, 2: model ID, 3: latency in milliseconds
													__(
														'"%1$s" from %2$s in %3$dms',
														'cloudflare-ai-gateway'
													),
													inferenceResult.reply,
													inferenceResult.model,
													inferenceResult.latency
												) }
											</span>
										) : (
											<span className="cfaig-fail">
												{ inferenceResult.message }
											</span>
										) }
									</FlexItem>
								) }
							</Flex>
						</Flex>
					) : (
						<p className="cfaig-muted">
							{ __(
								'A gateway is created automatically the first time you save your Account ID and API token.',
								'cloudflare-ai-gateway'
							) }
						</p>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __(
							'Default text generation model',
							'cloudflare-ai-gateway'
						) }
					</h3>
				</CardHeader>
				<CardBody>
					{ hasCredentials ? (
						<>
							<SelectControl
								label={ __(
									'Model used by WordPress AI features',
									'cloudflare-ai-gateway'
								) }
								value={ preferredModel }
								onChange={ setPreferredModel }
								options={ [
									{
										label: __(
											'— Default (Meta Llama 4 Scout 17B) —',
											'cloudflare-ai-gateway'
										),
										value: '',
									},
									...models.map( ( model ) => ( {
										label: model.name,
										value: model.id,
									} ) ),
								] }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							{ renderModelsStatus() }
						</>
					) : (
						<p className="cfaig-muted">
							{ __(
								'Save your Account ID and API token first to choose a model.',
								'cloudflare-ai-gateway'
							) }
						</p>
					) }
				</CardBody>
			</Card>
		</Flex>
	);
}
