import { useState } from '@wordpress/element';
import { TabPanel, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import useSettings from './hooks/useSettings';
import SaveBar from './components/SaveBar';
import OverviewTab from './tabs/OverviewTab';
import NetworksTab from './tabs/NetworksTab';
import ContentTab from './tabs/ContentTab';
import ReactionsTab from './tabs/ReactionsTab';
import AdvancedTab from './tabs/AdvancedTab';

const TABS = [
	{ name: 'overview',  title: __( 'Overview',  'nop-indieweb' ) },
	{ name: 'networks',  title: __( 'Networks',  'nop-indieweb' ) },
	{ name: 'content',   title: __( 'Content',   'nop-indieweb' ) },
	{ name: 'reactions', title: __( 'Reactions', 'nop-indieweb' ) },
	{ name: 'advanced',  title: __( 'Advanced',  'nop-indieweb' ) },
];

const VALID_TAB_NAMES = TABS.map( ( t ) => t.name );

function initialTab() {
	const hash = window.location.hash.replace( '#', '' );
	return VALID_TAB_NAMES.includes( hash ) ? hash : 'overview';
}

export default function App() {
	const { settings, setSettings, save, isSaving, notice, dismissNotice } = useSettings();
	const [ activeTab, setActiveTab ] = useState( initialTab );

	const handleTabSelect = ( tabName ) => {
		setActiveTab( tabName );
		history.replaceState( null, '', '#' + tabName );
	};

	const onTabSwitch = handleTabSelect;

	if ( ! settings ) {
		return (
			<div className="nop-settings-loading">
				<Spinner />
			</div>
		);
	}

	const tabProps = { settings, setSettings };

	return (
		<div className="nop-settings-app">
			<TabPanel
				tabs={ TABS }
				initialTabName={ activeTab }
				selectedTabName={ activeTab }
				onSelect={ handleTabSelect }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'overview':  return <OverviewTab  { ...tabProps } onTabSwitch={ onTabSwitch } />;
						case 'networks':  return <NetworksTab  { ...tabProps } />;
						case 'content':   return <ContentTab   { ...tabProps } />;
						case 'reactions': return <ReactionsTab { ...tabProps } />;
						case 'advanced':  return <AdvancedTab  { ...tabProps } />;
						default:          return null;
					}
				} }
			</TabPanel>
			<SaveBar
				onSave={ save }
				isSaving={ isSaving }
				notice={ notice }
				onDismiss={ dismissNotice }
			/>
		</div>
	);
}
