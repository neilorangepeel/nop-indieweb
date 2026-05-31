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
	// Bumping tabKey remounts TabPanel so a programmatic switch (e.g. from the
	// Overview cards) actually moves to the target tab — TabPanel only reads
	// initialTabName on mount.
	const [ tabKey, setTabKey ] = useState( 0 );
	const [ targetNetwork, setTargetNetwork ] = useState( null );

	const handleTabSelect = ( tabName ) => {
		setActiveTab( tabName );
		setTargetNetwork( null );
		window.history.replaceState( null, '', '#' + tabName );
	};

	const onTabSwitch = ( tabName, network = null ) => {
		setActiveTab( tabName );
		setTargetNetwork( network );
		setTabKey( ( k ) => k + 1 );
		window.history.replaceState( null, '', '#' + tabName );
	};

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
				key={ tabKey }
				tabs={ TABS }
				initialTabName={ activeTab }
				onSelect={ handleTabSelect }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'overview':  return <OverviewTab  { ...tabProps } onTabSwitch={ onTabSwitch } />;
						case 'networks':  return <NetworksTab  { ...tabProps } targetNetwork={ targetNetwork } />;
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
