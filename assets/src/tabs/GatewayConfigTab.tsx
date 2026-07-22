import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Flex,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	getGatewayConfig,
	updateGatewayConfig,
	type GatewayConfig,
} from '../api';
import { getErrorMessage } from '../utils';

type NoticeState = {
	status: 'success' | 'error';
	message: string;
} | null;

const CLOUDFLARE_DASHBOARD_URL =
	'https://dash.cloudflare.com/?to=/:account/ai/ai-gateway';

export default function GatewayConfigTab() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState >( null );
	const [ gatewayId, setGatewayId ] = useState( '' );
	const [ gateway, setGateway ] = useState< GatewayConfig | null >( null );

	const [ cachingEnabled, setCachingEnabled ] = useState( false );
	const [ cacheTtl, setCacheTtl ] = useState( '300' );
	const [ cacheInvalidateOnUpdate, setCacheInvalidateOnUpdate ] =
		useState( false );

	const [ rateLimitingEnabled, setRateLimitingEnabled ] = useState( false );
	const [ rateLimit, setRateLimit ] = useState( '100' );
	const [ rateInterval, setRateInterval ] = useState( '60' );
	const [ rateTechnique, setRateTechnique ] = useState< 'fixed' | 'sliding' >(
		'fixed'
	);

	const [ retryMaxAttempts, setRetryMaxAttempts ] = useState( '1' );
	const [ retryDelay, setRetryDelay ] = useState( '0' );
	const [ retryBackoff, setRetryBackoff ] = useState<
		'constant' | 'linear' | 'exponential'
	>( 'constant' );

	const [ collectLogs, setCollectLogs ] = useState( true );
	const [ authentication, setAuthentication ] = useState( false );

	useEffect( () => {
		getGatewayConfig()
			.then( ( result ) => {
				setGatewayId( result.gatewayId );
				setGateway( result.gateway );

				const g = result.gateway;
				setCachingEnabled( !! g.cache_ttl && g.cache_ttl > 0 );
				setCacheTtl( String( g.cache_ttl ?? 300 ) );
				setCacheInvalidateOnUpdate( !! g.cache_invalidate_on_update );

				setRateLimitingEnabled( !! g.rate_limiting_limit );
				setRateLimit( String( g.rate_limiting_limit ?? 100 ) );
				setRateInterval( String( g.rate_limiting_interval ?? 60 ) );
				setRateTechnique( g.rate_limiting_technique ?? 'fixed' );

				setRetryMaxAttempts( String( g.retry_max_attempts ?? 1 ) );
				setRetryDelay( String( g.retry_delay ?? 0 ) );
				setRetryBackoff( g.retry_backoff ?? 'constant' );

				setCollectLogs( g.collect_logs !== false );
				setAuthentication( !! g.authentication );
			} )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage(
						err,
						__(
							'Could not load the gateway configuration.',
							'cloudflare-ai-gateway'
						)
					),
				} )
			)
			.finally( () => setLoading( false ) );
	}, [] );

	const handleSave = () => {
		setSaving( true );
		setNotice( null );

		updateGatewayConfig( {
			cache_ttl: cachingEnabled ? parseInt( cacheTtl, 10 ) || 0 : 0,
			cache_invalidate_on_update: cacheInvalidateOnUpdate,
			rate_limiting_limit: rateLimitingEnabled
				? parseInt( rateLimit, 10 ) || 0
				: undefined,
			rate_limiting_interval: rateLimitingEnabled
				? parseInt( rateInterval, 10 ) || 0
				: undefined,
			rate_limiting_technique: rateTechnique as 'fixed' | 'sliding',
			retry_max_attempts: parseInt( retryMaxAttempts, 10 ) || 1,
			retry_delay: parseInt( retryDelay, 10 ) || 0,
			retry_backoff: retryBackoff as
				| 'constant'
				| 'linear'
				| 'exponential',
			collect_logs: collectLogs,
			authentication,
		} )
			.then( ( result ) => {
				setGateway( result.gateway );
				setNotice( {
					status: 'success',
					message: __(
						'Gateway configuration saved.',
						'cloudflare-ai-gateway'
					),
				} );
			} )
			.catch( ( err ) =>
				setNotice( {
					status: 'error',
					message: getErrorMessage(
						err,
						__(
							'Could not save the gateway configuration.',
							'cloudflare-ai-gateway'
						)
					),
				} )
			)
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return (
			<Flex justify="center" style={ { padding: '48px 0' } }>
				<Spinner />
			</Flex>
		);
	}

	if ( ! gateway ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ notice?.message ??
					__(
						'No gateway is configured yet.',
						'cloudflare-ai-gateway'
					) }
			</Notice>
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

			<p className="cfaig-muted">
				{ __( 'Gateway:', 'cloudflare-ai-gateway' ) }{ ' ' }
				<code>{ gatewayId }</code>
			</p>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'Caching', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<Flex direction="column" gap={ 4 }>
						<ToggleControl
							label={ __(
								'Cache responses',
								'cloudflare-ai-gateway'
							) }
							checked={ cachingEnabled }
							onChange={ setCachingEnabled }
							__nextHasNoMarginBottom
						/>
						{ cachingEnabled && (
							<>
								<TextControl
									label={ __(
										'Cache TTL (seconds)',
										'cloudflare-ai-gateway'
									) }
									type="number"
									value={ cacheTtl }
									onChange={ setCacheTtl }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<ToggleControl
									label={ __(
										'Invalidate cache when content changes',
										'cloudflare-ai-gateway'
									) }
									checked={ cacheInvalidateOnUpdate }
									onChange={ setCacheInvalidateOnUpdate }
									__nextHasNoMarginBottom
								/>
							</>
						) }
					</Flex>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'Rate limiting', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<Flex direction="column" gap={ 4 }>
						<ToggleControl
							label={ __(
								'Limit requests',
								'cloudflare-ai-gateway'
							) }
							checked={ rateLimitingEnabled }
							onChange={ setRateLimitingEnabled }
							__nextHasNoMarginBottom
						/>
						{ rateLimitingEnabled && (
							<>
								<TextControl
									label={ __(
										'Max requests',
										'cloudflare-ai-gateway'
									) }
									type="number"
									value={ rateLimit }
									onChange={ setRateLimit }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<TextControl
									label={ __(
										'Per interval (seconds)',
										'cloudflare-ai-gateway'
									) }
									type="number"
									value={ rateInterval }
									onChange={ setRateInterval }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<SelectControl
									label={ __(
										'Technique',
										'cloudflare-ai-gateway'
									) }
									value={ rateTechnique }
									onChange={ setRateTechnique }
									options={ [
										{ label: 'Fixed', value: 'fixed' },
										{ label: 'Sliding', value: 'sliding' },
									] }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</>
						) }
					</Flex>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'Retries', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<Flex direction="column" gap={ 4 }>
						<TextControl
							label={ __(
								'Max attempts (1–5)',
								'cloudflare-ai-gateway'
							) }
							type="number"
							min={ 1 }
							max={ 5 }
							value={ retryMaxAttempts }
							onChange={ setRetryMaxAttempts }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __(
								'Delay (milliseconds)',
								'cloudflare-ai-gateway'
							) }
							type="number"
							value={ retryDelay }
							onChange={ setRetryDelay }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Backoff', 'cloudflare-ai-gateway' ) }
							value={ retryBackoff }
							onChange={ setRetryBackoff }
							options={ [
								{
									label: __(
										'Constant',
										'cloudflare-ai-gateway'
									),
									value: 'constant',
								},
								{
									label: __(
										'Linear',
										'cloudflare-ai-gateway'
									),
									value: 'linear',
								},
								{
									label: __(
										'Exponential',
										'cloudflare-ai-gateway'
									),
									value: 'exponential',
								},
							] }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</Flex>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'Logging & security', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<Flex direction="column" gap={ 4 }>
						<ToggleControl
							label={ __(
								'Collect request logs',
								'cloudflare-ai-gateway'
							) }
							checked={ collectLogs }
							onChange={ setCollectLogs }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __(
								'Require authentication for this gateway',
								'cloudflare-ai-gateway'
							) }
							checked={ authentication }
							onChange={ setAuthentication }
							__nextHasNoMarginBottom
						/>
					</Flex>
				</CardBody>
			</Card>

			<Flex justify="flex-start">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ handleSave }
				>
					{ __(
						'Save gateway configuration',
						'cloudflare-ai-gateway'
					) }
				</Button>
			</Flex>

			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __(
							'Managed on the Cloudflare dashboard',
							'cloudflare-ai-gateway'
						) }
					</h3>
				</CardHeader>
				<CardBody>
					<p className="cfaig-muted">
						{ __(
							'These features either have no confirmed API for reading/writing them safely, or are dashboard-only by design — configure them directly on Cloudflare rather than here.',
							'cloudflare-ai-gateway'
						) }
					</p>
					<ul>
						<li>{ __( 'Guardrails', 'cloudflare-ai-gateway' ) }</li>
						<li>
							{ __(
								'DLP (data loss prevention)',
								'cloudflare-ai-gateway'
							) }
						</li>
						<li>
							{ __( 'Spend limits', 'cloudflare-ai-gateway' ) }
						</li>
						<li>
							{ __(
								'Dynamic Routing (fallback chains)',
								'cloudflare-ai-gateway'
							) }
						</li>
						<li>
							{ __(
								'BYOK provider keys',
								'cloudflare-ai-gateway'
							) }
						</li>
						<li>
							{ __(
								'Custom Providers',
								'cloudflare-ai-gateway'
							) }
						</li>
					</ul>
					<ExternalLink href={ CLOUDFLARE_DASHBOARD_URL }>
						{ __(
							'Open this gateway on the Cloudflare dashboard',
							'cloudflare-ai-gateway'
						) }
					</ExternalLink>
				</CardBody>
			</Card>
		</Flex>
	);
}
