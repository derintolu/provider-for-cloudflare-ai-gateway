import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import CredentialsTab from './tabs/CredentialsTab';
import ModelCatalogTab from './tabs/ModelCatalogTab';
import GatewayConfigTab from './tabs/GatewayConfigTab';
import LogsTab from './tabs/LogsTab';
import PlaygroundTab from './tabs/PlaygroundTab';

const TABS = [
	{
		name: 'credentials',
		title: __( 'Credentials', 'cloudflare-ai-gateway' ),
	},
	{ name: 'models', title: __( 'Model Catalog', 'cloudflare-ai-gateway' ) },
	{ name: 'gateway', title: __( 'Gateway Config', 'cloudflare-ai-gateway' ) },
	{ name: 'logs', title: __( 'Logs', 'cloudflare-ai-gateway' ) },
	{ name: 'playground', title: __( 'Playground', 'cloudflare-ai-gateway' ) },
];

export default function App() {
	return (
		<div className="cfaig-app">
			<h1 className="cfaig-app__title">
				{ __( 'AI Gateway for Cloudflare', 'cloudflare-ai-gateway' ) }
			</h1>
			<TabPanel tabs={ TABS } className="cfaig-app__tabs">
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'credentials':
							return <CredentialsTab />;
						case 'models':
							return <ModelCatalogTab />;
						case 'gateway':
							return <GatewayConfigTab />;
						case 'logs':
							return <LogsTab />;
						case 'playground':
							return <PlaygroundTab />;
						default:
							return null;
					}
				} }
			</TabPanel>
		</div>
	);
}
