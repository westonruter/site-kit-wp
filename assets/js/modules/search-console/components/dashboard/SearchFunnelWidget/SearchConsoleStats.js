/**
 * SearchConsoleStats component for SearchFunnelWidget.
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
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getSiteStatsDataForGoogleChart } from '../../../util';
import { Grid, Row, Cell } from '../../../../../material-components';
import { partitionReport } from '../../../../../util/partition-report';
import GoogleChart from '../../../../../components/GoogleChart';

const SearchConsoleStats = ( {
	data,
	metrics,
	selectedStats,
	dateRangeLength,
} ) => {
	const { compareRange, currentRange } = partitionReport( data, {
		dateRangeLength,
	} );
	const googleChartData = getSiteStatsDataForGoogleChart(
		currentRange,
		compareRange,
		metrics[ selectedStats ].label,
		metrics[ selectedStats ].metric,
		dateRangeLength
	);

	const dates = googleChartData.slice( 1 ).map( ( [ date ] ) => date );

	const options = {
		...SearchConsoleStats.chartOptions,
		hAxis: {
			format: 'M/d/yy',
			gridlines: {
				color: '#fff',
			},
			textStyle: {
				color: '#616161',
				fontSize: 12,
			},
			ticks: dates,
		},
		series: {
			0: {
				color: metrics[ selectedStats ].color,
				targetAxisIndex: 0,
			},
			1: {
				color: metrics[ selectedStats ].color,
				targetAxisIndex: 0,
				lineDashStyle: [ 3, 3 ],
				lineWidth: 1,
			},
		},
	};

	const isZeroChart = ! ( data[ 0 ]?.data?.totals[ selectedStats ] > 0 );

	if ( isZeroChart ) {
		options.vAxis.viewWindow.max = 100;
	} else {
		options.vAxis.viewWindow.max = undefined;
	}

	return (
		<Grid className="googlesitekit-search-console-site-stats">
			<Row>
				<Cell size={ 12 }>
					<GoogleChart
						chartType="LineChart"
						data={ googleChartData }
						loadingHeight="270px"
						loadingWidth="100%"
						options={ options }
					/>
				</Cell>
			</Row>
		</Grid>
	);
};

SearchConsoleStats.propTypes = {
	data: PropTypes.arrayOf( PropTypes.object ).isRequired,
	dateRangeLength: PropTypes.number.isRequired,
	metrics: PropTypes.arrayOf( PropTypes.object ).isRequired,
	selectedStats: PropTypes.number.isRequired,
};

SearchConsoleStats.chartOptions = {
	chart: {
		title: __( 'Search Traffic Summary', 'google-site-kit' ),
	},
	curveType: 'function',
	height: 270,
	width: '100%',
	chartArea: {
		height: '80%',
		width: '100%',
		left: 60,
	},
	legend: {
		position: 'top',
		textStyle: {
			color: '#616161',
			fontSize: 12,
		},
	},
	vAxis: {
		direction: 1,
		gridlines: {
			color: '#eee',
		},
		minorGridlines: {
			color: '#eee',
		},
		textStyle: {
			color: '#616161',
			fontSize: 12,
		},
		titleTextStyle: {
			color: '#616161',
			fontSize: 12,
			italic: false,
		},
		viewWindow: {
			min: 0,
		},
	},

	tooltip: {
		isHtml: true, // eslint-disable-line sitekit/acronym-case
		trigger: 'both',
	},
	focusTarget: 'category',
	crosshair: {
		color: 'gray',
		opacity: 0.1,
		orientation: 'vertical',
		trigger: 'both',
	},
};

export default SearchConsoleStats;
