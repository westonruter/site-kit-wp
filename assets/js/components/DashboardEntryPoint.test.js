/**
 * DashboardEntryPoint tests.
 *
 * Site Kit by Google, Copyright 2021 Google LLC
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
import { render } from '../../../tests/js/test-utils';
import { mockCreateComponent } from '../../../tests/js/mock-component-utils';
import DashboardEntryPoint from './DashboardEntryPoint';

jest.mock( './setup/ModuleSetup', () => mockCreateComponent( 'ModuleSetup' ) );
jest.mock( './DashboardMainApp', () =>
	mockCreateComponent( 'DashboardMainApp' )
);

describe( 'DashboardEntryPoint', () => {
	it( 'should render the unified dashboard', () => {
		const { container } = render( <DashboardEntryPoint /> );
		expect( container ).toMatchSnapshot();
	} );

	it( 'should render the module setup component when the setupModuleSlug prop is passed', () => {
		const { container } = render(
			<DashboardEntryPoint setupModuleSlug="analytics" />
		);
		expect( container ).toMatchSnapshot();
	} );
} );
