import apiFetch from '@wordpress/api-fetch';

const NAMESPACE = '/cloudflare-ai-gateway/v1';

export interface Settings {
	accountId: string;
	hasApiKey: boolean;
	gatewayId: string;
	preferredModel: string;
	connectorManaged: boolean;
	connectorApproved: boolean;
}

export interface TextModel {
	id: string;
	name: string;
}

export interface ModelsResponse {
	models: TextModel[];
	isLive: boolean;
	approved: boolean;
}

export interface TestConnectionResult {
	success: boolean;
	reason?: string;
	message: string;
}

export interface GatewayRecord {
	id: string;
	created_on?: string;
	modified_on?: string;
}

export interface GatewaysResponse {
	gateways: GatewayRecord[];
}

export type TestInferenceResult =
	| { success: true; reply: string; model: string; latency: number }
	| { success: false; message: string };

export interface CatalogModel {
	id: string;
	name: string;
	task: string;
	raw: Record< string, unknown >;
}

export interface CatalogResponse {
	models: CatalogModel[];
	tasks: string[];
}

/**
 * Raw gateway record from Cloudflare — only the fields this plugin's Gateway
 * Config tab actually reads/writes are typed; everything else Cloudflare
 * returns passes through untyped since the full schema isn't documented.
 */
export interface GatewayConfig {
	id: string;
	cache_ttl?: number | null;
	cache_invalidate_on_update?: boolean;
	rate_limiting_interval?: number | null;
	rate_limiting_limit?: number | null;
	rate_limiting_technique?: 'fixed' | 'sliding';
	retry_max_attempts?: number;
	retry_delay?: number;
	retry_backoff?: 'constant' | 'linear' | 'exponential';
	collect_logs?: boolean;
	authentication?: boolean;
	guardrails?: unknown;
	dlp?: unknown;
	spend_limits?: unknown;
	[ key: string ]: unknown;
}

export interface GatewayConfigResponse {
	gatewayId: string;
	gateway: GatewayConfig;
}

export type GatewayConfigUpdate = Partial<
	Pick<
		GatewayConfig,
		| 'cache_ttl'
		| 'cache_invalidate_on_update'
		| 'rate_limiting_interval'
		| 'rate_limiting_limit'
		| 'rate_limiting_technique'
		| 'retry_max_attempts'
		| 'retry_delay'
		| 'retry_backoff'
		| 'collect_logs'
		| 'authentication'
	>
>;

export const getSettings = (): Promise< Settings > =>
	apiFetch( { path: `${ NAMESPACE }/settings` } );

export const updateSettings = (
	payload: Partial< {
		accountId: string;
		apiKey: string;
		gatewayId: string;
		preferredModel: string;
	} >
): Promise< Settings > =>
	apiFetch( {
		path: `${ NAMESPACE }/settings`,
		method: 'POST',
		data: {
			account_id: payload.accountId,
			api_key: payload.apiKey,
			gateway_id: payload.gatewayId,
			preferred_model: payload.preferredModel,
		},
	} );

export const testConnection = (): Promise< TestConnectionResult > =>
	apiFetch( { path: `${ NAMESPACE }/test-connection`, method: 'POST' } );

export const listTextModels = (): Promise< ModelsResponse > =>
	apiFetch( { path: `${ NAMESPACE }/models` } );

export const listGateways = (): Promise< GatewaysResponse > =>
	apiFetch( { path: `${ NAMESPACE }/gateways` } );

export const testInference = (): Promise< TestInferenceResult > =>
	apiFetch( { path: `${ NAMESPACE }/test-inference`, method: 'POST' } );

export const getCatalog = (
	params: { task?: string; search?: string } = {}
): Promise< CatalogResponse > => {
	const query = new URLSearchParams();
	if ( params.task ) {
		query.set( 'task', params.task );
	}
	if ( params.search ) {
		query.set( 'search', params.search );
	}
	const qs = query.toString();
	return apiFetch( {
		path: `${ NAMESPACE }/catalog${ qs ? `?${ qs }` : '' }`,
	} );
};

export const getGatewayConfig = (): Promise< GatewayConfigResponse > =>
	apiFetch( { path: `${ NAMESPACE }/gateway-config` } );

export const updateGatewayConfig = (
	payload: GatewayConfigUpdate
): Promise< GatewayConfigResponse > =>
	apiFetch( {
		path: `${ NAMESPACE }/gateway-config`,
		method: 'POST',
		data: payload,
	} );

export interface LogEntry {
	id: string;
	created_at?: string;
	provider?: string;
	model?: string;
	success?: boolean;
	cached?: boolean;
	duration?: number;
	tokens_in?: number;
	tokens_out?: number;
	cost?: number;
	status_code?: number;
	[ key: string ]: unknown;
}

export interface LogsSummary {
	requestCount: number;
	cacheHitRate: number;
	totalCost: number;
}

export interface LogsResponse {
	logs: LogEntry[];
	summary: LogsSummary;
	resultInfo: unknown;
}

export const getLogs = (
	params: {
		model?: string;
		provider?: string;
		success?: string;
		cached?: string;
		page?: number;
	} = {}
): Promise< LogsResponse > => {
	const query = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== '' ) {
			query.set( key, String( value ) );
		}
	} );
	const qs = query.toString();
	return apiFetch( { path: `${ NAMESPACE }/logs${ qs ? `?${ qs }` : '' }` } );
};

export type PlaygroundResult =
	| {
			success: true;
			reply: string;
			latency: number;
			raw: Record< string, unknown >;
	  }
	| { success: false; message: string };

export const runPlayground = (
	model: string,
	prompt: string
): Promise< PlaygroundResult > =>
	apiFetch( {
		path: `${ NAMESPACE }/playground`,
		method: 'POST',
		data: { model, prompt },
	} );
