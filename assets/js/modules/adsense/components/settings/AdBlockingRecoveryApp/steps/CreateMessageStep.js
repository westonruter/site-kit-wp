/**
 * Site Kit by Google, Copyright 2023 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment, useCallback, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Data from 'googlesitekit-data';
import { SpinnerButton, Button } from 'googlesitekit-components';
import Link from '../../../../../../components/Link';
import {
	AD_BLOCKING_RECOVERY_SETUP_STATUS_SETUP_CONFIRMED,
	MODULES_ADSENSE,
} from '../../../../datastore/constants';
import { CORE_SITE } from '../../../../../../googlesitekit/datastore/site/constants';
import { CORE_LOCATION } from '../../../../../../googlesitekit/datastore/location/constants';
const { useSelect, useDispatch } = Data;

export default function CreateMessageStep() {
	const [ ctaClicked, setCTAClicked ] = useState( false );

	const adsenseAccountID = useSelect( ( select ) =>
		select( MODULES_ADSENSE ).getAccountID()
	);
	const privacyMessagingURL = useSelect( ( select ) =>
		select( MODULES_ADSENSE ).getServiceURL( {
			path: `/${ adsenseAccountID }/privacymessaging/ad_blocking`,
		} )
	);
	const dashboardURL = useSelect( ( select ) =>
		select( CORE_SITE ).getAdminURL( 'googlesitekit-dashboard' )
	);
	const isSaving = useSelect(
		( select ) =>
			select( MODULES_ADSENSE ).isDoingSaveSettings() ||
			select( CORE_LOCATION ).isNavigatingTo( dashboardURL )
	);

	const { saveSettings, setAdBlockingRecoverySetupStatus } =
		useDispatch( MODULES_ADSENSE );
	const { navigateTo } = useDispatch( CORE_LOCATION );

	const onCTAClick = useCallback( async () => {
		if ( ! ctaClicked ) {
			setCTAClicked( true );
			return;
		}

		setAdBlockingRecoverySetupStatus(
			AD_BLOCKING_RECOVERY_SETUP_STATUS_SETUP_CONFIRMED
		);

		const { error } = await saveSettings();

		if ( ! error ) {
			navigateTo( dashboardURL );
		}
	}, [
		ctaClicked,
		dashboardURL,
		navigateTo,
		saveSettings,
		setAdBlockingRecoverySetupStatus,
	] );

	return (
		<Fragment>
			<p>
				{ __(
					'Create and publish an ad blocking recovery message in AdSense.',
					'google-site-kit'
				) }
			</p>
			<p>
				{ __(
					'Site visitors will be given the option to allow ads on your site. You can also present them with other options to fund your site (optional)',
					'google-site-kit'
				) }
			</p>
			<div className="googlesitekit-ad-blocking-recovery__create-message-footer">
				<div className="googlesitekit-ad-blocking-recovery__create-message-footer-actions">
					{ ctaClicked ? (
						<Fragment>
							<SpinnerButton
								onClick={ onCTAClick }
								isSaving={ isSaving }
								disabled={ isSaving }
							>
								{ __(
									'My message is ready',
									'google-site-kit'
								) }
							</SpinnerButton>
							<Link href={ privacyMessagingURL } target="_blank">
								{ __( 'Create message', 'google-site-kit' ) }
							</Link>
						</Fragment>
					) : (
						<Button
							href={ privacyMessagingURL }
							target="_blank"
							onClick={ onCTAClick }
						>
							{ __( 'Create message', 'google-site-kit' ) }
						</Button>
					) }
				</div>
				{ ctaClicked && (
					<p className="googlesitekit-ad-blocking-recovery__create-message-footer-note">
						{ __(
							'Ad blocking recovery only works if you’ve created and published your message in AdSense',
							'google-site-kit'
						) }
					</p>
				) }
			</div>
		</Fragment>
	);
}
