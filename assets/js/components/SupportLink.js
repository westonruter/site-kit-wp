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
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * Internal dependencies
 */
import Data from 'googlesitekit-data';
import { CORE_SITE } from '../googlesitekit/datastore/site/constants';
import Link from './Link';
const { useSelect } = Data;

export default function SupportLink( props ) {
	const { href, ...otherProps } = props;

	const supportURL = useSelect( ( select ) =>
		select( CORE_SITE ).getGoogleSupportURL( href )
	);

	return <Link href={ supportURL } { ...otherProps } />;
}

SupportLink.propTypes = {
	href: PropTypes.shape( {
		path: PropTypes.string,
		query: PropTypes.object,
		hash: PropTypes.hash,
	} ).isRequired,
};
