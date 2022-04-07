/**
 * WP Dashboard ActivateAnalyticsCTA Component Stories.
 *
 * Site Kit by Google, Copyright 2022 Google LLC
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
 * Internal dependencies
 */
import WithRegistrySetup from '../../../../tests/js/WithRegistrySetup';
import WPDashboardActivateAnalyticsCTA from './WPDashboardActivateAnalyticsCTA';
import { widgetDecorators } from './common.stories';
import {
	provideModules,
	provideModuleRegistrations,
	provideUserAuthentication,
} from '../../../../tests/js/utils';

const Template = ( { setupRegistry } ) => (
	<WithRegistrySetup func={ setupRegistry }>
		<div id="google_dashboard_widget" style={ { maxWidth: '600px' } }>
			<div className="googlesitekit-widget">
				<div className="googlesitekit-widget__body">
					<WPDashboardActivateAnalyticsCTA />
				</div>
			</div>
		</div>
	</WithRegistrySetup>
);

export const Ready = Template.bind( {} );
Ready.storyName = 'Ready';
Ready.args = {
	setupRegistry: ( registry ) => {
		provideModules( registry, [
			{
				active: false,
				connected: false,
				slug: 'analytics',
			},
		] );
	},
};

export const CompleteActivation = Template.bind( {} );
CompleteActivation.storyName = 'Complete Activation';
CompleteActivation.args = {
	setupRegistry: ( registry ) => {
		provideUserAuthentication( registry );
		provideModules( registry, [
			{
				active: true,
				connected: false,
				slug: 'analytics',
			},
		] );
		provideModuleRegistrations( registry );
	},
};

export default {
	title: 'Views/WPDashboardApp/WPDashboardActivateAnalyticsCTA',
	decorators: widgetDecorators,
};
