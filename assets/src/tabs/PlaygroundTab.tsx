import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Notice,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { runPlayground, type PlaygroundResult } from '../api';
import { getErrorMessage } from '../utils';

export default function PlaygroundTab() {
	const [ model, setModel ] = useState(
		'@cf/meta/llama-4-scout-17b-16e-instruct'
	);
	const [ prompt, setPrompt ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ result, setResult ] = useState< PlaygroundResult | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const handleRun = () => {
		setRunning( true );
		setError( null );
		setResult( null );

		runPlayground( model, prompt )
			.then( setResult )
			.catch( ( err ) =>
				setError(
					getErrorMessage(
						err,
						__(
							'Request failed unexpectedly.',
							'cloudflare-ai-gateway'
						)
					)
				)
			)
			.finally( () => setRunning( false ) );
	};

	return (
		<Flex direction="column" gap={ 4 } expanded>
			<Card>
				<CardHeader>
					<h3 className="cfaig-card-heading">
						{ __( 'Run any model', 'cloudflare-ai-gateway' ) }
					</h3>
				</CardHeader>
				<CardBody>
					<Flex direction="column" gap={ 4 }>
						<TextControl
							label={ __( 'Model', 'cloudflare-ai-gateway' ) }
							help={ sprintf(
								// translators: 1: example Workers AI model ID, 2: example third-party provider/model string
								__(
									"A Workers AI ID (like %1$s) or, if you've configured BYOK on the Cloudflare dashboard, a third-party provider/model string (like %2$s).",
									'cloudflare-ai-gateway'
								),
								'@cf/meta/llama-4-scout-17b-16e-instruct',
								'openai/gpt-4.1'
							) }
							value={ model }
							onChange={ setModel }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<TextareaControl
							label={ __( 'Prompt', 'cloudflare-ai-gateway' ) }
							value={ prompt }
							onChange={ setPrompt }
							rows={ 4 }
							__nextHasNoMarginBottom
						/>
						<Flex justify="flex-start">
							<Button
								variant="primary"
								isBusy={ running }
								disabled={ running || ! model || ! prompt }
								onClick={ handleRun }
							>
								{ __( 'Run', 'cloudflare-ai-gateway' ) }
							</Button>
						</Flex>
					</Flex>
				</CardBody>
			</Card>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ result && ! result.success && (
				<Notice status="error" isDismissible={ false }>
					{ result.message }
				</Notice>
			) }

			{ result && result.success && (
				<Card>
					<CardHeader>
						<h3 className="cfaig-card-heading">
							{ __( 'Response', 'cloudflare-ai-gateway' ) }
						</h3>
					</CardHeader>
					<CardBody>
						<Flex direction="column" gap={ 4 }>
							<p>{ result.reply }</p>
							<p className="cfaig-muted">
								{ __( 'Latency:', 'cloudflare-ai-gateway' ) }{ ' ' }
								{ result.latency }ms
							</p>
							<details>
								<summary>
									{ __(
										'Raw response',
										'cloudflare-ai-gateway'
									) }
								</summary>
								<pre className="cfaig-raw">
									{ JSON.stringify( result.raw, null, 2 ) }
								</pre>
							</details>
						</Flex>
					</CardBody>
				</Card>
			) }
		</Flex>
	);
}
